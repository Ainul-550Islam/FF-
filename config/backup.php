<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backups (Phase 16 / GAP-10 C)
    |--------------------------------------------------------------------------
    |
    | Backups are written into the private filesystem (storage/app/private by
    | default) — never into public storage — and are chmod 0600. Each backup
    | is a timestamped directory containing a consistent database snapshot,
    | an optional copy of private user files, and a manifest with a SHA-256
    | checksum used for verification.
    |
    | GAP-10 C adds the disaster-recovery half: the snapshot is encrypted to
    | an age recipient before it leaves the host, a second copy is pushed to
    | an offsite disk and verified there by checksum, and both steps fail
    | closed — a backup that cannot be encrypted or copied where it was
    | supposed to go is reported FAILED, never "ok, minus the offsite copy".
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

    /*
    |--------------------------------------------------------------------------
    | Offsite copy (GAP-10 C)
    |--------------------------------------------------------------------------
    |
    | A backup that only exists on the host it protects is not a disaster
    | recovery plan. `offsite_disk` names the disk in config/filesystems.php
    | that receives the second, checksum-verified copy — 'backup-offsite'
    | ships with the application and defaults to a dependency-free local
    | mount (see BACKUP_OFFSITE_DRIVER in .env.example).
    |
    | `offsite_required` is the fail-closed switch: with it on, a backup whose
    | offsite copy did not land is a FAILED backup, so the failure shows up in
    | the monitoring that watches the backup job instead of being discovered
    | during an incident. Set it to true in production.
    |
    */

    'offsite_disk' => env('BACKUP_OFFSITE_DISK'),

    'offsite_required' => env('BACKUP_OFFSITE_REQUIRED', false),

    'offsite_prefix' => env('BACKUP_OFFSITE_PREFIX', 'backups'),

    /*
    |--------------------------------------------------------------------------
    | Encryption at rest (GAP-10 C, F-19)
    |--------------------------------------------------------------------------
    |
    | `encryption_recipient` is the age PUBLIC key the dump is encrypted to
    | before it is stored or copied anywhere. When it is set, encryption is
    | mandatory: a missing `age` binary or an unusable key aborts the run
    | rather than silently writing plaintext.
    |
    | `decryption_identity` is the age PRIVATE key a restore needs. It is
    | deliberately not stored on the web host — keep it in key escrow and
    | mount it for the duration of a restore, or pass it explicitly with
    | `php artisan ffarena:backup:restore --identity=/path/to/key`. With
    | neither present, restoring an encrypted backup fails closed with an
    | error that names both options.
    |
    */

    'encryption_recipient' => env('BACKUP_ENCRYPTION_RECIPIENT'),

    'decryption_identity' => env('BACKUP_DECRYPTION_IDENTITY'),

];
