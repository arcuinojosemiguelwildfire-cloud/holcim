# Seeders

This folder is intentionally empty in Phase 1.

The project does **not** ship demo or fake event data. The only data needed to
start is the first administrator account, which is created with a CLI script
instead of a seeder so no credentials ever live in the repository:

```bash
php backend/cli/create-user.php
```

If development seeders are added later (for example, a script that generates
sample attendees for load testing), they must:

- Live in this folder.
- Refuse to run when `APP_ENV=production`.
- Never contain real personal data or real credentials.
