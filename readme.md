
# Porter Package Documentation

## Overview
The Porter package allows for exporting, importing, and cloning of database and S3 bucket content, providing flexible ways to manage data for your Laravel applications.

## Installation

You can install the package via composer:

```bash
composer require thinkneverland/porter
```

### Run the install command

This command will publish the configuration file, allow you to set S3 credentials, and apply necessary configurations.

```bash
php artisan porter:install
```

During installation, you'll be prompted to provide S3 credentials for both primary and alternative (secondary) S3 buckets. These will be stored in your `.env` file if not already set.

## Configuration

The Porter package uses a configuration file located at `config/porter.php` after running the install command.

If you wish to configure Porter manually instead of using the install command, you can do so by editing the `config/porter.php` file.

### S3 Storage Settings for Cloning

Primary and alternative S3 buckets for cloning operations can be configured in the `porter.php` config file:

```php
<?php

return [
    's3' => [
        // Target bucket (used only for cloning operations)
        'target_bucket'     => env('AWS_BUCKET'),
        'target_region'     => env('AWS_DEFAULT_REGION'),
        'target_access_key' => env('AWS_ACCESS_KEY_ID'),
        'target_secret_key' => env('AWS_SECRET_ACCESS_KEY'),
        'target_url'        => env('AWS_URL'),
        'target_endpoint'   => env('AWS_ENDPOINT', null),  // Endpoint for target (optional)

        // Source bucket (used only for cloning operations)
        'source_bucket'     => env('AWS_SOURCE_BUCKET'),
        'source_region'     => env('AWS_SOURCE_REGION'),
        'source_access_key' => env('AWS_SOURCE_ACCESS_KEY_ID'),
        'source_secret_key' => env('AWS_SOURCE_SECRET_ACCESS_KEY'),
        'source_url'        => env('AWS_SOURCE_URL'),
        'source_endpoint'   => env('AWS_SOURCE_ENDPOINT', null),  // Endpoint for source (optional)
    ],

    // Alternate S3 Export Configuration
    'alt_s3' => [
        'enabled'    => env('EXPORT_AWS_ENABLED', false),
        'bucket'     => env('EXPORT_AWS_BUCKET', null),
        'region'     => env('EXPORT_AWS_REGION', null),
        'access_key' => env('EXPORT_AWS_ACCESS_KEY_ID', null),
        'secret_key' => env('EXPORT_AWS_SECRET_ACCESS_KEY', null),
        'url'        => env('EXPORT_AWS_URL', null),
        'endpoint'   => env('EXPORT_AWS_ENDPOINT', null), // Optional for custom S3 services like MinIO
    ],

    'expiration' => env('EXPORT_AWS_EXPIRATION', 3600),  // Expiration time in seconds
];

```

Make sure to set these values in your `.env` file if you wish to enable exporting to an alternative S3 bucket.

### IAM Policy Updates for S3 Multipart Uploads

Due to the addition of multipart uploads to S3, your IAM policies need to be updated to support this functionality. Ensure the following actions are allowed in your IAM policies for both the primary and alternative S3 buckets:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:AbortMultipartUpload",
        "s3:ListMultipartUploadParts",
        "s3:PutObject",
        "s3:PutObjectAcl"
      ],
      "Resource": [
        "arn:aws:s3:::your-primary-bucket-name/*",
        "arn:aws:s3:::your-alternative-bucket-name/*"
      ]
    }
  ]
}
```

Make sure to replace `your-primary-bucket-name` and `your-alternative-bucket-name` with the appropriate bucket names. This policy ensures that Porter can manage multipart uploads effectively, which is particularly important for handling large file transfers.

## Exporting Database

The `porter:export` command exports the database into an SQL file. This can be stored locally or on the primary or alternative S3 bucket, depending on your configuration.

### Usage:
```bash
php artisan porter:export {file} [--drop-if-exists]
```

### Example with flags:
```bash
php artisan porter:export export.sql --drop-if-exists
```

#### Flags:
- `--drop-if-exists`: Includes `DROP TABLE IF EXISTS` for all tables in the export file.
- `--keep-if-exists`: Ensures that `IF EXISTS` is kept for all tables.
- After export, a temporary download link is generated that expires after 30 minutes. The exported file is deleted after expiration.

#### Example Output:
```bash
Database exported successfully to: export.sql
Download your SQL file here: http://localhost/download/export.sql
```

## Importing Database

The `porter:import` command allows you to import a database SQL file from local or S3 storage.

### Usage:
```bash
php artisan porter:import /path/to/database.sql
```

### Example: Import from S3
```bash
php artisan porter:import s3://bucket-name/path/to/database.sql
```

### Example: Import from Local File
```bash
php artisan porter:import /local/storage/path/database.sql
```

## Cloning S3 Buckets

The `clone-s3` command allows you to clone content between S3 buckets as defined in your configuration.

### Usage:
```bash
php artisan porter:clone-s3
```

This will clone files from the source bucket to the target bucket as defined in your `.env` configuration, including support for both the primary and alternative buckets.

## Model-based Configuration

Instead of using the config file, randomization and keeping specific rows for tables are now done on the model level.

```php
// Inside the User model:
// These will be randomized during Porter export/import operations.
protected static $omittedFromPorter = ['email', 'name'];
// This will keep specific rows during Porter export/import operations.
protected static $keepForPorter = [1, 2, 3];
// This will ensure the model is ignored during Porter export/import operations.
public static $ignoreFromPorter = true;
```

You can also ignore specific tables directly in the model to prevent them from being exported.

```php
// In a model:
public static $ignoreFromPorter = true;
```
