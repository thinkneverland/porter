<?php

namespace ThinkNeverland\Porter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use ThinkNeverland\Porter\Services\PorterService;

class CloneS3Command extends Command
{
    protected $signature = 'porter:clone-s3
        {--username= : The username for authentication (optional)}
        {--password= : The password for authentication (optional)}';

    protected $description = 'Clone files from one S3 bucket to another using environment configurations';

    protected $porterService;

    public function __construct(PorterService $porterService)
    {
        parent::__construct();
        $this->porterService = $porterService;
    }

    public function handle()
    {
        // Authenticate the user, using either provided options or interactive prompts
        if (!Auth::check()) {
            $username = $this->option('username') ?: $this->ask('Enter your username');
            $password = $this->option('password') ?: $this->secret('Enter your password');

            if (!Auth::attempt(['email' => $username, 'password' => $password])) {
                $this->error('Invalid credentials.');
                return 1;
            }
        }

        // Authorization check from config
        $user = Auth::user();
        $authorization = config('porter.authorization.cloneS3');

        if (!$authorization($user)) {
            $this->error('You are not authorized to clone the S3 bucket.');
            return 1;
        }

        // Fetch source and target configurations from the environment
        $sourceBucket = env('AWS_SOURCE_BUCKET');
        $targetBucket = env('AWS_BUCKET');

        // Check if the buckets are defined
        if (!$sourceBucket || !$targetBucket) {
            $this->error('Source or target S3 bucket not defined in the environment.');
            return 1;
        }

        // Ask for AWS_SOURCE_URL if the source bucket is different from the target bucket
        if ($sourceBucket !== $targetBucket) {
            $sourceUrl = $this->ask('Enter the AWS Source URL');
        } else {
            $sourceUrl = env('AWS_SOURCE_URL');
        }

        // Display cloning information and proceed with the operation
        $this->info("Cloning S3 from '{$sourceBucket}' (Source URL: {$sourceUrl}) to '{$targetBucket}'...");

        // Use PorterService to clone the S3 bucket
        $this->porterService->cloneS3($sourceBucket, $targetBucket, $sourceUrl);

        $this->info("S3 clone completed successfully.");
    }
}
