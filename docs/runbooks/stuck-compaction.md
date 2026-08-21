# Stuck compaction

The dashboard's **Awaiting compaction**, queue lag, failed jobs, and state-age counters are the first signal.
Inspect the exact session state recorded by Reel:

```sql
SELECT id, session_id, status, status_changed_at, compaction_attempts, failure_code,
       gap_count, max_reorder_distance
FROM recording_sessions
WHERE status IN ('closing', 'compacting')
ORDER BY status_changed_at ASC;
```

List failed queue jobs with `php artisan queue:failed`. For a session still in `closing`, run the idempotent
`php artisan reel:finalize-sessions` command and confirm a compaction is queued. Retry a specifically identified
failed compaction job with Laravel's displayed `php artisan queue:retry <uuid>` command; do not retry every
failed job without reviewing its exception and session state. A terminal `failure_code = compaction_failed`
requires investigation rather than a forced state update.
