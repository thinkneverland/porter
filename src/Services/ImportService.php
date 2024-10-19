<?php

namespace ThinkNeverland\Porter\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportService
{
    /**
     * Import the SQL file into the database.
     *
     * @param string $path The path to the SQL file (can be local or S3).
     */
    public function importDatabase($path)
    {
        // If the path is an S3 URL, download the file first.
        if (str_starts_with($path, 's3://')) {
            $path = $this->downloadFromS3($path);
        }

        // Run the MySQL import command to restore the database.
        $command = "mysql --user=".env('DB_USERNAME')." --password=".env('DB_PASSWORD')." --host=".env('DB_HOST')." ".env('DB_DATABASE')." < $path";
        exec($command);
    }

    /**
     * Download the SQL file from S3 and save it locally.
     *
     * @param string $path The S3 path to the SQL file.
     * @return string The local path to the downloaded SQL file.
     */
    protected function downloadFromS3($path)
    {
        // Define the local path where the SQL file will be temporarily saved.
        $localPath = storage_path("app/temp.sql");

        // Download the file contents from S3.
        $contents = Storage::disk('s3')->get(basename($path));

        // Save the file locally.
        file_put_contents($localPath, $contents);

        return $localPath;
    }
}
