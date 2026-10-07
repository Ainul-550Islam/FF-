# GAP-06 — Disaster recovery and point-in-time recovery runbook

This runbook is the operational half of the backup code shipped in GAP-10 C. It
answers one question: **when the database is gone, corrupted or wrong, what
exactly do you type, in what order, and how do you know it worked?**

It deliberately contains no secrets. Every credential referenced here lives in
the secret store (`docs/SECRETS.md`), and the age private key is held offline in
escrow — it is never on a web host.

> **Reality check.** Everything below is code-complete and rehearsable, but a
> real restore drill on production-like infrastructure has **not** been
> performed: that is an external verification (E06 for the restore drill, E07
> for PITR) and is recorded as `PENDING` in
> `docs/GAP-09-EXTERNAL-VERIFICATION-REGISTER.md`. Do not mark those rows done
> because this document exists.

---

## 1. Recovery objectives (set these before you need them)

| Objective | Target | How it is met |
| --- | --- | --- |
| **RPO** (max data loss) | ≤ 24 h with nightly backups only; **≤ 5 min** with WAL archiving on | `WAL_ARCHIVE_ENABLED=true` with an archive destination + `archive_timeout = 300` (`deploy/postgres-init.sh`) |
| **RTO** (time to restore) | 1–4 h, dominated by restore I/O and verification | Restore → verify → re-point traffic (sections 4–6) |
| **Backup retention** | 14 completed backups | `BACKUP_RETENTION` (`config/backup.php`) |
| **Offsite copy** | Every run, checksum-verified | `BACKUP_OFFSITE_DISK` + `BACKUP_OFFSITE_REQUIRED=true` |

If `BACKUP_OFFSITE_REQUIRED=false` in production, `deploy/validate-env.py`
warns: a local-only backup means one disk failure ends the company. The shipped
production gate refuses to call a run "ok" when the offsite copy did not land
(`BackupService::copyOffsite()` returns `ok=false`, and the run is reported
failed while the verified local copy is kept).

---

## 2. What the backup actually contains

Each run creates `storage/app/private/backups/<name>/` (name =
`ffarena-YYYYmmdd-HHMMSS`) holding:

| File | Meaning |
| --- | --- |
| `database.pgdump` (pgsql) / `database.sqlite` (sqlite) | The consistent snapshot. With `BACKUP_ENCRYPTION_RECIPIENT` set the artifact is `*.age` ciphertext and **no cleartext dump is left behind**. |
| `private-files/` | A copy of `storage/app/private` — dispute evidence, identity documents, exports, avatars. |
| `manifest.json` | `db_file`, `db_sha256` (**of the ciphertext when encrypted**), `db_size`, `private_files`, `size`, `checksum`, `encrypted`, `encryption.recipient_fingerprint` + `encryption.plaintext_sha256`, and an `offsite` block (`ok`, `disk`, `path`, `sha256`, `error`) whenever an offsite target is configured. |

Invariants the code enforces:

* **Fail closed on encryption.** A configured recipient with no `age` binary
  aborts the run — a cleartext dump is never written to disk as a "fallback".
* **Fail closed on offsite.** An unreachable, unreadable or
  required-but-unconfigured target is a failed run, never an "ok" run.
* **Verified means read back.** The offsite checksum is computed from the
  object fetched back from the destination; a checksum of the local file would
  prove nothing about the copy.
* **Ciphertext is never integrity-checked as a database.** `verify()` skips the
  SQLite `integrity_check` when the artifact is encrypted, and instead reports
  `offsite_present` / `offsite_checksum`.

---

## 3. Daily verification (do this before you need it)

```bash
# 1. Take a backup and read the result. A non-zero exit or ok=false is a page.
php artisan ffarena:backup

# 2. Verify the NEWEST backup end to end (checksum, size, integrity, offsite).
php artisan ffarena:backup:verify --all

# 3. Prove the artifact is restorable WITHOUT touching production.
#    When the backup is age-encrypted (the production configuration) you must
#    supply the escrowed identity — see 3.1. Nothing here ever writes to the
#    live database.
php artisan ffarena:backup:restore ffarena-YYYYmmdd-HHMMSS --identity=/mnt/escrow/backup.key
```

### 3.1 Restoring an age-encrypted backup (finding F-19)

Encrypted backups are the strong case: a stolen artifact is unreadable without
the key. The key is held in escrow and is **never** on the web host, so the
identity is supplied for the duration of the operation:

```bash
# Without the identity the command fails closed with instructions — it will
# never tell you the archive is bad when the real problem is a missing key.
php artisan ffarena:backup:restore ffarena-20261006-140258
# Restore dry-run FAILED: Backup [...] is age-encrypted. Provide the age identity
# to read it: pass --identity=/path/to/key or set BACKUP_DECRYPTION_IDENTITY.

# With the identity:
php artisan ffarena:backup:restore ffarena-20261006-140258 \
    --identity=/mnt/escrow/backup.key
# Archive validated: the dump is readable by pg_restore.
# Integrity check passed. The live database was NOT touched.
# The decrypted copy used for this check was deleted again.
```

