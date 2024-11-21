<?php

namespace ThinkNeverland\Porter\Services;

use Aws\S3\S3Client;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\{Crypt, DB, Schema, Storage};
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

class ExportService
{
    private $modelCache = [];

    private static $fakerInstance = null;

    protected function getFaker()
    {
        return self::$fakerInstance ??= Faker::create();
    }

    protected function getOptimalBufferSize()
    {
        $memoryLimit = ini_get('memory_limit');
        $value       = (int)$memoryLimit;
        $unit        = strtolower(substr($memoryLimit, -1));

        switch ($unit) {
            case 'g': $value *= 1024;
                // no break
            case 'm': $value *= 1024;
                // no break
            case 'k': $value *= 1024;
        }

        return min(10 * 1024 * 1024, $value / 10);
    }

    public function exportDatabase($dropIfExists)
    {
        $filename          = 'export_' . Str::random(10) . '.sql';
        $encryptedFilename = Crypt::encryptString($filename);
        $tables            = DB::select('SHOW TABLES');
        $altS3Enabled      = config('porter.export_alt.enabled', false);

        return ($this->isRemoteDisk() || $altS3Enabled)
            ? $this->handleRemoteExport($tables, $encryptedFilename, $dropIfExists, $altS3Enabled)
            : $this->handleLocalExport($tables, $encryptedFilename, $dropIfExists);
    }

    protected function handleRemoteExport($tables, $encryptedFilename, $dropIfExists, $altS3Enabled)
    {
        $client = $this->getS3Client($altS3Enabled);
        $bucket = $altS3Enabled ? config('porter.export_alt.bucket') : config('filesystems.disks.s3.bucket');

        $multipart = $client->createMultipartUpload([
            'Bucket' => $bucket,
            'Key'    => $encryptedFilename,
        ]);

        $tempStream = fopen('php://temp', 'r+');
        $this->writeInitialSQL($tempStream, $dropIfExists);

        $uploadId   = $multipart['UploadId'];
        $partNumber = 1;
        $parts      = [];
        $bufferSize = $this->getOptimalBufferSize();

        foreach ($tables as $table) {
            $tableName  = array_values((array)$table)[0];
            $modelClass = $this->getModelForTable($tableName);

            if (!$this->shouldProcessTable($modelClass)) {
                continue;
            }

            fwrite($tempStream, $this->exportTableSchema($tableName, $dropIfExists));

            $this->processTableData($tableName, $modelClass, $tempStream, function () use ($client, $bucket, $encryptedFilename, $tempStream, $uploadId, &$partNumber, &$parts, $bufferSize) {
                if (ftell($tempStream) >= $bufferSize) {
                    $this->flushToS3($client, $bucket, $encryptedFilename, $tempStream, $uploadId, $partNumber++, $parts);
                }
            });
        }

        fwrite($tempStream, "SET FOREIGN_KEY_CHECKS=1;\n");

        if (ftell($tempStream) > 0) {
            $this->flushToS3($client, $bucket, $encryptedFilename, $tempStream, $uploadId, $partNumber++, $parts);
        }

        $client->completeMultipartUpload([
            'Bucket'          => $bucket,
            'Key'             => $encryptedFilename,
            'UploadId'        => $uploadId,
            'MultipartUpload' => ['Parts' => $parts],
        ]);

        fclose($tempStream);

        return $this->generatePresignedUrl($client, $bucket, $encryptedFilename, $altS3Enabled);
    }

