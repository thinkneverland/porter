<?php

namespace ThinkNeverland\Porter\Services;

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
        // Initialize Faker for data randomization.
        $faker = Faker::create();

        // Determine which disk to use (S3 or local).
        $disk = Storage::disk(config('filesystems.default'));

        // Encrypt the filename for security purposes.
        $encryptedFilename = Crypt::encryptString($filename);

        // Create a temporary file for storing the SQL dump
        $tempFile = tmpfile(); // Temporary file handle

        // Optionally add DROP IF EXISTS statements for each table.
        if ($dropIfExists) {
            fwrite($tempFile, "-- Add DROP IF EXISTS for each table.\n");
        }

        // Get all tables from the database.
        $tables = DB::select('SHOW TABLES');

        // Loop through all tables in the database.
        foreach ($tables as $table) {
            $tableName = array_values((array) $table)[0];

            // Try to find a corresponding model for the table.
            $modelClass = $this->getModelForTable($tableName);

            // Check if the model has a skip property ($ignoreFromPorter), if so, skip it.
            if ($modelClass && $this->shouldIgnoreModel($modelClass)) {
                continue; // Skip this table if it's marked to be ignored by the model.
            }

            // Export table schema.
            fwrite($tempFile, $this->exportTableSchema($tableName, $dropIfExists));

            // Export table data.
            if ($modelClass) {
                // Use model-specific behavior for randomization and omissions.
                $this->exportTableDataWithModel($modelClass, $tableName, $tempFile, $faker);
            } else {
                // No model found, export data without any custom behavior.
                $this->exportTableDataWithoutModel($tableName, $tempFile);
            }
        }

        // Rewind the file so it can be read for upload
        rewind($tempFile);

        // If using a remote disk (like S3), upload the file using putStream().
        if ($this->isRemoteDisk($disk)) {
            // Stream the file directly to the remote storage (e.g., S3)
            $disk->put($encryptedFilename, stream_get_contents($tempFile));

            // Close the temp file
            fclose($tempFile);

            // Generate a temporary URL if needed
            if (!$noExpiration) {
                return $disk->temporaryUrl($encryptedFilename, now()->addSeconds(config('porter.expiration')));
            }

            // Return the remote URL
            return $disk->url($encryptedFilename);
        }

        // If using local storage, save the temp file to disk
        $localPath = storage_path("app/{$filename}");
        file_put_contents($localPath, stream_get_contents($tempFile));

        // Close the temp file
        fclose($tempFile);

        // Return the local file path as the download link
        return $localPath;
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

    /**
     * Check if the current disk is a remote disk (e.g., S3).
     *
     * @param \Illuminate\Contracts\Filesystem\Filesystem $disk
     * @return bool True if the disk is remote, false if local.
     */
    protected function isRemoteDisk($disk)
    {
        // This works for remote disks like S3
        return $disk->getDriver() instanceof \League\Flysystem\FilesystemOperator;
    }
}
