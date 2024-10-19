<?php

namespace ThinkNeverland\Porter\Commands;

use Illuminate\Console\Command;
use ThinkNeverland\Porter\Services\ImportService;

class ImportCommand extends Command
{
    // Command signature with the path argument for importing SQL files.
    protected $signature = 'porter:import {path}';

    // Command description for Artisan.
    protected $description = 'Import a SQL file into the database';

    /**
     * Handle the command execution.
     */
    public function handle()
    {
        // Retrieve the file path argument passed by the user.
        $path = $this->argument('path');

        // Create an instance of the ImportService.
        $importService = new ImportService();

        // Call the service to handle the database import.
        $importService->importDatabase($path);

        // Output a success message to the console.
        $this->info("Database import completed from: $path");
    }
}
