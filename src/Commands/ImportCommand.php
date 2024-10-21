<?php

namespace ThinkNeverland\Porter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportCommand extends Command
{
    protected $signature = 'porter:import {file}';
    protected $description = 'Import a SQL file into the database';

    public function handle()
    {
        $file = $this->argument('file');

        // Determine if the path is absolute
        if ($this->isAbsolutePath($file)) {
            $filePath = $file;
        } else {
            // Treat it as a relative path from the project root
            $filePath = base_path($file);
        }

        // Check if the file exists
        if (!file_exists($filePath)) {
            $this->error("File not found at {$filePath}");
            return;
        }

        // Load and import SQL file contents into the database
        $sql = file_get_contents($filePath);
        DB::unprepared($sql);

        $this->info('Database imported successfully!');
    }

    /**
     * Check if a given path is an absolute path.
     *
     * @param string $path
     * @return bool
     */
    protected function isAbsolutePath($path)
    {
        return $path[0] === DIRECTORY_SEPARATOR || preg_match('/^[a-zA-Z]:[\/\\\\]/', $path);
    }
}
