<?php

namespace ThinkNeverland\Porter\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Faker\Factory as Faker;

class PorterService
{
    protected $useS3Storage;

    public function __construct()
    {
        $this->useS3Storage = Config::get('porter.useS3Storage', false);
    }

    /**
     * Export the database to an SQL file.
     *
     * @param string $filePath
     * @param bool $useS3Storage
     * @param bool $dropIfExists
     * @param bool $isCli
     * @return string
     */
    public function export(string $filePath, bool $useS3Storage = false, bool $dropIfExists = false, bool $isCli = false): string
    {
        $tables = $this->getAllTables();
        $sqlContent = "SET FOREIGN_KEY_CHECKS=0;\n\n"; // Disable foreign key checks for the export

        foreach ($tables as $table) {
            $model = $this->getModelForTable($table);

            // Skip tables marked as protected
            if ($model && $model::$protectedFromPorter) {
                continue;
            }

            $sqlContent .= $this->getTableCreateStatement($table, $dropIfExists);
            $sqlContent .= $this->getTableData($table, $model);
        }

        $sqlContent .= "\nSET FOREIGN_KEY_CHECKS=1;"; // Re-enable foreign key checks after export

        if ($useS3Storage) {
            Storage::disk('s3')->put($filePath, $sqlContent);
            return Storage::disk('s3')->url($filePath);
        }

        Storage::put($filePath, $sqlContent);
        return Storage::path($filePath);
    }

    /**
     * Get the create table statement for a specific table.
     *
     * @param string $table
     * @param bool $dropIfExists
     * @return string
     */
    protected function getTableCreateStatement(string $table, bool $dropIfExists): string
    {
        $createStatement = DB::select("SHOW CREATE TABLE {$table}");
        $sql = '';

        // Include DROP IF EXISTS only if selected
        if ($dropIfExists) {
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        }

        $sql .= $createStatement[0]->{'Create Table'} . ";\n\n";
        return $sql;
    }

    /**
     * Get the table data with Faker for randomization.
     */
    protected function getTableData(string $table, $model): string
    {
        $data = DB::table($table)->get();
        if ($data->isEmpty()) {
            return '';
        }

        $randomizedColumns = $model ? $model::$omittedFromPorter : [];
        $retainedRows = $model ? $model::$keepForPorter : [];
        $faker = Faker::create();
        $insertStatements = '';

        foreach ($data as $row) {
            $values = [];

            foreach ($row as $key => $value) {
                // Randomize columns if marked, retain original data for certain rows
                if (in_array($key, $randomizedColumns) && !in_array($row->id, $retainedRows)) {
                    $value = $faker->word;
                }

                // Handle NULL values and empty strings properly
                if (is_null($value)) {
                    $values[] = 'NULL'; // Handle NULL values
                } elseif ($value === '') {
                    $values[] = 'NULL'; // Convert empty string to NULL
                } else {
                    $values[] = DB::getPdo()->quote($value); // Quote other values
                }
            }

            $insertStatements .= "INSERT INTO {$table} VALUES (" . implode(', ', $values) . ");\n";
        }

        return $insertStatements . "\n";
    }

