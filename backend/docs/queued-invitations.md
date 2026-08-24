# Queued invitation e-mails

TASK-034C1 sends user invitations only by e-mail. It records queue acceptance and
mail-transport execution separately:

- `queued_at` means that the configured queue backend accepted the encrypted job;
- `sent_at` means that the worker completed the mail transport call successfully;
- `failed_at` means that the third and final attempt failed;
- neither `queued_at` nor `sent_at` proves delivery to, or reading by, the recipient.

An invitation delivery has three attempts. Its backoff sequence is 60, 300 and
900 seconds. A failed delivery does not delete or change the status of the account
or invitation. An authorized operator can use the existing resend action; resend
rotates the token and delivery version, invalidates every older link and makes any
older queued job harmless.

## Security model

`SendUserInvitationEmail` implements Laravel's `ShouldBeEncrypted` and
`ShouldQueueAfterCommit` contracts. The token exists only inside the encrypted job
command and the mail message. `user_invitations` continues to store only its
SHA-256 digest. Queue and worker errors use a fixed message that contains neither
the token nor the recipient address.

Do not remove job encryption, log decrypted queue payloads, copy a payload into an
incident ticket, or expose the contents of `jobs` and `failed_jobs` to application
users. Access to those tables is privileged operational access.

## Worker operation

Apply migrations before starting workers for this release. Run the worker through
the operator's existing process manager; TASK-034C1 does not install or prescribe
Supervisor, systemd, Docker or another provider-specific service.

The canonical foreground command is:

```text
php artisan queue:work --queue=default --sleep=3 --tries=3 --backoff=60,300,900 --max-time=3600
```

The job itself also declares the three-attempt and backoff policy, so a looser
global worker setting cannot weaken it. Deployments must request a graceful worker
restart after the new release is active:

```text
php artisan queue:restart
```

The process manager must restart workers that exit after `--max-time`, capture only
sanitized application output and never run two release versions against an
incompatible schema.

## Failed jobs and alerts

Inspect failures without displaying or exporting payload contents:

```text
php artisan queue:failed
php artisan queue:retry <failed-job-uuid>
```

Retry only after the transport incident is resolved and the target invitation is
still current. An obsolete job exits without sending. Prefer the invitation resend
action when a new link is required; it rotates the token and creates a new delivery
version.

Alert when any of these conditions occurs:

- a TASK-034C1 invitation reaches `failed_at`;
- queue depth or oldest-job age exceeds the approved operational threshold;
- no worker heartbeat/process is present;
- failure volume increases unexpectedly;
- SMTP authentication, connection or rate-limit errors recur.

Threshold values and the alert destination remain deployment decisions. Do not
automatically retry all failed jobs or delete evidence before incident review.

## Controlled SMTP staging validation

CI intentionally uses the `array` mailer and `sync` queue and therefore performs no
external delivery. Before production, an operator must validate a release in a
staging environment using:

1. a controlled recipient mailbox;
2. an SMTP transport configured outside version control;
3. the normal database queue connection and a running worker;
4. a newly created invitation containing no production personal data;
5. confirmation that `queued_at` is set after queue acceptance;
6. confirmation that `sent_at` is set after worker execution;
7. confirmation that the link can be accepted exactly once before expiry;
8. a forced transport failure confirming three attempts, bounded backoff and
   `failed_at`, followed by a successful authorized resend;
9. inspection proving that logs, audits, HTTP responses, `jobs.payload`,
   `failed_jobs.payload` and `failed_jobs.exception` contain no raw token.

`sent_at` is not a delivery receipt. Provider delivery tracking, bounce handling,
complaints and mailbox reading remain outside TASK-034C1 and require a separate
approved design.
