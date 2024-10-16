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

During installation, you'll be prompted to provide S3 credentials for both primary and secondary S3 buckets. These will be stored in your `.env` file if not already set.

## Configuration

The Porter package uses a configuration file located at `config/porter.php` after running the install command.

If you wish to configure Porter manually instead of using the install command, you can do so by editing the `config/porter.php` file.

### S3 Storage Settings

Primary and secondary S3 buckets can be configured in the `porter.php` config file:

```php
'primaryS3' => [
    'bucket' => env('AWS_BUCKET'),
    'region' => env('AWS_DEFAULT_REGION'),
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'url' => env('AWS_URL'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
],
'sourceS3' => [
    'bucket' => env('AWS_SOURCE_BUCKET'),
    'region' => env('AWS_SOURCE_REGION'),
    'key' => env('AWS_SOURCE_ACCESS_KEY_ID'),
    'secret' => env('AWS_SOURCE_SECRET_ACCESS_KEY'),
    'url' => env('AWS_SOURCE_URL'),
    'endpoint' => env('AWS_SOURCE_ENDPOINT'),
    'use_path_style' => env('AWS_SOURCE_USE_PATH_STYLE_ENDPOINT', false),
],
```

---

## Usage

### Export Command
The `porter:export` command allows exporting the database to an SQL file. Here are the flags you can use with this command:

#### Usage:
```bash
php artisan porter:export {file} [options]
```

#### Flags:
- `file`: **(Required)** The path where the exported SQL file will be saved. This is a mandatory argument.
- `--download`: **(Optional)** If this flag is provided, the exported SQL file will be available for download from the S3 bucket.
- `--drop-if-exists`: **(Optional)** Include the `DROP TABLE IF EXISTS` statement for all tables in the export. This ensures that tables are dropped before creating them again during an import.
- `--keep-if-exists`: **(Optional)** Leave `IF EXISTS` statements in the export process for table creation.

#### Example:
```bash
php artisan porter:export export.sql --download --drop-if-exists
```
This command exports the database to a file named `export.sql`, includes the `DROP TABLE IF EXISTS` statement, and downloads the file from S3.

---

### Import Command
The `porter:import` command imports an SQL file into the database. This command does not have any additional flags.

#### Usage:
```bash
php artisan porter:import {file}
```

#### Flags:
- `file`: **(Required)** The path to the SQL file that will be imported into the database.

#### Example:
```bash
php artisan porter:import /path/to/database.sql
```
This command imports the SQL file located at `/path/to/database.sql` into the database.

---

### Clone S3 Command
The `porter:clone-s3` command allows cloning files from one S3 bucket to another. This command does not have any additional flags.

#### Usage:
```bash
php artisan porter:clone-s3
```

#### Example:
```bash
php artisan porter:clone-s3
```
This command clones all files from the source S3 bucket (as defined in your environment settings) to the target S3 bucket.

---

## Exporting Database

```bash
php artisan porter:export
```

This will export the database and store it either locally or on S3, based on your configuration.

## Importing Database

You can import a database from a file by specifying the file path:

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

The `clone-s3` command allows you to clone content between S3 buckets:

```bash
php artisan porter:clone-s3
```

This will clone files from the source bucket to the target bucket as defined in your configuration.

## Model-based Configuration

Instead of using the config file, randomization and keeping specific rows for tables are now done on the model level.

```php
// Inside the User model:
// These will be randomized during Porter export/import operations.
protected static $omittedFromPorter = ['email', 'name'];
// This will key the rows during Porter export/import operations.
protected static $keepForPorter = [1, 2, 3];
// This will ensure the model is ignored during Porter export/import operations.
public static $ignoreFromPorter = true;

```

You can also ignore specific tables directly in the model to prevent them from being exported.
