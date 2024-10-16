<?php

namespace ThinkNeverland\Porter\Commands;

use Illuminate\Console\Command;
use ThinkNeverland\Porter\Services\PorterService;

class CloneS3Command extends Command
{
    protected $signature = 'porter:clone-s3';

    protected $description = 'Clone files from one S3 bucket to another using environment configurations.';

    protected $porterService;

    public function __construct(PorterService $porterService)
    {
        parent::__construct();
        $this->porterService = $porterService;
    }

    public function handle()
    {
        // Fetch the source and target configurations from the environment
        $sourceBucket = env('AWS_SOURCE_BUCKET');
        $targetBucket = env('AWS_BUCKET');
        $sourceUrl    = env('AWS_SOURCE_URL') ?: $this->ask('Enter the AWS_SOURCE_URL');

        $this->info("Cloning S3 from '{$sourceBucket}' to '{$targetBucket}' using URL '{$sourceUrl}'...");

        $this->porterService->cloneS3($sourceBucket, $targetBucket, $sourceUrl);

        $this->info("S3 clone completed successfully.");
    }
}
