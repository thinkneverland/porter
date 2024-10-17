<?php

namespace ThinkNeverland\Porter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use ThinkNeverland\Porter\Services\PorterService;
use ThinkNeverland\Porter\Jobs\DeleteFileAfterExpiration;

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
        // Get CLI options and arguments
        $filePath = $this->argument('file');
        $dropIfExists = $this->option('drop-if-exists') ? true : false;
        $keepIfExists = $this->option('keep-if-exists') ? true : false;
        $noExpiration = $this->option('no-expiration') ? true : false;

        // Ensure the file is saved in the correct storage disk
        $disk = config('filesystems.default', 'public'); // Default to public if not set

        // Ensure the file path points to public or S3 based on the configuration
        if ($disk === 's3') {
            $filePath = ltrim($filePath, '/'); // S3 doesn't need the 'public/' prefix
        } else {
            $filePath = 'public/' . ltrim($filePath, '/'); // Local disk should be under public
        }

        // Proceed with export
        $storagePath = $this->exportService->export($filePath, $disk === 's3', $dropIfExists, true);

        // Encrypt the file name
        $encryptedFileName = Crypt::encryptString($filePath);

        // Generate download link
        $downloadLink = $this->generateDownloadLink($encryptedFileName, $disk, $noExpiration);

        // Output success message and download link
        $this->info('Database exported successfully to: ' . $storagePath);
        $this->info('Download your SQL file here: ' . $downloadLink);

        // Dispatch job to delete file after expiration if the link is temporary
        if (!$noExpiration) {
            $deletionTime = now()->addMinutes(15);
            DeleteFileAfterExpiration::dispatch($storagePath, $disk)->delay($deletionTime);
        }

        return 0;
    }

    /**
     * Generate a download link based on the storage method (S3 or public).
     */
    protected function generateDownloadLink(string $encryptedFileName, string $disk, bool $noExpiration = false)
    {
        $decryptedFileName = Crypt::decryptString($encryptedFileName);

        if ($disk === 's3') {
            // Generate a signed temporary URL for S3 or a permanent link if no expiration
            return !$noExpiration
                ? Storage::disk($disk)->temporaryUrl($decryptedFileName, now()->addMinutes(30))
                : Storage::disk($disk)->url($decryptedFileName);
        } else {
            // For local storage, generate a public URL with an encrypted file name
            return route('porter.download', ['file' => $encryptedFileName]);
        }
    }
}
