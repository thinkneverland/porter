<?php

namespace ThinkNeverland\Porter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use ThinkNeverland\Porter\Services\PorterService;

class ExportCommand extends Command
{
    protected $signature = 'porter:export
        {file : The path to export the SQL file}
        {--download : Option to download the file from S3}
        {--username= : The username for authentication (optional)}
        {--password= : The password for authentication (optional)}
        {--drop-if-exists : Option to include DROP TABLE IF EXISTS for all tables (optional)}';

    protected $description = 'Export the database to an SQL file.';

    protected $exportService;

    public function __construct(PorterService $exportService)
    {
        parent::__construct();
        $this->exportService = $exportService;
    }

    public function handle()
    {
        // Check if user is already authenticated
        if (!Auth::check()) {
            // If not authenticated, use CLI options for username and password or prompt for them
            $username = $this->option('username') ?: $this->ask('Enter your username');
            $password = $this->option('password') ?: $this->secret('Enter your password');

            // Authentication
            if (!Auth::attempt(['email' => $username, 'password' => $password])) {
                $this->error('Invalid credentials.');
                return 1;
            }
        }

        // Get the currently authenticated user
        $user = Auth::user();

        // Authorization check from config
        $authorization = config('porter.authorization.export');

        if (!$authorization($user)) {
            $this->error('You are not authorized to export the database.');
            return 1;
        }

        // Check if the "DROP IF EXISTS" option is passed via CLI, else prompt
        $dropIfExists = $this->option('drop-if-exists') ?: $this->confirm('Include DROP TABLE IF EXISTS for all tables?');

        // Proceed with export
        $filePath = $this->argument('file');
        $useS3Storage = config('porter.useS3Storage');
        $storagePath = $this->exportService->export($filePath, $useS3Storage, $dropIfExists, true);

        $this->info('Database exported successfully to: ' . $storagePath);

        // Optional download from S3
        if ($this->option('download') && $useS3Storage) {
            $this->downloadSqlFile($storagePath);
        }

        return 0;
    }

    protected function downloadSqlFile(string $filePath)
    {
        $disk = 's3';
        if (Storage::disk($disk)->exists($filePath)) {
            $url = Storage::disk($disk)->temporaryUrl($filePath, now()->addMinutes(30));
            $this->info('Download your SQL file here: ' . $url);
        } else {
            $this->error('SQL file does not exist.');
        }
    }
}
