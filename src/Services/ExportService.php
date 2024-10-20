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
        // Create a Faker instance for generating fake data
        $faker = Faker::create();

        // Get the storage disk configuration
        $disk = Storage::disk(config('filesystems.default'));

        // Encrypt the filename for security
        $encryptedFilename = Crypt::encryptString($filename);

        // Fetch the list of all tables in the database
        $tables = DB::select('SHOW TABLES');

        // Check if the storage disk is remote (S3)
        if ($this->isRemoteDisk()) {
            // Initialize the S3 client with credentials
            $client = new S3Client([
                'version'     => 'latest',
                'region'      => env('AWS_DEFAULT_REGION'),
                'credentials' => [
                    'key'    => env('AWS_ACCESS_KEY_ID'),
                    'secret' => env('AWS_SECRET_ACCESS_KEY'),
                ],
            ]);

            // Get the S3 bucket name and set the key for the file
            $bucket = env('AWS_BUCKET');
            $key    = $encryptedFilename;

            // Start a multipart upload to S3
            $multipart = $client->createMultipartUpload([
                'Bucket' => $bucket,
                'Key'    => $key,
            ]);

            // Initialize variables for multipart upload
            $uploadId   = $multipart['UploadId'];
            $partNumber = 1;
            $parts      = [];
            $bufferSize = 1024 * 1024; // 1MB buffer size
            $tempStream = fopen('php://temp', 'r+');

            // Optionally add DROP IF EXISTS statements
            if ($dropIfExists) {
                fwrite($tempStream, "-- Add DROP IF EXISTS for each table.\n");
            }

            // Iterate over each table to export its schema and data
            foreach ($tables as $table) {
                $tableName  = array_values((array)$table)[0];
                $modelClass = $this->getModelForTable($tableName);

                // Skip tables that should be ignored
                if ($modelClass && $this->shouldIgnoreModel($modelClass)) {
                    continue;
                }

                // Write the table schema to the temporary stream
                fwrite($tempStream, $this->exportTableSchema($tableName, $dropIfExists));

                // Get a generator for the table data
                $dataGenerator = $this->getTableDataGenerator($tableName, $modelClass, $faker);

                // Write each row of data to the temporary stream
                foreach ($dataGenerator as $row) {
                    fwrite($tempStream, $row);

                    // Flush to S3 if the buffer size is exceeded
                    if (ftell($tempStream) >= $bufferSize) {
                        $this->flushToS3($client, $bucket, $key, $tempStream, $uploadId, $partNumber++, $parts);
                    }
                }
            }

            // Flush any remaining data to S3
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

            // Generate a temporary or permanent URL for the file
            if (!$noExpiration) {
                $url = $disk->temporaryUrl($encryptedFilename, now()->addMinutes(30));
            } else {
                $url = $disk->url($encryptedFilename);
            }

            return $url;
        } else {
            // Handle local storage
            $filePath    = storage_path("app/public/{$encryptedFilename}");
            $localStream = fopen($filePath, 'w+');

            // Optionally add DROP IF EXISTS statements
            if ($dropIfExists) {
                fwrite($localStream, "-- Add DROP IF EXISTS for each table.\n");
            }

            // Iterate over each table to export its schema and data
            foreach ($tables as $table) {
                $tableName  = array_values((array)$table)[0];
                $modelClass = $this->getModelForTable($tableName);

                // Skip tables that should be ignored
                if ($modelClass && $this->shouldIgnoreModel($modelClass)) {
                    continue;
                }

                // Write the table schema to the local stream
                fwrite($localStream, $this->exportTableSchema($tableName, $dropIfExists));

                // Get a generator for the table data
                $dataGenerator = $this->getTableDataGenerator($tableName, $modelClass, $faker);

                // Write each row of data to the local stream
                foreach ($dataGenerator as $row) {
                    fwrite($localStream, $row);
                }
            }

            fclose($localStream);

            // Return the URL to access the file
            return asset("storage/{$encryptedFilename}");
        }
    }

    /**
     * Flush the contents of the temporary stream to S3 as a part of the multipart upload.
     *
     * @param S3Client $client The S3 client.
     * @param string $bucket The S3 bucket name.
     * @param string $key The S3 object key.
     * @param resource $tempStream The temporary stream containing data.
     * @param string $uploadId The upload ID for the multipart upload.
     * @param int $partNumber The part number for the upload.
     * @param array &$parts The array of parts uploaded.
     */
    protected function flushToS3($client, $bucket, $key, $tempStream, $uploadId, $partNumber, &$parts)
    {
        rewind($tempStream);

        // Upload the current part to S3
        $result = $client->uploadPart([
            'Bucket'     => $bucket,
            'Key'        => $key,
            'UploadId'   => $uploadId,
            'PartNumber' => $partNumber,
            'Body'       => stream_get_contents($tempStream),
        ]);

        // Store the part information for completing the upload
        $parts[] = [
            'PartNumber' => $partNumber,
            'ETag'       => $result['ETag'],
        ];

        // Clear the temporary stream for the next part
        ftruncate($tempStream, 0);
        rewind($tempStream);
    }

    /**
     * Determine if the current storage disk is remote (S3).
     *
     * @return bool True if the storage disk is S3, false otherwise.
     */
    protected function isRemoteDisk()
    {
        return config('filesystems.default') === 's3';
    }

    /**
     * Export the schema of a given table.
     *
     * @param string $tableName The name of the table.
     * @param bool $dropIfExists Whether to include a DROP IF EXISTS statement.
     * @return string The SQL schema for the table.
     */
    protected function exportTableSchema($tableName, $dropIfExists)
    {
        $schema = "-- Exporting schema for table: {$tableName}\n";

        if ($dropIfExists) {
            $schema .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
        }

        // Get the CREATE TABLE statement for the table
        $createTableQuery = DB::select("SHOW CREATE TABLE {$tableName}")[0]->{'Create Table'};
        $schema .= "{$createTableQuery};\n\n";

        return $schema;
    }

    /**
     * Get a generator for the data of a given table.
     *
     * @param string $tableName The name of the table.
     * @param string|null $modelClass The model class associated with the table.
     * @param \Faker\Generator $faker The Faker instance for generating fake data.
     * @return \Generator A generator yielding SQL insert statements for the table data.
     */
    protected function getTableDataGenerator($tableName, $modelClass, $faker)
    {
        // Use a cursor to iterate over the table data without loading it all into memory
        $data = DB::table($tableName)->cursor();

        foreach ($data as $row) {
            if ($modelClass) {
                // Check if the row should be kept as is
                if (isset($modelClass::$keepForPorter) && in_array($row->id, $modelClass::$keepForPorter)) {
                    yield $this->generateInsertStatement($tableName, (array) $row);
                    continue;
                }

                // Replace omitted fields with fake data
                foreach ($row as $key => $value) {
                    if (in_array($key, $modelClass::$omittedFromPorter ?? [])) {
                        $row->{$key} = $faker->word;
                    }
                }
            }

            // Yield the insert statement for the current row
            yield $this->generateInsertStatement($tableName, (array) $row);
        }
    }

    /**
     * Generate an SQL insert statement for a given row of data.
     *
     * @param string $tableName The name of the table.
     * @param array $row The row data as an associative array.
     * @return string The SQL insert statement.
     */
    protected function generateInsertStatement($tableName, array $row)
    {
        $columns = implode('`, `', array_keys($row));
        $values  = implode("', '", array_map('addslashes', array_values($row)));

        return "INSERT INTO `{$tableName}` (`{$columns}`) VALUES ('{$values}');\n";
    }

    /**
     * Get the model class associated with a given table.
     *
     * @param string $tableName The name of the table.
     * @return string|null The model class name or null if no model is found.
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

        // Find all PHP files in the Models directory
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
        return isset($modelClass::$ignoreFromPorter) && $modelClass::$ignoreFromPorter === true;
    }
}
