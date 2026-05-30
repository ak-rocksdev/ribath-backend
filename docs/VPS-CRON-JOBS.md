# VPS Cron Jobs

Production VPS at `ribath.hyperscore.cloud` (103.157.97.233) hosts several projects sharing one OS-level crontab under user `ak_rocks`. This doc covers what runs, when, and why.

## Active entries (`crontab -l` as `ak_rocks`)

```cron
# Cycle 013 scheduler (added 2026-05-19)
* * * * * cd /srv/www/car-club-app/current && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1

# Ribath Backend scheduler (added 2026-05-30)
* * * * * cd /srv/www/ribath-backend/current && /usr/bin/php8.2 artisan schedule:run >> /dev/null 2>&1
```

Both follow the standard Laravel scheduler pattern: a single `* * * * *` (every minute) entry runs `php artisan schedule:run`, and Laravel decides internally which scheduled commands actually fire based on what's registered in `routes/console.php`.

## PHP version per project — why they differ

Each project pins to the PHP runtime its codebase is consistently using:

| Project | CLI PHP | FPM service | Why |
|---|---|---|---|
| `ribath-backend` | `/usr/bin/php8.2` (= `php8.2`, 8.2.30) | `php8.2-fpm` | `deploy.sh` uses bare `php` which resolves to 8.2; reload prefers `php8.2-fpm`. Web and cron share one runtime → same opcache, same extensions. |
| `car-club-app` | `/usr/bin/php8.4` | `php8.4-fpm` | Project requires 8.4. |

`/usr/bin/php` is a symlink (`update-alternatives`) currently pointing to 8.2. **Don't** rely on the bare `php` form in cron — `update-alternatives` changes silently affect all entries. Always explicit (`/usr/bin/php8.2`).

Both `php8.2-fpm` and `php8.4-fpm` run in parallel. Nginx vhost picks which socket based on per-project config; the cron must match what FPM serves.

## Scheduled Laravel commands (ribath-backend)

Defined in `routes/console.php`. Use `php artisan schedule:list` to see live state.

| Command | Frequency | Timezone | Purpose |
|---|---|---|---|
| `fee:generate-bills --actor=scheduler` | Daily 00:01 | Asia/Jakarta | Iterate active `student_fee_assignments`, generate `Bill` rows for the current period if not yet present. Idempotent. `--actor=scheduler` skips `once_at_enrollment` cadence (those are created at PSB approval, not by cron). |

## How to add a new cron entry

Idempotent install pattern:

```bash
if crontab -l 2>/dev/null | grep -q "MY_UNIQUE_MARKER"; then
    echo "already present — skipping"
else
    (crontab -l 2>/dev/null; echo ""; echo "# Description (added YYYY-MM-DD)"; echo "<schedule> <command>") | crontab -
fi
```

Always:
- Use `cd /srv/www/<project>/current` (the Capistrano symlink) — survives deploys without crontab edits.
- Use explicit PHP binary path (`/usr/bin/php8.2` or `/usr/bin/php8.4`), never bare `php`.
- Redirect output (`>> /dev/null 2>&1` or to a log file) — Laravel handles its own logging.
- Add a comment line with the date so future ops can audit.

## Troubleshooting

**Scheduled command not firing?**
1. Check OS cron is running: `systemctl status cron`
2. Check the entry is in the crontab: `crontab -l`
3. Check Laravel sees the command: `cd /srv/www/ribath-backend/current && /usr/bin/php8.2 artisan schedule:list`
4. Check Laravel logs: `tail -50 /srv/www/ribath-backend/shared/storage/logs/laravel.log`
5. Run once manually with verbose output: `cd /srv/www/ribath-backend/current && /usr/bin/php8.2 artisan schedule:run -v`

**`current` symlink invalid?** Deploy probably failed mid-flight. Re-run `bash /srv/www/ribath-backend/scripts/deploy.sh [...]` and the symlink switch in step 8 restores it.

**Two cron entries firing for the same project?** Use `withoutOverlapping()` on the schedule definition (already applied to `fee:generate-bills`).