What the command guarantees, in order:

1. the ciphertext is decrypted to a temporary file next to the backup;
2. the plaintext is checked against the `plaintext_sha256` (and size) recorded
   in the manifest at backup time — a truncated or tampered artifact is refused
   here, before `pg_restore` ever sees it;
3. the dump is validated/restored;
4. the decrypted copy is deleted in a `finally` block, so it never survives the
   call (success, failure or exception).

A full rehearsal of the worst case — the host is gone, only the offsite copy
exists — is:

```bash
# On the recovery host, with the escrowed identity mounted:
age --decrypt -i /mnt/escrow/backup.key \
    ./backups/ffarena-20261006-140258/database.sql.age > /tmp/restore.dump
PGPASSWORD="$PGPASSWORD" pg_restore --no-owner --no-privileges \
    -d "postgresql://$DB_USER:$DB_PASSWORD@$DB_HOST:5432/ffarena_restore_drill" /tmp/restore.dump
# then compare against the source: table count, `migrations` count, and a spot
# check that wallets / ledger_entries / payouts / audit_logs survived.
```

This exact drill was executed against the shipped code and is recorded, with the
observed output, in `docs/GAP-10-FINDINGS-REGISTER.md` (finding F-19).

`verify()` returns a `checks` list — `exists`, `non_empty`, `checksum`,
`integrity` (cleartext snapshots only), `offsite_present`, `offsite_checksum`.
Any `ok=false` on any check is an incident: open one, do not "retry until
green".

Metrics already exported for alerting: `ffarena_backup_age_seconds`,
`ffarena_backup_last_success_timestamp_seconds`. The shipped alert
(`deploy/prometheus-alerts.yml`) fires when the age exceeds the backup
interval — a backup that silently stops running is the most common real
failure mode.

---

## 4. Point-in-time recovery (PostgreSQL)

PITR needs two things: a **base backup** (the `.pgdump`/`.sql` snapshot) and the
**WAL archive** from the archive directory configured in
`deploy/postgres-init.sh` (`WAL_ARCHIVE_DIR`, default
`/var/lib/postgresql/wal-archive`). Without archiving enabled there is no PITR —
you can only restore the snapshot's moment, so section 6's rehearsal is also the
config check.

### 4.1 Enable archiving (one-time, on the primary)

```bash
# In the production environment file:
WAL_ARCHIVE_ENABLED=true
WAL_ARCHIVE_DIR=/var/lib/postgresql/wal-archive
WAL_LEVEL=replica
# The archive directory MUST live on durable storage that is not the same
# volume as PGDATA (otherwise the WAL dies with the database).

# deploy/postgres-init.sh verifies the directory exists and is writable, and
# REFUSES to enable archive_mode otherwise — a broken archive_command makes
# PostgreSQL accumulate WAL until the disk fills.
docker compose -f deploy/docker-compose.production.yml up -d postgres
docker compose -f deploy/docker-compose.production.yml logs postgres | grep -i archive

# Confirm from SQL (expect: on / replica / the archive_command):
psql -c "SHOW archive_mode; SHOW wal_level; SHOW archive_command;"
psql -c "SELECT archived_count, failed_count, last_archived_time FROM pg_stat_archiver;"
```

`failed_count` must stay at 0 and `last_archived_time` must advance. Alert on
both.

### 4.2 Restore to a point in time

Never restore over the live cluster. Restore into a **new** data directory (or a
new host), verify, then re-point.

```bash
# 0. Stop writes and record the exact target time. If the incident started at
#    14:32 UTC and you want everything before it, restore to 14:31:30Z.
export PGDATA=/var/lib/postgresql/restore-data
export TARGET_TIME='2026-10-06 14:31:30+00'
export BUCKET=ffarena-backups
export DUMP=ffarena-20261006-030000           # newest snapshot BEFORE the incident

# 1. Fetch the base snapshot (and its manifest) from the offsite bucket.
aws s3 cp "s3://$BUCKET/backups/$DUMP/database.pgdump" /tmp/
aws s3 cp "s3://$BUCKET/backups/$DUMP/manifest.json" /tmp/

# 2. Decrypt if the manifest says encrypted=true. The identity file comes from
#    offline escrow — it is never on a web host.
age --decrypt --identity /secure/escrow/ffarena-backup.key \
    -o /tmp/database.pgdump /tmp/database.pgdump.age

# 3. Prove the artifact is intact BEFORE restoring it:
#      - compare sha256 of the decrypted file with manifest.encryption.plaintext_sha256
sha256sum /tmp/database.pgdump
jq -r '.encryption.plaintext_sha256' /tmp/manifest.json

# 4. Create the restore target and apply recovery settings.
sudo -u postgres mkdir -p "$PGDATA"
cat >> /var/lib/postgresql/restore.conf <<EOF
restore_command = 'cp /var/lib/postgresql/wal-archive/%f %p'
recovery_target_time = '$TARGET_TIME'
recovery_target_action = 'promote'
EOF

# 5. Restore the base backup, then let PostgreSQL replay WAL to the target.
pg_restore --host=/var/run/postgresql --username=ffarena --dbname=ffarena_restore \
           --format=custom --no-owner --no-privileges /tmp/database.pgdump

# 6. Watch the replay. It stops when the target time is reached:
tail -f /var/log/postgresql/postgresql-17-main.log | grep -i "recovery"
psql -c "SELECT pg_is_in_recovery();"      # must return f after promotion
psql -c "SELECT pg_last_wal_replay_timestamp();"   # must be >= your target
```

