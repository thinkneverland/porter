<?php

namespace ThinkNeverland\Porter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use ThinkNeverland\Porter\Services\PorterService;

class ImportCommand extends Command
{
    protected $signature = 'porter:import
        {file : The path to the SQL file to import}
        {--username= : The username for authentication (optional)}
        {--password= : The password for authentication (optional)}';

    protected $description = 'Import an SQL file into the database.';

    protected $importService;

    public function __construct(PorterService $importService)
    {
        parent::__construct();
        $this->importService = $importService;
    }

    public function handle()
    {
        // Check if the user is already authenticated
        if (!Auth::check()) {
            // Use CLI options for username and password if provided, otherwise prompt for them
            $username = $this->option('username') ?: $this->ask('Enter your username');
            $password = $this->option('password') ?: $this->secret('Enter your password');

            // Attempt authentication
            if (!Auth::attempt(['email' => $username, 'password' => $password])) {
                $this->error('Invalid credentials.');
                return 1;
            }
        }

        // Get the currently authenticated user
        $user = Auth::user();

        // Authorization check from config
        $authorization = config('porter.authorization.import');

        if (!$authorization($user)) {
            $this->error('You are not authorized to import the database.');
            return 1;
        }

        // Proceed with the import
        $filePath = $this->argument('file');
        $this->importService->import($filePath);

        $this->info('Database imported successfully.');
    }
}
