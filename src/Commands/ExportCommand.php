<?php

namespace ThinkNeverland\Porter\Commands;

use Illuminate\Console\Command;
use ThinkNeverland\Porter\Services\ExportService;

class ExportCommand extends Command
{
    // Command signature with options for exporting the database.
    protected $signature = 'porter:export {filename} {--drop-if-exists} {--no-expiration}';

    // Command description for Artisan.
    protected $description = 'Export the entire database and optionally upload it to S3, respecting model configurations for skipping, omitting, and randomizing data.';

    /**
     * Handle the command execution.
     */
    public function handle()
    {
        // Retrieve arguments and options passed by the user.
        $dropIfExists = $this->option('drop-if-exists');
        $noExpiration = $this->option('no-expiration');

        // Create an instance of the ExportService.
        $exportService = new ExportService();

        // Call the service to handle the database export and get the download link.
        $downloadLink = $exportService->exportDatabase($dropIfExists, $noExpiration);

        // Output the download link to the console.
        if ($downloadLink) {
            $this->info("Database export completed. Download link: $downloadLink");
        } else {
            $this->info("Database export completed.");
        }
    }
}
