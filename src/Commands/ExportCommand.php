<?php

namespace ThinkNeverland\Porter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Crypt, Storage};
use ThinkNeverland\Porter\Jobs\DeleteFileAfterExpiration;
use ThinkNeverland\Porter\Services\PorterService;

class ExportCommand extends Command
{
    protected $signature = 'porter:export
        {file : The path to export the SQL file}
        {--drop-if-exists : Option to include DROP TABLE IF EXISTS for all tables (optional)}
        {--keep-if-exists : Option to leave IF EXISTS for all tables (optional)}
        {--no-expiration : Generate a download link without expiration (optional)}';

    protected $description = 'Export the database to an SQL file.';

    protected $exportService;

    public function __construct(PorterService $exportService)
    {
        parent::__construct();
        $this->exportService = $exportService;
    }

    public function handle()
    {
        $filePath     = $this->argument('file');
        $dropIfExists = $this->option('drop-if-exists') ? true : false;
        $keepIfExists = $this->option('keep-if-exists') ? true : false;
        $noExpiration = $this->option('no-expiration') ? true : false;

        // Use correct disk based on configuration
        $disk     = config('filesystems.default');
        $filePath = ltrim($filePath, '/'); // Adjust the file path for S3

        // Proceed with export
        $storagePath = $this->exportService->export($filePath, $dropIfExists, $keepIfExists);

        // Encrypt the file name for the download link
        $encryptedFileName = Crypt::encryptString($filePath);

        // Generate the download link
        $downloadLink = $this->generateDownloadLink($encryptedFileName, $disk, $noExpiration);

        // Output success message and download link
        $this->info('Database exported successfully to: ' . $storagePath);
        $this->info('Download your SQL file here: ' . $downloadLink);

        // Schedule file deletion if link is temporary
        if (!$noExpiration) {
            $deletionTime = now()->addMinutes(30);
            DeleteFileAfterExpiration::dispatch($filePath, config('filesystems.default'))->delay($deletionTime);
        }

        return 0;
    }

    /**
     * Generate a download link based on the storage disk.
     */
    protected function generateDownloadLink(string $encryptedFileName, string $disk, bool $noExpiration = false)
    {
        $decryptedFileName = Crypt::decryptString($encryptedFileName);

        if ($disk === 's3') {
            // Generate a signed URL for S3
            return !$noExpiration
                ? Storage::disk('s3')->temporaryUrl($decryptedFileName, now()->addMinutes(30))
                : Storage::disk('s3')->url($decryptedFileName);
        } else {
            // Generate a route for local storage with encryption
            return route('porter.download', ['file' => $encryptedFileName]);
        }
    }
}
