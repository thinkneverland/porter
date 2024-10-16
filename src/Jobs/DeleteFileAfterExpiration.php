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

    public function __construct(string $filePath, string $disk = 'public')
    {
        $this->filePath = $filePath;
        $this->disk = $disk;
    }

    public function handle()
    {
        // Delete the file after the expiration time
        if (Storage::disk($this->disk)->exists($this->filePath)) {
            Storage::disk($this->disk)->delete($this->filePath);
        }
    }
}