    /**
     * Import an SQL file into the database.
     *
     * @param string $filePath
     * @return void
     */
    public function import(string $filePath): void
    {
        // First, create a backup of the current database
        $backupFilePath = storage_path('app/backups/backup_' . now()->format('Y_m_d_His') . '.sql');
        $this->backup($backupFilePath);

        try {
            // Check if the file exists locally or on S3
            if ($this->useS3Storage) {
                $fileContents = Storage::disk('s3')->get($filePath);
                $localFilePath = storage_path('app/temp/' . basename($filePath));
                file_put_contents($localFilePath, $fileContents);
            } else {
                $localFilePath = $filePath;
            }

            if (!file_exists($localFilePath)) {
                throw new Exception('SQL file not found: ' . $localFilePath);
            }

            // Build the command for Symfony Process, using DB credentials from .env
            $command = [
                'mysql',
                '-u', env('DB_USERNAME'),
                '--host', env('DB_HOST', '127.0.0.1'),
            ];

            // Add password only if not empty
            if (!empty(env('DB_PASSWORD'))) {
                $command[] = '-p' . env('DB_PASSWORD');
            }

            // Add the database name and the source command to load the SQL file
            $command[] = env('DB_DATABASE');
            $command[] = '-e';
            $command[] = 'source ' . $localFilePath;

            // Run the command using Symfony Process
            $process = new Process($command);
            $process->run();

            // Check if the process was successful
            if (!$process->isSuccessful()) {
                throw new Exception('Database import failed: ' . $process->getErrorOutput());
            }

            // Clean up the backup file once the import is successful
            if (file_exists($backupFilePath)) {
                unlink($backupFilePath);
            }

            // Optionally, clean up the temp file if it was fetched from S3
            if ($this->useS3Storage && file_exists($localFilePath)) {
                unlink($localFilePath);
            }

        } catch (Exception $e) {
            // If import fails, restore from backup
            $this->restore($backupFilePath);
            throw new Exception('Import failed, database restored from backup. Error: ' . $e->getMessage());
        }
    }

    /**
     * Backup the current database to a file.
     *
     * @param string $backupFilePath
     * @return void
     */
    public function backup(string $backupFilePath): void
    {
        $command = [
            'mysqldump',
            '-u', env('DB_USERNAME'),
            '--host', env('DB_HOST', '127.0.0.1'),
            env('DB_DATABASE'),
        ];

        // Add password only if not empty
        if (!empty(env('DB_PASSWORD'))) {
            $command[] = '-p' . env('DB_PASSWORD');
        }

        // Save the output to the backup file
        $process = new Process($command);
        $process->mustRun();
        file_put_contents($backupFilePath, $process->getOutput());
    }

    /**
     * Restore the database from a backup file.
     *
     * @param string $backupFilePath
     * @return void
     */
    protected function restore(string $backupFilePath): void
    {
        if (!file_exists($backupFilePath)) {
            throw new Exception('Backup file not found: ' . $backupFilePath);
        }

        // Build the command for restoring the database from the backup
        $command = [
            'mysql',
            '-u', env('DB_USERNAME'),
            '--host', env('DB_HOST', '127.0.0.1'),
        ];

        // Add password only if not empty
        if (!empty(env('DB_PASSWORD'))) {
            $command[] = '-p' . env('DB_PASSWORD');
        }

        // Add the database name and the source command to load the backup file
        $command[] = env('DB_DATABASE');
        $command[] = '-e';
        $command[] = 'source ' . $backupFilePath;

        // Run the command using Symfony Process to restore the database
        $process = new Process($command);
        $process->mustRun();
    }

    /**
     * Clone an S3 bucket to another using environment variables.
     *
     * @return void
     */
    public function cloneS3(): void
    {
        // Use environment variables for source and target references
        $sourceDisk = Storage::build([
            'driver' => 's3',
            'key' => env('AWS_SOURCE_ACCESS_KEY_ID'),
            'secret' => env('AWS_SOURCE_SECRET_ACCESS_KEY'),
            'region' => env('AWS_SOURCE_DEFAULT_REGION'),  // Ensure region is set
            'bucket' => env('AWS_SOURCE_BUCKET'),
            'url' => env('AWS_SOURCE_URL'),
            'endpoint' => env('AWS_SOURCE_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_SOURCE_USE_PATH_STYLE_ENDPOINT', true),
        ]);

        $targetDisk = Storage::build([
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),  // Ensure region is set
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', true),
        ]);

        // Get all files from the source bucket
        $files = $sourceDisk->allFiles();

        // Loop through the files and copy them to the target bucket
        foreach ($files as $file) {
            $targetDisk->put($file, $sourceDisk->get($file));
        }
    }

    /**
     * Get all table names in the database.
     */
    protected function getAllTables(): array
    {
        $result = DB::select('SHOW TABLES');
        return array_map(function ($table) {
            return reset($table);
        }, $result);
    }

    /**
     * Get the corresponding model for a table, if it exists.
     */
    protected function getModelForTable(string $table)
    {
        $models = config('porter.models'); // Assume a mapping between tables and models
        return $models[$table] ?? null;
    }
}