### 4.3 Reconcile after a PITR

A restore lands the database in the past; the world outside it did not go back
with it. Before serving traffic:

1. **Stop the queue workers and the scheduler** so no in-flight job writes onto
   the restored data.
2. Reconcile the money state — this is exactly what GAP-10 A8 exists for:
   ```bash
   php artisan settlements:reconcile --dry-run     # see the disagreement first
   php artisan settlements:reconcile               # bounded batches
   php artisan game-sessions:reconcile --dry-run
   php artisan game-sessions:reconcile
   ```
   Drift is **reported, never auto-corrected**: a human decides. Every run writes
   a `settlement.reconciled` / `game_session.reconciled` audit row.
3. Replay or cancel provider webhooks whose delivery window overlapped the
   outage. The ingress is idempotent on `(provider, external_event_id)`, so a
   replayed delivery settles exactly once.
4. Restart workers and the scheduler, then watch
   `ffarena_queue_oldest_seconds` and `ffarena_reconciliation_conflicts`.

---

## 5. Restore from a snapshot (no PITR)

```bash
# 1. Stop the app tier (no writes during a restore).
# 2. Restore into a NEW database name — never over the live one.
createdb -O ffarena ffarena_restore
pg_restore --dbname=ffarena_restore --format=custom --no-owner --no-privileges /tmp/database.pgdump

# 3. Private files:
tar -C /path/to/restored -xzf private-files.tar.gz   # identity docs, evidence, exports

# 4. Verify against the restored database before switching:
php artisan migrate:status                # no pending migration you did not expect
php artisan settlements:reconcile --dry-run
php artisan game-sessions:reconcile --dry-run

# 5. Switch the app over (DB_DATABASE=ffarena_restore), restart, then watch the
#    readiness probe and the metrics from section 3.
```

---

## 6. Rehearsal checklist (quarterly, and before every risky migration)

- [ ] `verify()` on the newest backup reports `ok=true` on every check.
- [ ] `restoreDryRun()` succeeds for the newest backup.
- [ ] A snapshot restored into a scratch database passes
      `settlements:reconcile --dry-run` and `game-sessions:reconcile --dry-run`.
- [ ] A PITR rehearsal (section 4.2) reaches the chosen target time and promotes.
- [ ] The decrypted dump's SHA-256 matches `encryption.plaintext_sha256`.
- [ ] `pg_stat_archiver.failed_count = 0` and `last_archived_time` is recent.
- [ ] The offsite object is present and its checksum matches the manifest.
- [ ] Restore timings are written down and compared with the RTO target.
- [ ] The drill report is filed; **E06 and E07 stay `PENDING` until a real drill
      against production-like infrastructure has produced one.**

---

## 7. Failure modes and what the code does

| Failure | Behaviour | Operator action |
| --- | --- | --- |
| `age` binary missing while `BACKUP_ENCRYPTION_RECIPIENT` is set | Run **fails**; no cleartext dump is stored | Install `age` or clear the recipient; never accept a cleartext backup of production |
| Offsite disk unreachable | Run reports `ok=false`, `offsite.ok=false`, error recorded; **local verified backup is kept** | Fix the destination; the local copy is the only copy until then |
| `BACKUP_OFFSITE_REQUIRED=true`, no disk | Run fails with `BACKUP_OFFSITE_DISK must be set` | Configure the disk — do not set `REQUIRED=false` to make it green |
| Offsite checksum mismatch on read-back | Copy marked failed; run failed | Treat as data corruption or a truncated upload; investigate before retrying |
| `archive_command` can't write | PostgreSQL keeps WAL (`wal_keep_size` bounds it) and `failed_count` climbs | Fix the archive path; the init script refuses to enable archiving if the directory is not writable |
| Restored database disagrees with the ledger | `settlements:reconcile` reports drift | Human review; reconciliation never rewrites financial history |
