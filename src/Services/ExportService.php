<?php

namespace ThinkNeverland\Porter\Services;

use Aws\S3\S3Client;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\{Crypt, DB, Storage};
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

class ExportService
{
    /**
     * Export the database and handle S3 (Amazon or S3-compatible) or local storage.
     *
     * @param string $filename
     * @param bool $dropIfExists
     * @param bool $noExpiration
     * @return string|null
     */
    public function exportDatabase($filename, $dropIfExists, $noExpiration)
    {
        $faker             = Faker::create();
        $disk              = Storage::disk(config('filesystems.default'));
        $encryptedFilename = Crypt::encryptString($filename);
        $tables            = DB::select('SHOW TABLES');
        $altS3Enabled      = env('EXPORT_AWS_ENABLED', false);
        $useMultipart      = env('EXPORT_USE_MULTIPART_UPLOAD', true); // Check whether to use multipart upload

        if ($this->isRemoteDisk() || $altS3Enabled) {
            // Configure S3 client based on whether alternate S3 is enabled
            $clientConfig = [
                'version'     => 'latest',
                'region'      => $altS3Enabled ? env('EXPORT_AWS_REGION') : env('AWS_DEFAULT_REGION'),
                'credentials' => [
                    'key'    => $altS3Enabled ? env('EXPORT_AWS_ACCESS_KEY_ID') : env('AWS_ACCESS_KEY_ID'),
                    'secret' => $altS3Enabled ? env('EXPORT_AWS_SECRET_ACCESS_KEY') : env('AWS_SECRET_ACCESS_KEY'),
                ],
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            ];

            // Set endpoint for S3-compatible services if provided in the env
            $endpoint = $altS3Enabled ? env('EXPORT_AWS_ENDPOINT') : env('AWS_ENDPOINT', null);

            if ($endpoint) {
                $clientConfig['endpoint'] = $endpoint;
            }

            // Create the S3 client (Amazon or S3-compatible service)
            $client = new S3Client($clientConfig);
            $bucket = $altS3Enabled ? env('EXPORT_AWS_BUCKET') : env('AWS_BUCKET');
            $key    = $encryptedFilename;

            if ($useMultipart) {
                // Use multipart upload
                $multipart = $client->createMultipartUpload([
                    'Bucket' => $bucket,
                    'Key'    => $key,
                ]);

                $uploadId   = $multipart['UploadId'];
                $partNumber = 1;
                $parts      = [];
                $bufferSize = 5 * 1024 * 1024; // 5 MB buffer for multipart upload
                $tempStream = fopen('php://temp', 'r+');

                // Write to temp stream with DROP IF EXISTS (if needed)
                if ($dropIfExists) {
                    fwrite($tempStream, "-- Add DROP IF EXISTS for each table.\n");
                }

                // Iterate through all tables
                foreach ($tables as $table) {
                    $tableName  = array_values((array)$table)[0];
                    $modelClass = $this->getModelForTable($tableName);

                    if ($modelClass && $this->shouldIgnoreModel($modelClass)) {
                        continue;
                    }

                    // Write schema and data for each table
                    fwrite($tempStream, $this->exportTableSchema($tableName, $dropIfExists));
                    $dataGenerator = $this->getTableDataGenerator($tableName, $modelClass, $faker);

                    foreach ($dataGenerator as $row) {
                        fwrite($tempStream, $row);

                        // If buffer exceeds the set size, flush to S3
                        if (ftell($tempStream) >= $bufferSize) {
                            $this->flushToS3($client, $bucket, $key, $tempStream, $uploadId, $partNumber++, $parts);
                        }
                    }
                }

                // Flush any remaining data
                if (ftell($tempStream) > 0) {
                    $this->flushToS3($client, $bucket, $key, $tempStream, $uploadId, $partNumber++, $parts);
                }

                // Complete the multipart upload
                $client->completeMultipartUpload([
                    'Bucket'          => $bucket,
                    'Key'             => $key,
                    'UploadId'        => $uploadId,
                    'MultipartUpload' => ['Parts' => $parts],
                ]);

                fclose($tempStream);
            } else {
                // Use simple upload (non-multipart)
                $tempStream = fopen('php://temp', 'r+');

                // Write to temp stream with DROP IF EXISTS (if needed)
                if ($dropIfExists) {
                    fwrite($tempStream, "-- Add DROP IF EXISTS for each table.\n");
                }

                // Iterate through all tables and write data
                foreach ($tables as $table) {
                    $tableName  = array_values((array)$table)[0];
                    $modelClass = $this->getModelForTable($tableName);

                    if ($modelClass && $this->shouldIgnoreModel($modelClass)) {
                        continue;
                    }

                    fwrite($tempStream, $this->exportTableSchema($tableName, $dropIfExists));
                    $dataGenerator = $this->getTableDataGenerator($tableName, $modelClass, $faker);

                    foreach ($dataGenerator as $row) {
                        fwrite($tempStream, $row);
                    }
                }

                // Rewind and upload the entire file to S3 (single-part upload)
                rewind($tempStream);
                $client->putObject([
                    'Bucket' => $bucket,
                    'Key'    => $key,
                    'Body'   => stream_get_contents($tempStream),
                ]);

                fclose($tempStream);
            }

            // Generate the download URL (presigned or public)
            $expiration = $altS3Enabled ? env('EXPORT_AWS_EXPIRATION', 3600) : env('AWS_EXPIRATION', 3600);

            // Generate a presigned URL for both standard and alternate S3
            $cmd = $client->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key'    => $key,
            ]);

