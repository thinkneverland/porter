<?php

namespace ThinkNeverland\Porter\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Storage;

class DeleteFileAfterExpiration implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected $filePath;

    protected $disk;

    /**
     * Number of retries
     */
    public $tries = 3;

    /**
     * Retry delay in seconds
     */
    public $backoff = [30, 60, 120];

    public function __construct(string $filePath, string $disk)
    {
        $this->filePath = $filePath;
        $this->disk     = $disk;
    }

    /**
     * Execute the job with improved error handling and logging
     */
    public function handle()
    {
        try {
            $storage = Storage::disk($this->disk);

            // Check if file exists before attempting deletion
            if (!$storage->exists($this->filePath)) {
                info("File already deleted or not found: {$this->filePath}");

                return;
            }

            // Attempt deletion
            if (!$storage->delete($this->filePath)) {
                throw new Exception("Failed to delete file: {$this->filePath}");
            }

            info("Successfully deleted expired file: {$this->filePath}");
        } catch (Exception $e) {
            report($e);

            throw $e;
        }
    }

    /**
     * Handle a job failure
     */
    public function failed(Exception $exception)
    {
        report("Failed to delete expired file {$this->filePath}: " . $exception->getMessage());
    }
}
