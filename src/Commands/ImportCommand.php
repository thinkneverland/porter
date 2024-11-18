<?php

namespace ThinkNeverland\Porter\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ImportCommand extends Command
{
    protected $signature = 'porter:import {file}';
    protected $description = 'Import a SQL file into the database';

    /**
     * Optimal chunk size for file reading (5MB)
     */
    protected const CHUNK_SIZE = 5 * 1024 * 1024;

    public function handle()
    {
        $file = $this->argument('file');
        $filePath = $this->resolvePath($file);

        if (!$this->validateFile($filePath)) {
            return 1;
        }

        try {
            $this->streamImport($filePath);
            $this->info('Database imported successfully!');
            return 0;
        } catch (QueryException $e) {
            $this->error('Failed to import database: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Resolve the file path
     */
    protected function resolvePath($file)
    {
        return $this->isAbsolutePath($file) ? $file : base_path($file);
    }

    /**
     * Validate the import file
     */
    protected function validateFile($filePath)
    {
        if (!file_exists($filePath)) {
            $this->error("File not found at {$filePath}");
            return false;
        }

        if (!is_readable($filePath)) {
            $this->error("File is not readable at {$filePath}");
            return false;
        }

        return true;
    }

    /**
     * Stream the SQL import in chunks
     */
    protected function streamImport($filePath)
    {
        $handle = fopen($filePath, 'r');
        $query = '';
        $delimiter = ';';
        $fileSize = filesize($filePath);

        $this->output->progressStart($fileSize);
        $processedSize = 0;

        while (!feof($handle)) {
            $chunk = fread($handle, self::CHUNK_SIZE);
            $processedSize += strlen($chunk);
            $query .= $chunk;

            // Process complete queries in the buffer
            while (($queryEnd = strpos($query, $delimiter)) !== false) {
                $sqlQuery = substr($query, 0, $queryEnd + 1);
                $query = substr($query, $queryEnd + 1);

                // Execute non-empty queries
                if (trim($sqlQuery)) {
                    DB::unprepared($this->sanitizeQuery($sqlQuery));
                }
            }

            $this->output->progressAdvance(strlen($chunk));
        }

        // Process any remaining query
        if (trim($query)) {
            DB::unprepared($this->sanitizeQuery($query));
        }

        $this->output->progressFinish();
        fclose($handle);
    }

    /**
     * Sanitize SQL query
     */
    protected function sanitizeQuery($query)
    {
        // Remove comments and empty lines
        $lines = explode("\n", $query);
        $lines = array_filter($lines, function($line) {
            $line = trim($line);
            return $line && !preg_match('/^--/', $line) && !preg_match('/^#/', $line);
        });

        return implode("\n", $lines);
    }

    /**
     * Check if path is absolute
     */
    protected function isAbsolutePath($path)
    {
        return $path[0] === DIRECTORY_SEPARATOR ||
            preg_match('/^[a-zA-Z]:[\/\\\\]/', $path);
    }
}
