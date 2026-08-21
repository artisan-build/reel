# Rejected chunks

Start with the affected session and epoch. Reel persists privacy failures on the epoch and upload/state symptoms
on the session without storing rejected DOM payloads:

```sql
SELECT rs.session_id, rs.status, rs.failure_code, rs.conflicting_retry_count,
       rs.gap_count, rs.max_reorder_distance, re.epoch_id, re.status AS epoch_status,
       re.failure_code AS epoch_failure_code
FROM recording_sessions AS rs
LEFT JOIN recording_epochs AS re ON re.recording_session_id = rs.id
WHERE rs.session_id = '<opaque-session-id>';
```

Use the structured application logs at the rejection time for the exact sanitized reason and HTTP status. The
dashboard's late-upload rejection counter identifies uploads rejected after closing or cutoff. Common durable
signals include `conflicting_retry_count`, failed epochs, gaps, and a session `failure_code`; Reel intentionally
does not persist the rejected event body. Fix the host policy, credential, protocol, ordering, or capacity cause
before re-enabling ingest. Do not inspect or log decoded real-user chunks to diagnose a rejection.
