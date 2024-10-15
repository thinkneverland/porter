<?php

namespace ThinkNeverland\Porter\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'porter:install';
    protected $description = 'Install Porter and publish configuration, and optionally configure S3 instances.';

    public function handle()
    {
        // Publish configuration
        $this->call('vendor:publish', [
            '--tag' => 'config',
            '--provider' => "ThinkNeverland\Porter\PorterServiceProvider"
        ]);

        // Configure S3 settings
        $this->configureS3();

        $this->info('Porter installed successfully.');
    }

    /**
     * Configure S3 settings and optionally configure a source instance.
     */
    protected function configureS3()
    {
        // Prompt for the first S3 credentials if they are missing
        $this->promptS3Credentials('AWS_ACCESS_KEY_ID', 'Enter your AWS Access Key ID');
        $this->promptS3Credentials('AWS_SECRET_ACCESS_KEY', 'Enter your AWS Secret Access Key');
        $this->promptS3Credentials('AWS_DEFAULT_REGION', 'Enter your AWS Region');
        $this->promptS3Credentials('AWS_BUCKET', 'Enter your S3 Bucket name');
        $this->promptS3Credentials('AWS_URL', 'Enter your AWS URL');
        $this->promptS3Credentials('AWS_ENDPOINT', 'Enter your AWS Endpoint', true);

        // Prompt for the source set of S3 credentials if required
        if ($this->confirm('Would you like to configure a source S3 instance?')) {
            $this->promptS3Credentials('AWS_SOURCE_ACCESS_KEY_ID', 'Enter your SOURCE AWS Access Key ID');
            $this->promptS3Credentials('AWS_SOURCE_SECRET_ACCESS_KEY', 'Enter your SOURCE AWS Secret Access Key');
            $this->promptS3Credentials('AWS_SOURCE_DEFAULT_REGION', 'Enter your SOURCE AWS Region');
            $this->promptS3Credentials('AWS_SOURCE_BUCKET', 'Enter your SOURCE S3 Bucket name');
            $this->promptS3Credentials('AWS_SOURCE_URL', 'Enter your SOURCE AWS URL');
            $this->promptS3Credentials('AWS_SOURCE_ENDPOINT', 'Enter your SOURCE AWS Endpoint', true);
            $this->promptS3Credentials('AWS_SOURCE_USE_PATH_STYLE_ENDPOINT', 'Use path-style access for the SOURCE AWS S3 instance?', false, true);
        } elseif ($this->confirm('Is the source instance the same as the first, but with a different bucket?')) {
            $this->copyS3CredentialsFromPrimaryToSource();
        }
    }

    /**
     * Helper to prompt for S3 credentials and store them in the .env file if missing.
     */
    protected function promptS3Credentials($envKey, $message, $optional = false, $isBoolean = false)
    {
        if (!$this->isEnvSet($envKey)) {
            $value = $isBoolean ? $this->confirm($message) ? 'true' : 'false' : $this->ask($message);
            if ($value || $optional) {
                $this->updateEnvFile($envKey, $value);
            }
        }
    }

    /**
     * Helper to copy primary S3 credentials to source if requested.
     */
    protected function copyS3CredentialsFromPrimaryToSource()
    {
        $this->updateEnvFile('AWS_SOURCE_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID'));
        $this->updateEnvFile('AWS_SOURCE_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY'));
        $this->updateEnvFile('AWS_SOURCE_DEFAULT_REGION', env('AWS_DEFAULT_REGION'));
        $this->updateEnvFile('AWS_SOURCE_URL', env('AWS_URL'));
        $this->updateEnvFile('AWS_SOURCE_ENDPOINT', env('AWS_ENDPOINT'));
        $this->updateEnvFile('AWS_SOURCE_BUCKET', $this->ask('Enter the SOURCE S3 Bucket name'));
        $this->updateEnvFile('AWS_SOURCE_USE_PATH_STYLE_ENDPOINT', 'false'); // default to false if copied
    }

    /**
     * Helper function to check if a given environment variable is set.
     */
    protected function isEnvSet(string $key): bool
    {
        return !empty(env($key));
    }

    /**
     * Helper function to update the .env file.
     */
    protected function updateEnvFile(string $key, string $value): void
    {
        $envPath = base_path('.env');
        if (!preg_match("/^{$key}=/m", file_get_contents($envPath))) {
            file_put_contents($envPath, "\n{$key}={$value}", FILE_APPEND);
        }
    }
}
