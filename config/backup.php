<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backups (Phase 16)
    |--------------------------------------------------------------------------
    |
    | Backups are written into the private filesystem (storage/app/private by
    | default) — never into public storage — and are chmod 0600. Each backup
    | is a timestamped directory containing a consistent database snapshot,
    | an optional copy of private user files, and a manifest with a SHA-256
    | checksum used for verification.
    |
    */

    // Filesystem disk the backups are written to ('local' = private storage).
    'disk' => env('BACKUP_DISK', 'local'),

    // Directory (relative to the disk root) holding backups.
    'path' => env('BACKUP_PATH', 'backups'),

    // How many completed backups to retain. Oldest are pruned first.
    'retention' => (int) env('BACKUP_RETENTION', 14),

    // Also snapshot storage/app/private (dispute evidence, identity docs,
    // avatars, exports). Never includes the backups directory itself.
    'include_private_files' => env('BACKUP_INCLUDE_PRIVATE_FILES', true),

    // Private files copied per backup.
    'private_dir' => storage_path('app/private'),

    // Checksum algorithm recorded in the manifest.
    'checksum' => 'sha256',

    // Run "PRAGMA integrity_check" against every SQLite snapshot after it is
    // written. A failing check aborts the backup (never silent success).
    'integrity_check' => env('BACKUP_INTEGRITY_CHECK', true),

    // Notify platform admins (Phase 11 NotificationService) when a backup
    // fails or fails verification.
    'notify_admins' => env('BACKUP_NOTIFY_ADMINS', true),
];
