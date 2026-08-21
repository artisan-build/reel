# Restore uncertainty

Any database-only or bucket-only restore creates uncertainty about live references. Suspend destructive work
before restoring:

```bash
php artisan reel:reconcile-storage --suspend
```

Restore PostgreSQL and the private bucket from one consistency set while normal access and ingest remain
paused. Then run the real dry-run inventory command:

```bash
php artisan reel:reconcile-storage
```

Review its object, orphan, and missing-reference counts together with the high-water state:

```sql
SELECT orphan_sweeper_suspended, suspension_reason, database_high_water_at,
       object_high_water_at, reconciled_at, last_orphan_sweep_error
FROM retention_states
WHERE id = 1;
```

Resolve unexpected missing or extra objects and reapply deletion requests that occurred after the restored
backup. Only then persist the reviewed high-water marks and resume orphan deletion with
`php artisan reel:reconcile-storage --apply`. Confirm `php artisan reel:smoke` passes before restoring normal
access or ingest. A live Reel deletion does not erase immutable backup copies; those follow the customer's
backup retention policy.
