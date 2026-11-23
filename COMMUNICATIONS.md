# Member email communications

The Communication Centre is available to central administrators from the main administration navigation. It supports immediate and scheduled announcements to all active members, a sport audience, or selected members. Only members with email consent and a usable email address are included.

Transactional messages are created automatically for booking confirmations, booking cancellations, invoices, and confirmed payments. The scheduler also creates one reminder per booking approximately 24 hours before play. Every delivery has an idempotency key, status history, and a member-portal inbox copy. Failed deliveries can be retried from the Communication Centre.

## Local testing

The example environment uses Laravel's `log` mailer. Messages are written to the application log and are not delivered to real members. If a Mailpit, Mailtrap, or other SMTP sandbox is configured locally, verify its host, port, and credentials in `.env` before relying on inbox delivery.

Run these in separate terminals while testing queued and scheduled mail:

```powershell
php artisan queue:work --queue=communications,default
php artisan schedule:work
```

Use the admin **Communication Centre** to send a test announcement. Then sign in through `/member-login`, open **Messages**, and confirm that the same message appears in the member inbox and records its read time when opened.

## Production requirements

Before real delivery, configure the club's production SMTP provider and set `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`. Keep one queue worker running for the `communications,default` queues and invoke `php artisan schedule:run` every minute using the server scheduler. After changing environment values, run:

```powershell
php artisan optimize:clear
```

Monitor failed queue jobs with `php artisan queue:failed`. The Communication Centre's delivery totals show queued, sent, and failed recipient counts for each campaign.
