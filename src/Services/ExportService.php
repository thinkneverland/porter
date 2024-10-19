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
     * Export the entire database to a file, apply model-based configurations, and optionally upload it to S3 or local storage.
     *
     * @param string $filename The name of the file to export.
     * @param bool $dropIfExists Whether to add DROP IF EXISTS statements to the SQL file.
     * @param bool $noExpiration Whether the file download link should have no expiration.
     * @return string|null The download link or null if no link was created.
     */

    public function exportDatabase($filename, $dropIfExists, $noExpiration)
    {
        $faker             = Faker::create();
        $disk              = Storage::disk(config('filesystems.default'));
        $encryptedFilename = Crypt::encryptString($filename);

        // Fetch the database tables
        $tables = DB::select('SHOW TABLES');

        // If the disk is S3, handle multipart upload directly with AWS SDK
        if ($this->isRemoteDisk()) {
            // Initialize S3 client directly from AWS SDK
            $client = new S3Client([
                'version'     => 'latest',
                'region'      => env('AWS_DEFAULT_REGION'),
                'credentials' => [
                    'key'    => env('AWS_ACCESS_KEY_ID'),
                    'secret' => env('AWS_SECRET_ACCESS_KEY'),
                ],
            ]);

            $bucket = env('AWS_BUCKET');
            $key    = $encryptedFilename;

            // Start multipart upload
            $multipart = $client->createMultipartUpload([
                'Bucket' => $bucket,
                'Key'    => $key,
            ]);

            $uploadId   = $multipart['UploadId'];
            $partNumber = 1;
            $parts      = [];
            $bufferSize = 1024 * 1024; // 1MB buffer size
            $tempStream = fopen('php://temp', 'r+'); // Temporary memory stream

            if ($dropIfExists) {
                fwrite($tempStream, "-- Add DROP IF EXISTS for each table.\n");
            }

            // Process the database tables
            foreach ($tables as $table) {
                $tableName  = array_values((array)$table)[0];
                $modelClass = $this->getModelForTable($tableName);

                if ($modelClass && $this->shouldIgnoreModel($modelClass)) {
                    continue;
                }

                // Write table schema
                fwrite($tempStream, $this->exportTableSchema($tableName, $dropIfExists));

                // Write table data
                if ($modelClass) {
                    $this->exportTableDataWithModel($modelClass, $tableName, $tempStream, $faker);
                } else {
                    $this->exportTableDataWithoutModel($tableName, $tempStream);
                }

                // Check if buffer exceeds 1MB and flush
                if (ftell($tempStream) >= $bufferSize) {
                    $this->flushToS3($client, $bucket, $key, $tempStream, $uploadId, $partNumber++, $parts);
                }
            }

            // Final flush if there's remaining data
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

            // Generate URL for S3
            if (!$noExpiration) {
                // Generate a temporary signed URL (e.g., valid for 30 minutes)
                $url = $disk->temporaryUrl($encryptedFilename, now()->addMinutes(30));
            } else {
                // Generate a public URL if the file is public
                $url = $disk->url($encryptedFilename);
            }

            // Return the correct URL for S3
            return $url;
        } else {
            // For public/local storage
            $filePath    = storage_path("app/public/{$encryptedFilename}");
            $localStream = fopen($filePath, 'w+');

            if ($dropIfExists) {
                fwrite($localStream, "-- Add DROP IF EXISTS for each table.\n");
            }

            // Process tables (local handling)
            foreach ($tables as $table) {
                $tableName  = array_values((array)$table)[0];
                $modelClass = $this->getModelForTable($tableName);

                if ($modelClass && $this->shouldIgnoreModel($modelClass)) {
                    continue;
                }

                // Write schema and data to local file
                fwrite($localStream, $this->exportTableSchema($tableName, $dropIfExists));

                if ($modelClass) {
                    $this->exportTableDataWithModel($modelClass, $tableName, $localStream, $faker);
                } else {
                    $this->exportTableDataWithoutModel($tableName, $localStream);
                }
            }

            fclose($localStream);

            // Return public URL for local disk
            return asset("storage/{$encryptedFilename}");
        }
    }

    /**
     * Flush the contents of the temp stream to S3 as a part of multipart upload.
     */
    protected function flushToS3($client, $bucket, $key, $tempStream, $uploadId, $partNumber, &$parts)
    {
        rewind($tempStream); // Rewind to the beginning

        // Upload the part to S3
        $result = $client->uploadPart([
            'Bucket'     => $bucket,
            'Key'        => $key,
            'UploadId'   => $uploadId,
            'PartNumber' => $partNumber,
            'Body'       => stream_get_contents($tempStream),
        ]);

        // Save the part information
        $parts[] = [
            'PartNumber' => $partNumber,
            'ETag'       => $result['ETag'],
        ];

        // Truncate the stream (clear the buffer)
        ftruncate($tempStream, 0);
        rewind($tempStream); // Prepare for next part
    }

    /**
     * Check if the current disk is S3.
     */
    protected function isRemoteDisk()
    {
        return config('filesystems.default') === 's3';
    }

    /**
     * Export the table schema.
     *
     * @param string $tableName The name of the table.
     * @param bool $dropIfExists Whether to include DROP IF EXISTS statements.
     * @return string The SQL schema export string.
     */
    protected function exportTableSchema($tableName, $dropIfExists)
    {
        $schema = "-- Exporting schema for table: {$tableName}\n";

        if ($dropIfExists) {
            $schema .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
        }

        // Get the schema of the table.
        $createTableQuery = DB::select("SHOW CREATE TABLE {$tableName}")[0]->{'Create Table'};
        $schema .= "{$createTableQuery};\n\n";

        return $schema;
    }

    /**
     * Export table data, applying model-specific randomization and column omissions.
     *
     * @param string $modelClass The corresponding Eloquent model for the table.
     * @param string $tableName The table name.
     * @param resource $stream The file stream to write to (S3 or local).
     * @param \Faker\Generator $faker The Faker instance used for randomizing data.
     */
    protected function exportTableDataWithModel($modelClass, $tableName, $stream, $faker)
    {
        fwrite($stream, "-- Exporting data for table: {$tableName} (using model-specific rules)\n");

        $data = DB::table($tableName)->get();

        foreach ($data as $row) {
            // Check if the row is marked to not be randomized (if the model defines keepForPorter).
            if (isset($modelClass::$keepForPorter) && in_array($row->id, $modelClass::$keepForPorter)) {
                $this->writeInsertStatement($stream, $tableName, (array) $row);

                continue;
            }

            // Randomize columns as defined in the model.
            foreach ($row as $key => $value) {
                if (in_array($key, $modelClass::$omittedFromPorter ?? [])) {
                    $row->{$key} = $faker->word; // Example randomization, can be more sophisticated.
                }
            }

            $this->writeInsertStatement($stream, $tableName, (array) $row);
        }

        fwrite($stream, "\n");
    }

    /**
     * Export table data without any model-specific behavior.
     *
     * @param string $tableName The table name.
     * @param resource $stream The file stream to write to (S3 or local).
     */
    protected function exportTableDataWithoutModel($tableName, $stream)
    {
        fwrite($stream, "-- Exporting data for table: {$tableName} (no model found)\n");

        $data = DB::table($tableName)->get();

        foreach ($data as $row) {
            $this->writeInsertStatement($stream, $tableName, (array) $row);
        }

        fwrite($stream, "\n");
    }

    /**
     * Write an INSERT statement for a row of data.
     *
     * @param resource $stream The file stream to write to (S3 or local).
     * @param string $tableName The table name.
     * @param array $row The row data as an associative array.
     */
    protected function writeInsertStatement($stream, $tableName, array $row)
    {
        $columns = implode('`, `', array_keys($row));
        $values  = implode("', '", array_map('addslashes', array_values($row)));

        fwrite($stream, "INSERT INTO `{$tableName}` (`{$columns}`) VALUES ('{$values}');\n");
    }

    /**
     * Dynamically find the model class that corresponds to a given table.
     *
     * @param string $tableName The name of the table to match.
     * @return string|null The model class name or null if no matching model is found.
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
     * Dynamically retrieve all Eloquent models in the application.
     *
     * @return array The list of model class names.
     */
    protected function getAllModels()
    {
        $models    = [];
        $namespace = app()->getNamespace();
        $modelPath = app_path('Models');

        // Use Symfony's Finder component to scan all files in the app/Models directory
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
     * Check if the model should be ignored in the export process.
     * Use property_exists() to check for the $ignoreFromPorter property.
     *
     * @param string $modelClass The class of the model to check.
     * @return bool True if the model should be ignored, false otherwise.
     */
    protected function shouldIgnoreModel($modelClass)
    {
        return isset($modelClass::$ignoreFromPorter) && $modelClass::$ignoreFromPorter === true;
    }
}
