<?php

namespace ThinkNeverland\Porter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use ThinkNeverland\Porter\Services\PorterService;

class ExportCommand extends Command
{
    protected $signature = 'porter:export
        {file : The path to export the SQL file}
        {--drop-if-exists : Option to include DROP TABLE IF EXISTS for all tables (optional)}
        {--keep-if-exists : Option to keep IF EXISTS for all tables (optional)}';

    protected $description = 'Export the database to an SQL file.';

    protected $exportService;

    public function __construct(PorterService $exportService)
    {
        parent::__construct();
        $this->exportService = $exportService;
    }

    public function handle()
    {
        // No prompts if --drop-if-exists or --keep-if-exists flags are used
        $dropIfExists = $this->option('drop-if-exists') ?: false;
        $keepIfExists = $this->option('keep-if-exists') ?: false;

        // Proceed with export
        $filePath = $this->argument('file');
        $storagePath = $this->exportService->export($filePath, $dropIfExists, $keepIfExists, true);

        // Provide a relative file path
        $this->info('Database exported successfully to: ' . $filePath);

        // Generate a temporary download link
        $downloadLink = $this->generateDownloadLink($storagePath);

        // Display the download link
        $this->info('Download your SQL file here: ' . $downloadLink);

        // Schedule file deletion after link expiration
        $this->scheduleFileDeletion($filePath);
    }

    protected function generateDownloadLink(string $filePath)
    {
        $disk = config('filesystems.default', 'local');

        if ($disk === 's3') {
            // Generate a temporary link for S3
            return Storage::disk('s3')->temporaryUrl($filePath, now()->addMinutes(30));
        }

        // Generate a local download link (assuming local file access via a relative URL)
        $relativePath = str_replace(storage_path('app/'), '', $filePath);
        return route('download', ['file' => $relativePath]); // Assuming there's a route to handle local file downloads
    }

    protected function scheduleFileDeletion(string $filePath)
    {
        // Schedule deletion of the file after 30 minutes
        $disk = config('filesystems.default', 'local');

        // For S3, simply let the expiration on the link handle it (S3 handles the expiration).
        if ($disk !== 's3') {
            // For local files, we need to delete the file after 30 minutes
            Storage::disk('local')->delete($filePath, now()->addMinutes(30));
        }
    }
}
