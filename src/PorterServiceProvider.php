<?php

namespace ThinkNeverland\Porter;

use Illuminate\Support\Facades\{Crypt, Route, Storage};
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
        Route::get('/download/{file}', function ($file) {
            try {
                $decryptedFileName = Crypt::decryptString($file);
                return Storage::disk('public')->download($decryptedFileName);
            } catch (\Exception $e) {
                return response()->json(['error' => 'File not found.'], 404);
            }
        })->name('porter.download');
    }
}
