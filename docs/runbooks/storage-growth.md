# Storage growth

Compare the dashboard's estimated bytes and protected count with live session state:

```sql
SELECT status, (protected_at IS NOT NULL) AS protected, COUNT(*) AS sessions,
       COALESCE(SUM(compressed_bytes), 0) AS compressed_bytes
FROM recording_sessions
WHERE status <> 'deleted'
GROUP BY status, (protected_at IS NOT NULL)
ORDER BY status, protected;
```

Run `php artisan reel:reconcile-storage` without `--apply` to inventory object count, unreferenced objects, and
referenced manifests whose objects are missing. It is a dry run and does not resume or delete anything. Check
the oldest overdue unprotected expiry and last successful retention sweep on the dashboard, then follow the
[failed-retention runbook](failed-retention.md) when expiry is behind. Protected sessions intentionally do not
expire; review them with their owners rather than adding an unconditional bucket lifecycle rule.
