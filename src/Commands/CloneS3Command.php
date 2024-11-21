<?php

namespace ThinkNeverland\Porter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CloneS3Command extends Command
{
    protected $signature = 'porter:clone-s3';

    protected $description = 'Clone contents from a source S3 bucket to the target S3 bucket. Skips files that already exist in the target.';

    /**
     * Batch size for processing files
     */
    protected $batchSize = 100;

    /**
     * Memory cache for file existence checks
     */
    protected $existenceCache = [];

    public function handle()
    {
        $config = $this->getS3Config();

        if (!$this->validateConfig($config)) {
            return 1;
        }

        $this->cloneS3Bucket(...array_values($config));

        $this->info("S3 bucket cloning from {$config['sourceBucket']} to {$config['targetBucket']} completed successfully!");

        return 0;
    }

    /**
     * Get S3 configuration
     */
    protected function getS3Config()
    {
        return [
            'sourceBucket'    => config('porter.s3.source_bucket'),
            'sourceRegion'    => config('porter.s3.source_region'),
            'sourceAccessKey' => config('porter.s3.source_access_key'),
            'sourceSecretKey' => config('porter.s3.source_secret_key'),
            'sourceUrl'       => config('porter.s3.source_url'),
            'sourceEndpoint'  => config('porter.s3.source_endpoint'),
            'targetBucket'    => config('porter.s3.target_bucket'),
            'targetRegion'    => config('porter.s3.target_region'),
            'targetAccessKey' => config('porter.s3.target_access_key'),
            'targetSecretKey' => config('porter.s3.target_secret_key'),
            'targetUrl'       => config('porter.s3.target_url'),
            'targetEndpoint'  => config('porter.s3.target_endpoint'),
        ];
    }

    protected function validateConfig($config)
    {
        $requiredFields = [
            'sourceBucket'    => 'S3 source bucket',
            'sourceRegion'    => 'S3 source region',
            'sourceAccessKey' => 'S3 source access key',
            'sourceSecretKey' => 'S3 source secret key',
            'targetBucket'    => 'S3 target bucket',
            'targetRegion'    => 'S3 target region',
            'targetAccessKey' => 'S3 target access key',
            'targetSecretKey' => 'S3 target secret key',
        ];

        $missingFields = [];

        foreach ($requiredFields as $field => $label) {
            if (empty($config[$field])) {
                $missingFields[] = $label;
            }
        }

        if (!empty($missingFields)) {
            $this->error('Missing required S3 configuration:');

            foreach ($missingFields as $field) {
                $this->line("- {$field}");
            }

            return false;
        }

        return true;
    }

    /**
     * Clone S3 bucket with optimized batch processing
     */
    protected function cloneS3Bucket(
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
    ) {
        $sourceStorage = $this->configureStorage('s3_source', [
            'bucket'   => $sourceBucket,
            'region'   => $sourceRegion,
            'key'      => $sourceAccessKey,
            'secret'   => $sourceSecretKey,
            'url'      => $sourceUrl,
            'endpoint' => $sourceEndpoint,
        ]);

        $targetStorage = $this->configureStorage('s3_target', [
            'bucket'   => $targetBucket,
            'region'   => $targetRegion,
            'key'      => $targetAccessKey,
            'secret'   => $targetSecretKey,
            'url'      => $targetUrl,
            'endpoint' => $targetEndpoint,
        ]);

        $files          = $sourceStorage->allFiles();
        $totalFiles     = count($files);
        $processedFiles = 0;
        $filesBatch     = [];

        $this->output->progressStart($totalFiles);

        foreach ($files as $file) {
            $filesBatch[] = $file;

            if (count($filesBatch) >= $this->batchSize) {
                $this->processBatch($filesBatch, $sourceStorage, $targetStorage);
                $processedFiles += count($filesBatch);
                $this->output->progressAdvance(count($filesBatch));
                $filesBatch = [];
            }
        }

        // Process remaining files
        if (!empty($filesBatch)) {
            $this->processBatch($filesBatch, $sourceStorage, $targetStorage);
            $this->output->progressAdvance(count($filesBatch));
        }

        $this->output->progressFinish();
    }

    /**
     * Configure storage disk
     */
    protected function configureStorage($diskName, $config)
    {
        // Ensure required fields are present
        $diskConfig = [
            'driver' => 's3',
            'bucket' => $config['bucket'],
            'region' => $config['region'],
            'key'    => $config['key'],
            'secret' => $config['secret'],
        ];

        // Add optional configurations if they exist
        if (!empty($config['url'])) {
            $diskConfig['url'] = $config['url'];
        }

        if (!empty($config['endpoint'])) {
            $diskConfig['endpoint'] = $config['endpoint'];
        }

        // Configure the disk
        config(["filesystems.disks.{$diskName}" => $diskConfig]);

        try {
            return Storage::disk($diskName);
        } catch (\Exception $e) {
            $this->error("Failed to configure {$diskName} storage:");
            $this->error($e->getMessage());

            throw $e;
        }
    }

    /**
     * Process a batch of files
     */
    protected function processBatch($files, $sourceStorage, $targetStorage)
    {
        // Pre-check existence for the batch
        $existenceChecks = $this->batchExistenceCheck($files, $targetStorage);

        foreach ($files as $file) {
            if ($existenceChecks[$file]) {
                $this->info("Skipping: {$file} (already exists in target)");

                continue;
            }

            try {
                // Get file metadata
                $metadata = $sourceStorage->getMetadata($file);
                $contents = $sourceStorage->get($file);

                // Put file with metadata
                $targetStorage->put($file, $contents, [
                    'ContentType' => $metadata['type'] ?? 'application/octet-stream',
                    'visibility'  => $metadata['visibility'] ?? 'private',
                ]);

                $this->existenceCache[$file] = true;
                $this->info("Cloned: {$file}");
            } catch (\Exception $e) {
                $this->error("Failed to clone {$file}: " . $e->getMessage());

                continue;
            }
        }
    }

    /**
     * Batch check file existence
     */
    protected function batchExistenceCheck($files, $targetStorage)
    {
        $results = [];

        foreach ($files as $file) {
            if (isset($this->existenceCache[$file])) {
                $results[$file] = $this->existenceCache[$file];
            } else {
                $exists                      = $targetStorage->exists($file);
                $this->existenceCache[$file] = $exists;
                $results[$file]              = $exists;
            }
        }

        return $results;
    }

    /**
     * Clean up temporary storage configurations
     */
    public function __destruct()
    {
        // Clean up temporary disk configurations
        config(['filesystems.disks.s3_source' => null]);
        config(['filesystems.disks.s3_target' => null]);
    }
}
