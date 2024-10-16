<?php

namespace ThinkNeverland\Porter\Services;

use Exception;
use Illuminate\Support\Facades\{DB, Storage};
use Symfony\Component\Process\Process;

class PorterService
{
    /**
     * Export the database to an SQL file.
     *
     * @param string $filePath
     * @param bool $dropIfExists
     * @return string
     */
    public function export(string $filePath, bool $dropIfExists = false): string
    {
        // Export logic here...
        $tables     = DB::select('SHOW TABLES');
        $sqlContent = "SET FOREIGN_KEY_CHECKS=0;\n\n"; // Disable foreign key checks for the export

        foreach ($tables as $table) {
            $tableName = reset($table);
            $sqlContent .= $this->getTableCreateStatement($tableName, $dropIfExists);
            $sqlContent .= $this->getTableData($tableName);
        }

        $sqlContent .= "\nSET FOREIGN_KEY_CHECKS=1;"; // Re-enable foreign key checks after export

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
        $sql             = '';

        if ($dropIfExists) {
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        }

        $sql .= $createStatement[0]->{'Create Table'} . ";\n\n";

        return $sql;
    }

    /**
     * Get the table data.
     *
     * @param string $table
     * @return string
     */
    protected function getTableData(string $table): string
    {
        $data = DB::table($table)->get();

        if ($data->isEmpty()) {
            return '';
        }

        $insertStatements = '';

        foreach ($data as $row) {
            $values = [];

            foreach ($row as $value) {
                $values[] = DB::getPdo()->quote($value);
            }
            $insertStatements .= "INSERT INTO {$table} VALUES (" . implode(', ', $values) . ");\n";
        }

        return $insertStatements . "\n";
    }

    /**
     * Import an SQL file into the database.
     *
     * @param string $filePath
     * @throws Exception
     */
    public function import(string $filePath): void
    {
        $disk          = config('filesystems.default');
        $localFilePath = ($disk === 's3')
            ? $this->fetchFromS3($filePath)
            : storage_path('app/public/' . basename($filePath));

        if (!file_exists($localFilePath)) {
            throw new Exception('SQL file not found: ' . $localFilePath);
        }

        $command = [
            'mysql',
            '-u', env('DB_USERNAME'),
            '--host', env('DB_HOST', '127.0.0.1'),
        ];

        if (!empty(env('DB_PASSWORD'))) {
            $command[] = '-p' . env('DB_PASSWORD');
        }

        $command[] = env('DB_DATABASE');
        $command[] = '-e';
        $command[] = 'source ' . $localFilePath;

        $process = new Process($command);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new Exception('Database import failed: ' . $process->getErrorOutput());
        }

        if ($disk === 's3' && file_exists($localFilePath)) {
            unlink($localFilePath);
        }
    }

    /**
     * Fetch a file from S3 to a local temporary file.
     *
     * @param string $filePath
     * @return string
     */
    protected function fetchFromS3(string $filePath): string
    {
        $fileContents  = Storage::disk('s3')->get($filePath);
        $localFilePath = storage_path('app/temp/' . basename($filePath));
        file_put_contents($localFilePath, $fileContents);

        return $localFilePath;
    }

    /**
     * Clone files from one S3 bucket to another.
     *
     * @param string $sourceBucket
     * @param string $targetBucket
     * @param string $sourceUrl
     */
    public function cloneS3(string $sourceBucket, string $targetBucket, string $sourceUrl): void
    {
        $sourceDisk = Storage::build([
            'driver'                  => 's3',
            'key'                     => env('AWS_SOURCE_ACCESS_KEY_ID'),
            'secret'                  => env('AWS_SOURCE_SECRET_ACCESS_KEY'),
            'region'                  => env('AWS_SOURCE_DEFAULT_REGION'),
            'bucket'                  => $sourceBucket,
            'url'                     => $sourceUrl,
            'endpoint'                => env('AWS_SOURCE_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_SOURCE_USE_PATH_STYLE_ENDPOINT', true),
        ]);

        $targetDisk = Storage::disk('s3');

        $files = $sourceDisk->allFiles();

        foreach ($files as $file) {
            $targetDisk->put($file, $sourceDisk->get($file));
        }
    }
}
