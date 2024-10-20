<?php

namespace ThinkNeverland\Porter\Services;

use Aws\S3\S3Client;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\{Crypt, DB, Storage};
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

class ExportService
{
    public function exportDatabase($filename, $dropIfExists, $noExpiration)
    {
        $faker             = Faker::create();
        $disk              = Storage::disk(config('filesystems.default'));
        $encryptedFilename = Crypt::encryptString($filename);
        $tables            = DB::select('SHOW TABLES');
        $altS3Enabled      = config('export.aws_enabled', false);
        $useMultipart      = config('export.use_multipart_upload', true);

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

            if ($useMultipart) {
                $multipart = $client->createMultipartUpload([
                    'Bucket' => $bucket,
                    'Key'    => $key,
                ]);

                $uploadId   = $multipart['UploadId'];
                $partNumber = 1;
                $parts      = [];
                $bufferSize = 5 * 1024 * 1024;
                $tempStream = fopen('php://temp', 'r+');

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
                    $dataGenerator = $this->getTableDataGenerator($tableName, $modelClass, $faker);

                    foreach ($dataGenerator as $row) {
                        fwrite($tempStream, $row);

                        if (ftell($tempStream) >= $bufferSize) {
                            $this->flushToS3($client, $bucket, $key, $tempStream, $uploadId, $partNumber++, $parts);
                        }
                    }
                }

                if (ftell($tempStream) > 0) {
                    $this->flushToS3($client, $bucket, $key, $tempStream, $uploadId, $partNumber++, $parts);
                }

                $client->completeMultipartUpload([
                    'Bucket'          => $bucket,
                    'Key'             => $key,
                    'UploadId'        => $uploadId,
                    'MultipartUpload' => ['Parts' => $parts],
                ]);

                fclose($tempStream);
            } else {
                $tempStream = fopen('php://temp', 'r+');

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
                    $dataGenerator = $this->getTableDataGenerator($tableName, $modelClass, $faker);

                    foreach ($dataGenerator as $row) {
                        fwrite($tempStream, $row);
                    }
                }

                rewind($tempStream);
                $client->putObject([
                    'Bucket' => $bucket,
                    'Key'    => $key,
                    'Body'   => stream_get_contents($tempStream),
                ]);

                fclose($tempStream);
            }

            $expiration = $altS3Enabled ? config('export.aws_expiration', 3600) : config('filesystems.disks.s3.expiration', 3600);

            $cmd = $client->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key'    => $key,
            ]);

            $request = $client->createPresignedRequest($cmd, '+' . $expiration . ' seconds');
            $url     = (string) $request->getUri();

            return $url;
        } else {
            $filePath    = storage_path("app/public/{$encryptedFilename}");
            $localStream = fopen($filePath, 'w+');

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
                $dataGenerator = $this->getTableDataGenerator($tableName, $modelClass, $faker);

                foreach ($dataGenerator as $row) {
                    fwrite($localStream, $row);
                }
            }

            fclose($localStream);

            return asset("storage/{$encryptedFilename}");
        }
    }

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
