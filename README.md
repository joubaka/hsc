# Hermanus Sports Club

Hermanus Sports Club (HSC) is a Laravel 12 and Vue 3 application for club administration, member services, court booking, visitor checkout, finance, communications, access control, lighting operations, and championship management.

## Local setup

[VS Code](https://code.visualstudio.com/) + [Volar](https://marketplace.visualstudio.com/items?itemName=johnsoncodehk.volar) (and disable Vetur).

Requirements: PHP 8.2+, Composer, Node.js, npm, and SQLite or MySQL.

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
npm install
php artisan migrate
npm run build
```

Set `HSC_ADMIN_PASSWORD_HASH` before `php artisan db:seed` to create the protected administrator. To create or rotate one interactively, run `php artisan hsc:admin`.

## Running the application

Since TypeScript cannot handle type information for `.vue` imports, they are shimmed to be a generic Vue component type by default. In most cases this is fine if you don't really care about component prop types outside of templates.

However, if you wish to get actual prop types in `.vue` imports (for example to get props validation when using manual `h(...)` calls), you can run `Volar: Switch TS Plugin on/off` from VS Code command palette.

Main routes are `/login`, `/member-login`, `/member-portal`, `/book-a-court`, and `/manage-booking/{token}`. Run `php artisan queue:work --queue=communications,default` and `php artisan schedule:work` for queued communications and reminders.

## Operational areas

See [Vite Configuration Reference](https://vitejs.dev/config/).

Membership, renewals, member records, sport setup, court booking, public visitor checkout, finance, check-in, lighting, access, communications, and championships are implemented as separate role-aware workflows.

## Security and release verification

Public booking uses verification, opaque management tokens, and idempotency. PayFast and hardware controls are disabled/dry-run in the example environment and require real configuration and sandbox testing before activation. Never commit `.env`, credentials, raw tokens, or production database files; keep `APP_DEBUG=false` in production and use HTTPS.

```powershell
php artisan test --compact
npm run build
git diff --check
```

### Compile and Hot-Reload for Development

```

### Type-Check, Compile and Minify for Production

Before release, browser-test admin/member login, public booking/payment, booking management, cancellation, check-in, finance allocation, and championship operations at desktop and 320px widths. Production requires a real database, managed queue worker, SMTP, and a scheduler running `php artisan schedule:run` every minute. See [COMMUNICATIONS.md](COMMUNICATIONS.md).
