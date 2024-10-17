<?php

namespace ThinkNeverland\Porter;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use ThinkNeverland\Porter\Commands\{CloneS3Command, ExportCommand, ImportCommand, InstallCommand};

class PorterServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Merge default configuration file
        $this->mergeConfigFrom(__DIR__ . '/../config/porter.php', 'porter');
    }

    public function boot()
    {
        // Publish configuration file and other assets during installation
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/porter.php' => config_path('porter.php'),
            ], 'config');

            // Publish PorterPolicy for defining access policies
            $this->publishes([
                __DIR__ . '/../src/Policies/PorterPolicy.php' => app_path('Policies/PorterPolicy.php'),
            ], 'policies');

            // Register all Porter commands
            $this->commands([
                ExportCommand::class,
                ImportCommand::class,
                CloneS3Command::class,
                InstallCommand::class,
            ]);
        }

        // Register routes for download links
        $this->registerRoutes();
    }

    /**
     * Register the download route for public and encrypted file URLs.
     */
    protected function registerRoutes()
    {
        Route::middleware('web')->group(function () {
            Route::get('/download/{file}', function ($file) {
                try {
                    // Decrypt the file name to prevent tampering
                    $decryptedFileName = decrypt($file);

                    // Ensure file is served from the correct disk
                    $disk = config('filesystems.default') === 's3' ? 's3' : 'public';

                    if (!Storage::disk($disk)->exists($decryptedFileName)) {
                        return response()->json(['error' => 'File not found.'], 404);
                    }

                    // Serve the file as a download
                    return Storage::disk($disk)->download($decryptedFileName);
                } catch (\Exception $e) {
                    return response()->json(['error' => 'Invalid file request.'], 403);
                }
            })->name('porter.download');
        });
    }
}
