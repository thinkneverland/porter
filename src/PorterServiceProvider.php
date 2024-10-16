<?php

namespace ThinkNeverland\Porter;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ThinkNeverland\Porter\Commands\{CloneS3Command, ExportCommand, ImportCommand, InstallCommand};

class PorterServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/porter.php', 'porter');
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/porter.php' => config_path('porter.php'),
            ], 'config');

            // Publish the PorterPolicy to the policies directory
            $this->publishes([
                __DIR__ . '/../src/Policies/PorterPolicy.php' => app_path('Policies/PorterPolicy.php'),
            ], 'policies');

            $this->commands([
                ExportCommand::class,
                ImportCommand::class,
                CloneS3Command::class,
                InstallCommand::class,
            ]);
        }

        // Register the download routes
        $this->registerRoutes();
    }

    protected function registerRoutes()
    {
        Route::middleware('web')
            ->group(function () {
                Route::get('porter/download/{file}', function ($encryptedFileName) {
                    try {
                        // Attempt to decrypt the file name
                        $filePath = \Crypt::decryptString($encryptedFileName);
                        $storagePath = storage_path('app/' . $filePath);

                        // Check if the file exists in storage
                        if (!file_exists($storagePath)) {
                            return response()->json(['error' => 'File not found.'], 404);
                        }

                        // Serve the file for download
                        return response()->download($storagePath)->deleteFileAfterSend(true);

                    } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                        // If decryption fails, log the error and return a 403
                        \Log::error('File decryption failed: ' . $e->getMessage());
                        return response()->json(['error' => 'Invalid file request.'], 403);
                    } catch (\Exception $e) {
                        // Catch any other errors and log them
                        \Log::error('File download error: ' . $e->getMessage());
                        return response()->json(['error' => 'An error occurred.'], 500);
                    }
                })->name('porter.download');
            });
    }
}
