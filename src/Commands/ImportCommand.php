<?php

namespace ThinkNeverland\Porter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use ThinkNeverland\Porter\Services\PorterService;

class ImportCommand extends Command
{
    protected $signature = 'porter:import {file}';
    protected $description = 'Import an SQL file into the database.';

    protected $importService;

    public function __construct(PorterService $importService)
    {
        parent::__construct();
        $this->importService = $importService;
    }

    public function handle()
    {
        if (!Auth::check()) {
            $username = $this->ask('Enter your username');
            $password = $this->secret('Enter your password');

            if (!Auth::attempt(['email' => $username, 'password' => $password])) {
                $this->error('Invalid credentials.');
                return 1;
            }
        }

        // Authorization check from config
        $user = Auth::user();
        $authorization = config('porter.authorization.import');

        if (!$authorization($user)) {
            $this->error('You are not authorized to import the database.');
            return 1;
        }

        // Proceed with import
        $filePath = $this->argument('file');
        $this->importService->import($filePath);

        $this->info('Database imported successfully.');
    }
}
