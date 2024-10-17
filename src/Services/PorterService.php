<?php

namespace ThinkNeverland\Porter\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Config;
use Faker\Factory as Faker;

class PorterService
{
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
        // Disable foreign key checks for the export
        $sqlContent = "SET FOREIGN_KEY_CHECKS=0;\n\n";
        $tables = $this->getAllTables();

        foreach ($tables as $table) {
            $model = $this->getModelForTable($table);

            // Skip tables marked as protected from export
            if ($model && $model::$ignoreFromPorter) {
                continue;
            }

            $sqlContent .= $this->getTableCreateStatement($table, $dropIfExists);
            $sqlContent .= $this->getTableData($table, $model);
        }

        // Re-enable foreign key checks after export
        $sqlContent .= "\nSET FOREIGN_KEY_CHECKS=1;";

        // Store the SQL content to the appropriate disk
        if ($useS3Storage) {
            Storage::disk('s3')->put($filePath, $sqlContent);
            return Storage::disk('s3')->url($filePath);
        } else {
            Storage::disk('public')->put($filePath, $sqlContent);
            return Storage::disk('public')->path($filePath);
        }
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

        // Optionally include DROP IF EXISTS
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
                if (in_array($key, $randomizedColumns) && !in_array($row->id, $retainedRows)) {
                    $value = $faker->word;
                }

                // Handle NULL and empty strings
                if (is_null($value)) {
                    $values[] = 'NULL';
                } elseif ($value === '') {
                    $values[] = 'NULL';
                } else {
                    $values[] = DB::getPdo()->quote($value);
                }
            }

            $insertStatements .= "INSERT INTO {$table} VALUES (" . implode(', ', $values) . ");\n";
        }

        return $insertStatements . "\n";
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
