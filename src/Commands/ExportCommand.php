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
        // Get CLI options and arguments
        $filePath     = $this->argument('file');
        $dropIfExists = $this->option('drop-if-exists') ? true : false;
        $keepIfExists = $this->option('keep-if-exists') ? true : false;
        $noExpiration = $this->option('no-expiration') ? true : false;

        // Ensure the file is saved in the public storage directory
        $filePath = 'public/' . ltrim($filePath, '/');

        // Proceed with export using filesystem configuration
        $storagePath = $this->exportService->export($filePath, $dropIfExists);

        // Encrypt the file name
        $encryptedFileName = Crypt::encryptString($filePath);

        // Generate download link
        $downloadLink = $this->generateDownloadLink($encryptedFileName, $noExpiration);

        // Output success message and download link
        $this->info('Database exported successfully to: ' . $storagePath);
        $this->info('Download your SQL file here: ' . $downloadLink);

        // Dispatch job to delete file after expiration if the link is temporary
        if (!$noExpiration) {
            $deletionTime = now()->addMinutes(30);
            DeleteFileAfterExpiration::dispatch($storagePath, config('filesystems.default'))->delay($deletionTime);
        }

        return 0;
    }

    /**
     * Generate a download link based on the storage method.
     */
    protected function generateDownloadLink(string $encryptedFileName, bool $noExpiration = false)
    {
        $decryptedFileName = Crypt::decryptString($encryptedFileName);

        if (config('filesystems.default') === 's3') {
            if (!$noExpiration) {
                return Storage::disk('s3')->temporaryUrl($decryptedFileName, now()->addDay());
            } else {
                return Storage::disk('s3')->temporaryUrl($decryptedFileName, now()->addCentury());
            }
        } else {
            return route('porter.download', ['file' => $encryptedFileName]);
        }
    }
}
