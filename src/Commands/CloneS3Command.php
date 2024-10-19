<?php

namespace ThinkNeverland\Porter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CloneS3Command extends Command
{
    // Command signature for cloning S3 buckets.
    protected $signature = 'porter:clone-s3';

    // Command description for Artisan.
    protected $description = 'Clone contents from a source S3 bucket to the target S3 bucket. Skips files that already exist in the target.';

    /**
     * Handle the command execution.
     */
    public function handle()
    {
        // Get source and target S3 bucket credentials from config.
        $sourceBucket    = config('porter.s3.source_bucket');
        $sourceRegion    = config('porter.s3.source_region');
        $sourceAccessKey = config('porter.s3.source_access_key');
        $sourceSecretKey = config('porter.s3.source_secret_key');
        $sourceUrl       = config('porter.s3.source_url');
        $sourceEndpoint  = config('porter.s3.source_endpoint');  // Adding the endpoint here

        $targetBucket    = config('porter.s3.target_bucket');
        $targetRegion    = config('porter.s3.target_region');
        $targetAccessKey = config('porter.s3.target_access_key');
        $targetSecretKey = config('porter.s3.target_secret_key');
        $targetUrl       = config('porter.s3.target_url');
        $targetEndpoint  = config('porter.s3.target_endpoint');  // Adding the endpoint here

        // Ensure the source and target buckets are set in the configuration.
        if (!$sourceBucket || !$targetBucket) {
            $this->error('S3 source or target bucket configuration is missing in the .env file.');

            return 1;
        }

        // Clone the contents from the source bucket to the target bucket.
        $this->cloneS3Bucket(
            $sourceBucket,
            $sourceRegion,
            $sourceAccessKey,
            $sourceSecretKey,
            $sourceUrl,
            $sourceEndpoint,
            $targetBucket,
            $targetRegion,
            $targetAccessKey,
            $targetSecretKey,
            $targetUrl,
            $targetEndpoint
        );

        // Output success message.
        $this->info("S3 bucket cloning from {$sourceBucket} to {$targetBucket} completed successfully!");

        return 0;
    }

    /**
     * Clone the contents of the source bucket to the target bucket.
     * Skip files that already exist in the target bucket.
     *
     * @param string $sourceBucket The source S3 bucket name.
     * @param string $sourceRegion The source S3 bucket region.
     * @param string $sourceAccessKey The access key for the source bucket.
     * @param string $sourceSecretKey The secret key for the source bucket.
     * @param string $sourceUrl The URL for the source S3 bucket.
     * @param string $sourceEndpoint The custom endpoint for the source S3 bucket.
     * @param string $targetBucket The target S3 bucket name.
     * @param string $targetRegion The target S3 bucket region.
     * @param string $targetAccessKey The access key for the target bucket.
     * @param string $targetSecretKey The secret key for the target bucket.
     * @param string $targetUrl The URL for the target S3 bucket.
     * @param string $targetEndpoint The custom endpoint for the target S3 bucket.
     */
    protected function cloneS3Bucket($sourceBucket, $sourceRegion, $sourceAccessKey, $sourceSecretKey, $sourceUrl, $sourceEndpoint, $targetBucket, $targetRegion, $targetAccessKey, $targetSecretKey, $targetUrl, $targetEndpoint)
    {
        // Dynamically configure the source S3 disk
        config(['filesystems.disks.s3' => array_merge(config('filesystems.disks.s3'), [
            'bucket'   => $sourceBucket,
            'region'   => $sourceRegion,
            'key'      => $sourceAccessKey,
            'secret'   => $sourceSecretKey,
            'url'      => $sourceUrl,
            'endpoint' => $sourceEndpoint, // Use the custom endpoint for the source
        ])]);

        $sourceStorage = Storage::disk('s3');

        // Dynamically configure the target S3 disk
        config(['filesystems.disks.s3' => array_merge(config('filesystems.disks.s3'), [
            'bucket'   => $targetBucket,
            'region'   => $targetRegion,
            'key'      => $targetAccessKey,
            'secret'   => $targetSecretKey,
            'url'      => $targetUrl,
            'endpoint' => $targetEndpoint, // Use the custom endpoint for the target
        ])]);

        $targetStorage = Storage::disk('s3');

        // Get all files from the source bucket.
        $files = $sourceStorage->allFiles();

        // Copy each file from the source bucket to the target bucket, but skip if it already exists in the target.
        foreach ($files as $file) {
            // Check if the file already exists in the target bucket.
            if ($targetStorage->exists($file)) {
                $this->info("Skipping: {$file} (already exists in target)");

                continue; // Skip this file
            }

            // If the file does not exist, clone it.
            $targetStorage->put($file, $sourceStorage->get($file));
            $this->info("Cloned: {$file}");
        }
    }
}