            $request = $client->createPresignedRequest($cmd, '+' . $expiration . ' seconds');
            $url     = (string) $request->getUri();  // Presigned URL with expiration

            return $url;
        } else {
            // Handle local storage export
            $filePath    = storage_path("app/public/{$encryptedFilename}");
            $localStream = fopen($filePath, 'w+');

            if ($dropIfExists) {
                fwrite($localStream, "-- Add DROP IF EXISTS for each table.\n");
            }

            // Iterate through all tables and write to local file
            foreach ($tables as $table) {
                $tableName  = array_values((array)$table)[0];
                $modelClass = $this->getModelForTable($tableName);

                if ($modelClass && $this->shouldIgnoreModel($modelClass)) {
                    continue;
                }

                fwrite($localStream, $this->exportTableSchema($tableName, $dropIfExists));
                $dataGenerator = $this->getTableDataGenerator($tableName, $modelClass, $faker);

                foreach ($dataGenerator as $row) {
                    fwrite($localStream, $row);
                }
            }

            fclose($localStream);

            return asset("storage/{$encryptedFilename}");
        }
    }

    /**
     * Flush data to S3 in multipart upload.
     *
     * @param S3Client $client
     * @param string $bucket
     * @param string $key
     * @param resource $tempStream
     * @param string $uploadId
     * @param int $partNumber
     * @param array $parts
     */
    protected function flushToS3($client, $bucket, $key, $tempStream, $uploadId, $partNumber, &$parts)
    {
        rewind($tempStream);

        $result = $client->uploadPart([
            'Bucket'     => $bucket,
            'Key'        => $key,
            'UploadId'   => $uploadId,
            'PartNumber' => $partNumber,
            'Body'       => stream_get_contents($tempStream),
        ]);

        $parts[] = [
            'PartNumber' => $partNumber,
            'ETag'       => $result['ETag'],
        ];

        ftruncate($tempStream, 0);
        rewind($tempStream);
    }

    protected function isRemoteDisk()
    {
        return config('filesystems.default') === 's3';
    }

    protected function exportTableSchema($tableName, $dropIfExists)
    {
        $schema = "-- Exporting schema for table: {$tableName}\n";

        if ($dropIfExists) {
            $schema .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
        }

        $createTableQuery = DB::select("SHOW CREATE TABLE {$tableName}")[0]->{'Create Table'};
        $schema .= "{$createTableQuery};\n\n";

        return $schema;
    }

    protected function getTableDataGenerator($tableName, $modelClass, $faker)
    {
        $data = DB::table($tableName)->cursor();

        foreach ($data as $row) {
            if ($modelClass) {
                if (isset($modelClass::$keepForPorter) && in_array($row->id, $modelClass::$keepForPorter)) {
                    yield $this->generateInsertStatement($tableName, (array) $row);

                    continue;
                }

                foreach ($row as $key => $value) {
                    if (in_array($key, $modelClass::$omittedFromPorter ?? [])) {
                        $row->{$key} = $faker->word;
                    }
                }
            }

            yield $this->generateInsertStatement($tableName, (array) $row);
        }
    }

    protected function generateInsertStatement($tableName, array $row)
    {
        $columns = implode('`, `', array_keys($row));
        $values  = implode("', '", array_map('addslashes', array_values($row)));

        return "INSERT INTO `{$tableName}` (`{$columns}`) VALUES ('{$values}');\n";
    }

    protected function getModelForTable($tableName)
    {
        $models = $this->getAllModels();

        foreach ($models as $modelClass) {
            $model = new $modelClass();

            if ($model->getTable() === $tableName) {
                return $modelClass;
            }
        }

        return null;
    }

    protected function getAllModels()
    {
        $models    = [];
        $namespace = app()->getNamespace();
        $modelPath = app_path('Models');

        $finder = new Finder();

        foreach ($finder->files()->in($modelPath)->name('*.php') as $file) {
            $relativePath = str_replace('/', '\\', $file->getRelativePathname());
            $class        = $namespace . 'Models\\' . Str::replaceLast('.php', '', $relativePath);

            if (class_exists($class)) {
                $models[] = $class;
            }
        }

        return $models;
    }

    protected function shouldIgnoreModel($modelClass)
    {
        return isset($modelClass::$ignoreFromPorter) && $modelClass::$ignoreFromPorter === true;
    }
}
