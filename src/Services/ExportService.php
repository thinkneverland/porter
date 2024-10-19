<?php

namespace ThinkNeverland\Porter\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;
use Symfony\Component\Finder\Finder;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;

class ExportService
{
    /**
     * Export the entire database to a file, apply model-based configurations, and optionally upload it to S3.
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
        $path = storage_path("app/{$filename}");

        // Open a file handle for writing SQL.
        $fileHandle = fopen($path, 'w');

        // Optionally add DROP IF EXISTS.
        if ($dropIfExists) {
            fwrite($fileHandle, "-- Add DROP IF EXISTS for each table.\n");
        }

        // Get all tables from the database.
        $tables = DB::select('SHOW TABLES');

        // Loop through all tables in the database.
        foreach ($tables as $table) {
            $tableName = array_values((array) $table)[0];

            // Try to find a corresponding model for the table.
            $modelClass = $this->getModelForTable($tableName);

            // If there is a model and it should be ignored, skip it.
            if ($modelClass && $modelClass::$ignoreFromPorter ?? false) {
                $this->info("Skipping table: {$tableName} (ignored by model configuration)");
                continue;
            }

            // Export table schema.
            fwrite($fileHandle, $this->exportTableSchema($tableName, $dropIfExists));

            // Export table data.
            if ($modelClass) {
                // Use model-specific behavior for randomization and omissions.
                $this->exportTableDataWithModel($modelClass, $tableName, $fileHandle, $faker);
            } else {
                // No model found, export data without any custom behavior.
                $this->exportTableDataWithoutModel($tableName, $fileHandle);
            }
        }

        // Close the file handle.
        fclose($fileHandle);

        // Upload to S3 using Laravel's default S3 configuration.
        $bucket = config('filesystems.disks.s3.bucket');
        if ($bucket) {
            return $this->uploadToS3($filename, $path, $noExpiration);
        }

        return null;
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
     * @param resource $fileHandle The file handle to write to.
     * @param \Faker\Generator $faker The Faker instance used for randomizing data.
     */
    protected function exportTableDataWithModel($modelClass, $tableName, $fileHandle, $faker)
    {
        fwrite($fileHandle, "-- Exporting data for table: {$tableName} (using model-specific rules)\n");

        $data = DB::table($tableName)->get();

        foreach ($data as $row) {
            // Check if the row is marked to not be randomized (if the model defines keepForPorter).
            if (isset($modelClass::$keepForPorter) && in_array($row->id, $modelClass::$keepForPorter)) {
                $this->writeInsertStatement($fileHandle, $tableName, (array) $row);
                continue;
            }

            // Randomize columns as defined in the model.
            foreach ($row as $key => $value) {
                if (in_array($key, $modelClass::$omittedFromPorter ?? [])) {
                    $row->{$key} = $faker->word; // Example randomization, can be more sophisticated.
                }
            }

            $this->writeInsertStatement($fileHandle, $tableName, (array) $row);
        }

        fwrite($fileHandle, "\n");
    }

    /**
     * Export table data without any model-specific behavior.
     *
     * @param string $tableName The table name.
     * @param resource $fileHandle The file handle to write to.
     */
    protected function exportTableDataWithoutModel($tableName, $fileHandle)
    {
        fwrite($fileHandle, "-- Exporting data for table: {$tableName} (no model found)\n");

        $data = DB::table($tableName)->get();

        foreach ($data as $row) {
            $this->writeInsertStatement($fileHandle, $tableName, (array) $row);
        }

        fwrite($fileHandle, "\n");
    }

    /**
     * Write an INSERT statement for a row of data.
     *
     * @param resource $fileHandle The file handle to write to.
     * @param string $tableName The table name.
     * @param array $row The row data as an associative array.
     */
    protected function writeInsertStatement($fileHandle, $tableName, array $row)
    {
        $columns = implode('`, `', array_keys($row));
        $values = implode("', '", array_map('addslashes', array_values($row)));

        fwrite($fileHandle, "INSERT INTO `{$tableName}` (`{$columns}`) VALUES ('{$values}');\n");
    }

    /**
     * Upload the exported database file to S3 and optionally create a temporary URL.
     *
     * @param string $filename The name of the file to upload.
     * @param string $localPath The local path of the exported SQL file.
     * @param bool $noExpiration Whether the file download link should have no expiration.
     * @return string|null The download link or null if no link was created.
     */
    protected function uploadToS3($filename, $localPath, $noExpiration)
    {
        // Encrypt the filename to add an extra layer of security.
        $encryptedFilename = Crypt::encryptString($filename);

        // Upload the file to S3 using Laravel's default S3 configuration.
        Storage::disk('s3')->put($encryptedFilename, file_get_contents($localPath));

        // If no expiration is set, create a temporary URL that expires.
        if (!$noExpiration) {
            return Storage::disk('s3')->temporaryUrl($encryptedFilename, now()->addSeconds(config('porter.expiration')));
        }

        return Storage::disk('s3')->url($encryptedFilename);
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
            $model = new $modelClass;

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
        $models = [];
        $namespace = app()->getNamespace();
        $modelPath = app_path('Models');

        // Use Symfony's Finder component to scan all files in the app/Models directory
        $finder = new Finder();
        foreach ($finder->files()->in($modelPath)->name('*.php') as $file) {
            $relativePath = str_replace('/', '\\', $file->getRelativePathname());
            $class = $namespace . 'Models\\' . Str::replaceLast('.php', '', $relativePath);

            if (class_exists($class)) {
                $models[] = $class;
            }
        }

        return $models;
    }
}
