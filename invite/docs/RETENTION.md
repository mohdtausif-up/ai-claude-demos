# Data retention and deletion policy

| Data | Rule |
| --- | --- |
| Deleted event | Soft-deleted immediately (guests lose access at once). Host owner/admin can restore for `RETENTION_DAYS` (default 30). |
| After the window | The worker permanently deletes the event and everything under it (invitations, RSVPs, answers, companions, check-ins, messages). Guest address-book rows no longer used by any event are anonymised. Logged as `event.purged`. |
| Guest "delete my data" / host "erase personal data" | Name, email, phone, notes, free-text answers, companion names and old-link tokens are removed immediately. The RSVP remains as an anonymous headcount so totals stay correct. Logged as `guest.anonymized`. |
| Unsubscribe | Sets do-not-contact; honoured at queue time and again at send time. |
| Auth tokens, idempotency keys, rate limits, finished jobs | Short-lived; purged by the scheduler. |
| Audit log | Kept (no personal guest data in its metadata). |

Passwords are Argon2id hashes; reset/login/verification tokens and API tokens are stored only as SHA-256 hashes; guest links are never stored.
