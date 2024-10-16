<?php

namespace ThinkNeverland\Porter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class DeleteFileAfterExpiration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $filePath;
    protected $disk;

    /**
     * Create a new job instance.
     *
     * @param string $filePath
     * @param string $disk
     */
    public function __construct(string $filePath, string $disk)
    {
        $this->filePath = $filePath;
        $this->disk = $disk;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Check if the file exists and delete it
        if (Storage::disk($this->disk)->exists($this->filePath)) {
            Storage::disk($this->disk)->delete($this->filePath);
        }
    }
}
