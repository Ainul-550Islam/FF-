---

## 4. Rollback

1. Redeploy the previous release artifact (or `git revert` the change).
2. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
3. `php artisan queue:restart`
4. `php artisan cache:clear` (public read caches rebuild automatically)
5. If a migration was destructive, restore the database from the last good
   backup (see DR runbook) — do **not** rely on `migrate:rollback`.

---

## 5. Cache management

