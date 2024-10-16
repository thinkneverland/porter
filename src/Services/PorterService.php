<?php

namespace ThinkNeverland\Porter\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PorterService
{
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

        // Use public storage to save the file
        $publicFilePath = 'public/' . $filePath;

        Storage::put($publicFilePath, $sqlContent);
        return Storage::path($publicFilePath);
    }

    // Other necessary methods
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

    protected function getTableData(string $table, $model): string
    {
        $data = DB::table($table)->get();
        if ($data->isEmpty()) {
            return '';
        }

        $randomizedColumns = $model ? $model::$omittedFromPorter : [];
        $retainedRows = $model ? $model::$keepForPorter : [];
        $insertStatements = '';

        foreach ($data as $row) {
            $values = [];

            foreach ($row as $key => $value) {
                if (in_array($key, $randomizedColumns) && !in_array($row->id, $retainedRows)) {
                    $value = "REDACTED";
                }

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

    protected function getAllTables(): array
    {
        $result = DB::select('SHOW TABLES');
        return array_map(function ($table) {
            return reset($table);
        }, $result);
    }

    protected function getModelForTable(string $table)
    {
        $models = config('porter.models');
        return $models[$table] ?? null;
    }
}
