<?php

namespace ThinkNeverland\Porter\Commands;

use Illuminate\Console\Command;
use ThinkNeverland\Porter\Services\PorterService;

class ImportCommand extends Command
{
    protected $signature = 'porter:import
        {file : The path to the SQL file to import}';

    protected $description = 'Import an SQL file into the database.';

    protected $importService;

    public function __construct(PorterService $importService)
    {
        parent::__construct();
        $this->importService = $importService;
    }

    public function handle()
    {
        // Proceed with import
        $filePath = $this->argument('file');
        $this->importService->import($filePath);

        $this->info('Database imported successfully.');
    }
}
