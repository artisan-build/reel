# Failed retention sweeps

Inspect the singleton retention state and overdue live sessions:

```sql
SELECT orphan_sweeper_suspended, suspension_reason, last_retention_sweep_at,
       database_high_water_at, object_high_water_at, last_orphan_sweep_error
FROM retention_states
WHERE id = 1;

SELECT id, session_id, status, delete_not_before, deletion_started_at,
       deletion_attempts, deletion_remaining_objects, failure_code
FROM recording_sessions
WHERE protected_at IS NULL
  AND delete_not_before <= CURRENT_TIMESTAMP
  AND status NOT IN ('deleted')
ORDER BY delete_not_before ASC;
```

Run `php artisan reel:retain-sessions` to execute the same database-aware retention sweep used by the scheduler.
Run `php artisan reel:retry-deletions` first for a dry-run count of incomplete deletions, then
`php artisan reel:retry-deletions --apply` after reviewing object-store health. Run
`php artisan reel:sweep-orphans` only when `orphan_sweeper_suspended` is false. Never replace Reel retention with
an unconditional bucket lifecycle rule because it cannot preserve protected recordings.
