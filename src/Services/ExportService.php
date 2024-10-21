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
     * Export the database to a file, optionally uploading to S3.
     *
     * @param string $filename The name of the file to export to.
     * @param bool $dropIfExists Whether to drop tables if they exist.
     * @param bool $noExpiration Whether the export link should have no expiration.
     * @return string The URL of the exported file.
     */
    public function exportDatabase($filename, $dropIfExists, $noExpiration)
    {
        $faker             = Faker::create();
        $disk              = Storage::disk(config('filesystems.default'));
        $encryptedFilename = Crypt::encryptString($filename);
        $tables            = DB::select('SHOW TABLES');
        $altS3Enabled      = config('export.aws_enabled', false);
        $useMultipart      = config('export.use_multipart_upload', true);

        // Check if the storage is remote (S3) or alternative S3 is enabled
        if ($this->isRemoteDisk() || $altS3Enabled) {
            $clientConfig = [
                'version'     => 'latest',
                'region'      => $altS3Enabled ? config('export.aws_region') : config('filesystems.disks.s3.region'),
                'credentials' => [
                    'key'    => $altS3Enabled ? config('export.aws_access_key_id') : config('filesystems.disks.s3.key'),
                    'secret' => $altS3Enabled ? config('export.aws_secret_access_key') : config('filesystems.disks.s3.secret'),
                ],
                'use_path_style_endpoint' => config('filesystems.disks.s3.use_path_style_endpoint', false),
            ];

            $endpoint = $altS3Enabled ? config('export.aws_endpoint') : config('filesystems.disks.s3.endpoint', null);

            if ($endpoint) {
                $clientConfig['endpoint'] = $endpoint;
            }

            $client = new S3Client($clientConfig);
            $bucket = $altS3Enabled ? config('export.aws_bucket') : config('filesystems.disks.s3.bucket');
            $key    = $encryptedFilename;

            // Use multipart upload if enabled
            if ($useMultipart) {
                $multipart = $client->createMultipartUpload([
                    'Bucket' => $bucket,
                    'Key'    => $key,
                ]);

                $uploadId   = $multipart['UploadId'];
                $partNumber = 1;
                $parts      = [];
                $bufferSize = 5 * 1024 * 1024; // 5 MB buffer size
                $tempStream = fopen('php://temp', 'r+');

                fwrite($tempStream, "SET FOREIGN_KEY_CHECKS=0;\n");

                if ($dropIfExists) {
                    fwrite($tempStream, "-- Add DROP IF EXISTS for each table.\n");
                }

                // Iterate over each table and export schema and data
                foreach ($tables as $table) {
                    $tableName  = array_values((array)$table)[0];
                    $modelClass = $this->getModelForTable($tableName);

                    if ($modelClass && $this->shouldIgnoreModel($modelClass)) {
                        continue;
                    }

                    fwrite($tempStream, $this->exportTableSchema($tableName, $dropIfExists));
                    $dataGenerator = $this->getTableDataGenerator($tableName, $modelClass);

                    foreach ($dataGenerator as $row) {
                        fwrite($tempStream, $row);

                        // Flush to S3 if buffer size is exceeded
                        if (ftell($tempStream) >= $bufferSize) {
                            $this->flushToS3($client, $bucket, $key, $tempStream, $uploadId, $partNumber++, $parts);
                        }
                    }
                }

                // Final flush to S3
                if (ftell($tempStream) > 0) {
                    $this->flushToS3($client, $bucket, $key, $tempStream, $uploadId, $partNumber++, $parts);
                }

                fwrite($tempStream, "SET FOREIGN_KEY_CHECKS=1;\n");

                $client->completeMultipartUpload([
                    'Bucket'          => $bucket,
                    'Key'             => $key,
                    'UploadId'        => $uploadId,
                    'MultipartUpload' => ['Parts' => $parts],
                ]);

                fclose($tempStream);
            } else {
                // Single part upload
                $tempStream = fopen('php://temp', 'r+');

                fwrite($tempStream, "SET FOREIGN_KEY_CHECKS=0;\n");

                if ($dropIfExists) {
                    fwrite($tempStream, "-- Add DROP IF EXISTS for each table.\n");
                }

                foreach ($tables as $table) {
                    $tableName  = array_values((array)$table)[0];
                    $modelClass = $this->getModelForTable($tableName);

                    if ($modelClass && $this->shouldIgnoreModel($modelClass)) {
                        continue;
                    }

                    fwrite($tempStream, $this->exportTableSchema($tableName, $dropIfExists));
                    $dataGenerator = $this->getTableDataGenerator($tableName, $modelClass);

                    foreach ($dataGenerator as $row) {
                        fwrite($tempStream, $row);
                    }
                }

                fwrite($tempStream, "SET FOREIGN_KEY_CHECKS=1;\n");

                rewind($tempStream);
                $client->putObject([
                    'Bucket' => $bucket,
                    'Key'    => $key,
                    'Body'   => stream_get_contents($tempStream),
                ]);

                fclose($tempStream);
            }

            // Generate a presigned URL for the uploaded file
            $expiration = $altS3Enabled ? config('export.aws_expiration', 3600) : config('filesystems.disks.s3.expiration', 3600);
            $cmd        = $client->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key'    => $key,
            ]);

            $request = $client->createPresignedRequest($cmd, '+' . $expiration . ' seconds');
            $url     = (string) $request->getUri();

            return $url;
        } else {
            // Local storage
            $filePath    = storage_path("app/public/{$encryptedFilename}");
            $localStream = fopen($filePath, 'w+');

            fwrite($localStream, "SET FOREIGN_KEY_CHECKS=0;\n");

            if ($dropIfExists) {
                fwrite($localStream, "-- Add DROP IF EXISTS for each table.\n");
            }

            foreach ($tables as $table) {
                $tableName  = array_values((array)$table)[0];
                $modelClass = $this->getModelForTable($tableName);

                if ($modelClass && $this->shouldIgnoreModel($modelClass)) {
                    continue;
                }

                fwrite($localStream, $this->exportTableSchema($tableName, $dropIfExists));
                $dataGenerator = $this->getTableDataGenerator($tableName, $modelClass);

                foreach ($dataGenerator as $row) {
                    fwrite($localStream, $row);
                }
            }

            fwrite($localStream, "SET FOREIGN_KEY_CHECKS=1;\n");

            fclose($localStream);

            return asset("storage/{$encryptedFilename}");
        }
    }

    /**
     * Flush the contents of the temporary stream to S3.
     *
     * @param S3Client $client The S3 client.
     * @param string $bucket The S3 bucket name.
     * @param string $key The S3 object key.
     * @param resource $tempStream The temporary stream.
     * @param string $uploadId The multipart upload ID.
     * @param int $partNumber The part number.
     * @param array &$parts The array of uploaded parts.
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

    /**
     * Check if the current storage disk is remote (S3).
     *
     * @return bool True if the disk is remote, false otherwise.
     */
    protected function isRemoteDisk()
    {
        return config('filesystems.default') === 's3';
    }

    /**
     * Export the schema for a given table.
     *
     * @param string $tableName The name of the table.
     * @param bool $dropIfExists Whether to include DROP TABLE IF EXISTS.
     * @return string The SQL schema for the table.
     */
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

    /**
     * Get a generator for the data of a given table.
     *
     * @param string $tableName The name of the table.
     * @param string|null $modelClass The model class associated with the table.
     * @return \Generator A generator yielding SQL insert statements.
     */
    protected function getTableDataGenerator($tableName, $modelClass)
    {
        $data = DB::table($tableName)->cursor();

        foreach ($data as $row) {
            if ($modelClass) {
                $modelInstance = new $modelClass();

                if (method_exists($modelInstance, 'porterShouldIgnoreModel') && $modelInstance->porterShouldIgnoreModel()) {
                    continue;
                }

                if (method_exists($modelInstance, 'porterShouldKeepRow') && $modelInstance->porterShouldKeepRow((array) $row)) {
                    yield $this->generateInsertStatement($tableName, (array) $row);

                    continue;
                }

                if (method_exists($modelInstance, 'porterRandomizeRow')) {
                    $row = $modelInstance->porterRandomizeRow((array) $row);
                }
            }

            yield $this->generateInsertStatement($tableName, (array) $row);
        }
    }

    /**
     * Generate an SQL insert statement for a given row of data.
     *
     * @param string $tableName The name of the table.
     * @param array $row The row of data.
     * @return string The SQL insert statement.
     */
    protected function generateInsertStatement($tableName, array $row)
    {
        $columns = implode('`, `', array_keys($row));

        // Get nullable columns for the table
        $nullableColumns = $this->getNullableColumns($tableName);

        // Map values, setting empty values to NULL if the column is nullable
        $values = implode(", ", array_map(function($column, $value) use ($nullableColumns) {
            if (is_null($value) || ($value === '' && in_array($column, $nullableColumns))) {
                return 'NULL';
            }
            return "'" . addslashes($value) . "'";
        }, array_keys($row), array_values($row)));

        return "INSERT INTO `{$tableName}` (`{$columns}`) VALUES ({$values});\n";
    }

    /**
     * Get nullable columns for a given table.
     *
     * @param string $tableName The name of the table.
     * @return array An array of nullable column names.
     */
    protected function getNullableColumns($tableName)
    {
        $columns = DB::select("SHOW COLUMNS FROM {$tableName}");
        $nullableColumns = [];

        foreach ($columns as $column) {
            if ($column->Null === 'YES') {
                $nullableColumns[] = $column->Field;
            }
        }

        return $nullableColumns;
    }

    /**
     * Get the model class associated with a given table.
     *
     * @param string $tableName The name of the table.
     * @return string|null The model class name, or null if not found.
     */
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

    /**
     * Get all model classes in the application.
     *
     * @return array An array of model class names.
     */
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

    /**
     * Determine if a model should be ignored during export.
     *
     * @param string $modelClass The model class name.
     * @return bool True if the model should be ignored, false otherwise.
     */
    protected function shouldIgnoreModel($modelClass)
    {
        $modelInstance = new $modelClass();

        return method_exists($modelInstance, 'porterShouldIgnoreModel') && $modelInstance->porterShouldIgnoreModel();
    }
}
