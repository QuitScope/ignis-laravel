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
            // Off: the framework's `/storage/{path}` route would shadow StorageFileController's
            // `/storage/{area}/{file}`. Private files go through that controller only.
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        // Private; served by StorageFileController to signed-in users only. Flat: generated names, no folders.
        'profile-pictures' => [
            'driver' => 'local',
            'root' => storage_path('app/private/profile-pictures'),
            'throw' => false,
            'report' => false,
        ],

        // Private; editor/{public_id}.pdf and legacy/{name}. Never served directly, only by controllers.
        'documents' => [
            'driver' => 'local',
            'root' => storage_path('app/private/documents'),
            'throw' => false,
            'report' => false,
        ],

        // Private; the logo, flat, served by BrandingController (public route, current file only).
        'branding' => [
            'driver' => 'local',
            'root' => storage_path('app/private/branding'),
            'throw' => false,
            'report' => false,
        ],

        // Private; knowledge base images, flat, served by KbImageController (public only with knowledgebase.public_access).
        'kb-images' => [
            'driver' => 'local',
            'root' => storage_path('app/private/kb-images'),
            'throw' => false,
            'report' => false,
        ],

        // Private; mail attachments, flat `mail-<message>-<16hex>.<ext>`, served only by the mail AttachmentController
        // to participants of the mail. Cleaned by `mail:cleanup` (not by ignis:storage:cleanup).
        'mail-attachments' => [
            'driver' => 'local',
            'root' => storage_path('app/private/mail-attachments'),
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
