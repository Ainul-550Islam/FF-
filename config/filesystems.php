<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        |------------------------------------------------------------------
        | Offsite backup target (GAP-10 C)
        |------------------------------------------------------------------
        |
        | The disk the backup engine copies the encrypted snapshot to and
        | verifies by checksum. The default is a plain local path so a host
        | with no object-storage package and no credentials still has an
        | offsite copy (attached volume, NFS/CIFS mount, rclone remote) —
        | point BACKUP_OFFSITE_ROOT at a path that outlives the host.
        |
        | Set BACKUP_OFFSITE_DRIVER=s3 to use object storage; that needs
        | `composer require league/flysystem-aws-s3-v3` plus the keys below.
        | (No automated check enforces this yet — verify by hand that
        | BACKUP_OFFSITE_REQUIRED=true always ships with a bucket. GAP-R7:
        | docs/RUNTIME_EVIDENCE_RUNBOOK.md.)
        |
        */

        'backup-offsite' => [
            'driver' => env('BACKUP_OFFSITE_DRIVER', 'local'),
            'root' => env('BACKUP_OFFSITE_ROOT', storage_path('app/offsite-backups')),
            'key' => env('BACKUP_OFFSITE_KEY', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('BACKUP_OFFSITE_SECRET', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('BACKUP_OFFSITE_REGION', env('AWS_DEFAULT_REGION')),
            'bucket' => env('BACKUP_OFFSITE_BUCKET', env('AWS_BUCKET')),
            'endpoint' => env('BACKUP_OFFSITE_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('BACKUP_OFFSITE_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