    protected function handleLocalExport($tables, $encryptedFilename, $dropIfExists)
    {
        $filePath    = storage_path("app/public/{$encryptedFilename}");
        $localStream = fopen($filePath, 'w+');

        $this->writeInitialSQL($localStream, $dropIfExists);

        foreach ($tables as $table) {
            $tableName  = array_values((array)$table)[0];
            $modelClass = $this->getModelForTable($tableName);

            if (!$this->shouldProcessTable($modelClass)) {
                continue;
            }

            fwrite($localStream, $this->exportTableSchema($tableName, $dropIfExists));
            $this->processTableData($tableName, $modelClass, $localStream);
        }

        fwrite($localStream, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($localStream);

        return asset("storage/{$encryptedFilename}");
    }

    protected function processTableData($tableName, $modelClass, $stream, $callback = null)
    {
        $query = DB::table($tableName);

        // Get indexed columns using raw query
        $indexes = DB::select("SHOW INDEX FROM `{$tableName}`");

        // Try to find a suitable ordering column
        $orderByColumn = null;

        // First check for id column
        if (Schema::hasColumn($tableName, 'id')) {
            $orderByColumn = 'id';
        } else {
            // Look for primary key first
            foreach ($indexes as $index) {
                if ($index->Key_name === 'PRIMARY') {
                    $orderByColumn = $index->Column_name;

                    break;
                }
            }

            // If no primary key, look for first unique index
            if (!$orderByColumn) {
                foreach ($indexes as $index) {
                    if ($index->Non_unique == 0) {
                        $orderByColumn = $index->Column_name;

                        break;
                    }
                }
            }

            // Last resort - use first indexed column
            if (!$orderByColumn) {
                if (!empty($indexes)) {
                    $orderByColumn = $indexes[0]->Column_name;
                }
            }
        }

        if ($orderByColumn) {
            $query->orderBy($orderByColumn);
        }

        $query->chunk(1000, function ($records) use ($tableName, $modelClass, $stream, $callback) {
            foreach ($records as $row) {
                if ($modelClass) {
                    $row = $this->processRowWithModel($row, $modelClass);

                    if ($row === null) {
                        continue;
                    }
                }

                fwrite($stream, $this->generateInsertStatement($tableName, (array)$row));

                if ($callback) {
                    $callback();
                }
            }
        });
    }

    protected function processRowWithModel($row, $modelClass)
    {
        $modelInstance = new $modelClass();

        if (method_exists($modelInstance, 'porterShouldIgnoreModel') && $modelInstance->porterShouldIgnoreModel()) {
            return null;
        }

        if (method_exists($modelInstance, 'porterShouldKeepRow') && $modelInstance->porterShouldKeepRow((array)$row)) {
            return $row;
        }

        if (method_exists($modelInstance, 'porterRandomizeRow')) {
            return $modelInstance->porterRandomizeRow((array)$row);
        }

        return $row;
    }

    protected function getModelForTable($tableName)
    {
        return $this->modelCache[$tableName] ??= $this->findModelForTable($tableName);
    }

    protected function findModelForTable($tableName)
    {
        $namespace = app()->getNamespace();
        $finder    = new Finder();

        foreach ($finder->files()->in(app_path('Models'))->name('*.php') as $file) {
            $class = $namespace . 'Models\\' . Str::replaceLast('.php', '', str_replace('/', '\\', $file->getRelativePathname()));

            if (class_exists($class)) {
                $model = new $class();

                if ($model->getTable() === $tableName) {
                    return $class;
                }
            }
        }

        return null;
    }

    protected function generateInsertStatement($tableName, array $row)
    {
        $columns = implode('`, `', array_keys($row));
        $values  = implode(", ", array_map(function ($value) {
            if (is_null($value)) {
                return 'NULL';
            }

            // Properly escape special characters and quotes
            return "'" . str_replace(
                ["\\", "'", "\r", "\n"],
                ["\\\\", "\\'", "\\r", "\\n"],
                $value
            ) . "'";
        }, array_values($row)));

        return "INSERT INTO `{$tableName}` (`{$columns}`) VALUES ({$values});\n";
    }

    protected function getS3Client($altS3Enabled)
    {
        $config = [
            'version'     => 'latest',
            'region'      => $altS3Enabled ? config('porter.export_alt.region') : config('filesystems.disks.s3.region'),
            'credentials' => [
                'key'    => $altS3Enabled ? config('porter.export_alt.access_key') : config('filesystems.disks.s3.key'),
                'secret' => $altS3Enabled ? config('porter.export_alt.secret_key') : config('filesystems.disks.s3.secret'),
            ],
            'use_path_style_endpoint' => $altS3Enabled ? config('porter.export_alt.use_path_style_endpoint') : config('filesystems.disks.s3.use_path_style_endpoint', false),
        ];

        if ($endpoint = $altS3Enabled ? config('porter.export_alt.endpoint') : config('filesystems.disks.s3.endpoint', null)) {
            $config['endpoint'] = $endpoint;
        }

        return new S3Client($config);
    }

    protected function writeInitialSQL($stream, $dropIfExists)
    {
        fwrite($stream, "SET FOREIGN_KEY_CHECKS=0;\n");

        if ($dropIfExists) {
            fwrite($stream, "-- Adding DROP IF EXISTS for each table\n");
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

        return $schema . DB::select("SHOW CREATE TABLE {$tableName}")[0]->{'Create Table'} . ";\n\n";
    }

    protected function generatePresignedUrl($client, $bucket, $key, $altS3Enabled)
    {
        $expiration = $altS3Enabled ? config('porter.export.expiration', 3600) : config('filesystems.disks.s3.expiration', 3600);
        $cmd        = $client->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $key]);
        $request    = $client->createPresignedRequest($cmd, '+' . $expiration . ' seconds');

        return (string)$request->getUri();
    }

    protected function shouldProcessTable($modelClass)
    {
        if (!$modelClass) {
            return true;
        }
        $model = new $modelClass();

        return !(method_exists($model, 'porterShouldIgnoreModel') && $model->porterShouldIgnoreModel());
    }
}
