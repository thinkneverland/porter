<?php

namespace ThinkNeverland\Porter\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckFileExistence;

class CloneS3Command extends Command
{
    protected $signature = 'porter:clone-s3';

    protected $description = 'Clone contents from a source S3 bucket to the target S3 bucket. Skips files that already exist in the target.';

    protected $batchSize = 100;

    protected $existenceCache = [];

    protected $failedFiles = [];

    protected $retryAttempts = 3;

    public function handle()
    {
        $this->info('Starting S3 bucket cloning operation...');

        try {
            $config = $this->getS3Config();

            if (!$this->validateConfig($config)) {
                return 1;
            }

            // Test connections before proceeding
            if (!$this->testConnections($config)) {
                return 1;
            }

            $this->cloneS3Bucket(...array_values($config));

            if (!empty($this->failedFiles)) {
                $this->warn("\nThe following files failed to process:");

                foreach ($this->failedFiles as $file => $error) {
                    $this->error("- {$file}: {$error}");
                }
            }

            $this->info("\nS3 bucket cloning completed!");

            return 0;
        } catch (Exception $e) {
            $this->error("Fatal error during cloning operation: " . $e->getMessage());

            return 1;
        }
    }

    protected function testConnections($config)
    {
        $this->info('Testing S3 connections...');

        try {
            $sourceStorage = $this->configureStorage('s3_source', [
                'bucket'   => $config['sourceBucket'],
                'region'   => $config['sourceRegion'],
                'key'      => $config['sourceAccessKey'],
                'secret'   => $config['sourceSecretKey'],
                'url'      => $config['sourceUrl'],
                'endpoint' => $config['sourceEndpoint'],
            ]);

            $targetStorage = $this->configureStorage('s3_target', [
                'bucket'   => $config['targetBucket'],
                'region'   => $config['targetRegion'],
                'key'      => $config['targetAccessKey'],
                'secret'   => $config['targetSecretKey'],
                'url'      => $config['targetUrl'],
                'endpoint' => $config['targetEndpoint'],
            ]);

            // Test source bucket access
            $sourceStorage->exists('test-connection');
            $this->info('✓ Source bucket connection successful');

            // Test target bucket access
            $targetStorage->exists('test-connection');
            $this->info('✓ Target bucket connection successful');

            return true;
        } catch (Exception $e) {
            $this->error('Failed to establish S3 connections:');
            $this->error($e->getMessage());

            return false;
        }
    }

    protected function batchExistenceCheck($files, $targetStorage)
    {
        $results = [];

        foreach ($files as $file) {
            if (isset($this->existenceCache[$file])) {
                $results[$file] = $this->existenceCache[$file];

                continue;
            }

            try {
                $exists                      = $targetStorage->exists($file);
                $this->existenceCache[$file] = $exists;
                $results[$file]              = $exists;
            } catch (UnableToCheckFileExistence $e) {
                // If we can't check existence, assume file doesn't exist and try to copy
                $this->warn("Unable to check if file exists: {$file} - will attempt to copy");
                $this->existenceCache[$file] = false;
                $results[$file]              = false;
            } catch (Exception $e) {
                // For any other errors, also assume file doesn't exist and try to copy
                $this->warn("Error checking file existence: {$file} - {$e->getMessage()} - will attempt to copy");
                $this->existenceCache[$file] = false;
                $results[$file]              = false;
            }
        }

        return $results;
    }

    protected function processBatch($files, $sourceStorage, $targetStorage)
    {
        // Pre-check existence for the batch
        $existenceChecks = $this->batchExistenceCheck($files, $targetStorage);

        foreach ($files as $file) {
            if ($existenceChecks[$file]) {
                $this->line("Skipping: {$file} (already exists in target)");

                continue;
            }

            $attempts = 0;
            while ($attempts < $this->retryAttempts) {
                try {
                    // Get MIME type and visibility using newer methods
                    try {
                        $mimeType   = $sourceStorage->mimeType($file);
                        $visibility = $sourceStorage->visibility($file);
                    } catch (Exception $e) {
                        // If metadata fetch fails, use default values
                        $mimeType   = 'application/octet-stream';
                        $visibility = 'private';
                        $this->warn("Could not fetch metadata for {$file} - using defaults");
                    }

                    // Get and put the file
                    $contents = $sourceStorage->get($file);
                    $targetStorage->put($file, $contents, [
                        'ContentType' => $mimeType,
                        'visibility'  => $visibility,
                    ]);

                    $this->existenceCache[$file] = true;
                    $this->line("Cloned: {$file}");

                    break;
                } catch (Exception $e) {
                    $attempts++;

                    if ($attempts === $this->retryAttempts) {
                        $this->failedFiles[$file] = $e->getMessage();
                        $this->error("Failed to clone {$file} after {$this->retryAttempts} attempts: " . $e->getMessage());
                    } else {
                        $this->warn("Retry {$attempts}/{$this->retryAttempts} for {$file}");
                        usleep(500000); // 0.5 second delay before retry
                    }
                }
            }
        }
    }

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

        try {
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
        } catch (Exception $e) {
            $this->error("Error while processing files: " . $e->getMessage());

            throw $e;
        }
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
     * Clean up temporary storage configurations
     */
    public function __destruct()
    {
        // Clean up temporary disk configurations
        config(['filesystems.disks.s3_source' => null]);
        config(['filesystems.disks.s3_target' => null]);
    }
}
