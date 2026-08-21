# Retention and recovery

Reel expires ordinary recordings through its database-aware scheduler. Do not configure an unconditional
bucket lifecycle rule for the recording prefix: a time-based bucket rule cannot see Reel protection state and
can permanently delete protected recordings.

After restoring either PostgreSQL or Object Storage, suspend orphan deletion with
`php artisan reel:reconcile-storage --suspend`. Restore the database and private bucket consistently, then run
`php artisan reel:reconcile-storage` to inspect the prefix without changing state. Only after reviewing the
database/object high-water report should an administrator run `php artisan reel:reconcile-storage --apply`.
The orphan sweep remains unhealthy and performs no deletion while suspended.

Application-user erasure removes matching jobs, metadata, and objects from the live Reel deployment. Its audit
contains the Reel actor, application, time, counts, outcome, and an opaque batch id, but not the erased
application user id. Immutable backups follow the customer's backup retention policy. If an older backup is
restored, applicable erasure requests must be reapplied before normal access or ingest resumes.
