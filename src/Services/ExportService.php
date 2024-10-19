<?php

namespace ThinkNeverland\Porter\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;
use Symfony\Component\Finder\Finder;
use Illuminate\Support\Str;

class ExportService
{
    /**
     * Export the database to a file, apply model-based configurations, and optionally upload it to S3.
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

        // Scan all models and export their data.
        foreach ($this->getAllModels() as $modelClass) {
            $model = new $modelClass;

            // Skip models marked for ignoring in the export process.
            if ($model::$ignoreFromPorter ?? false) {
                continue;
            }

            // Export table schema.
            fwrite($fileHandle, $this->exportTableSchema($model, $dropIfExists));

            // Export table data with randomization as per model config.
            $this->exportTableData($model, $fileHandle, $faker);
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
     * @param \Illuminate\Database\Eloquent\Model $model The model representing the table.
     * @param bool $dropIfExists Whether to include DROP IF EXISTS statements.
     * @return string The SQL schema export string.
     */
    protected function exportTableSchema($model, $dropIfExists)
    {
        $tableName = $model->getTable();
        $schema = "-- Exporting schema for table: {$tableName}\n";

        if ($dropIfExists) {
            $schema .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
        }

        // Example of getting the schema from the database.
        $createTableQuery = DB::select("SHOW CREATE TABLE {$tableName}")[0]->{'Create Table'};
        $schema .= "{$createTableQuery};\n\n";

        return $schema;
    }

    /**
     * Export the table data, with randomization applied as per model configuration.
     *
     * @param \Illuminate\Database\Eloquent\Model $model The model representing the table.
     * @param resource $fileHandle The file handle to write to.
     * @param \Faker\Generator $faker The Faker instance used for randomizing data.
     */
    protected function exportTableData($model, $fileHandle, $faker)
    {
        $tableName = $model->getTable();
        fwrite($fileHandle, "-- Exporting data for table: {$tableName}\n");

        $data = DB::table($tableName)->get();

        foreach ($data as $row) {
            // Check if the row is marked to not be randomized (if the model defines keepForPorter).
            if (isset($model::$keepForPorter) && in_array($row->id, $model::$keepForPorter)) {
                $this->writeInsertStatement($fileHandle, $tableName, (array) $row);
                continue;
            }

            // Randomize columns as defined in the model.
            foreach ($row as $key => $value) {
                if (in_array($key, $model::$omittedFromPorter ?? [])) {
                    $row->{$key} = $faker->word; // Example randomization, can be more sophisticated.
                }
            }

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
