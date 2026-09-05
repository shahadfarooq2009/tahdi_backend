# Laravel Backend

This is the **only supported API** for the Arabic Quiz platform.

## Quick start (Windows / XAMPP)

```powershell
cd backend
composer install
# Configure .env (database, Supabase keys, OpenAI, queue)
php artisan migrate
npm run dev
```

- HTTP: http://127.0.0.1:8000
- Health: http://127.0.0.1:8000/api/health
- Starts a **queue worker** automatically (required for textbook PDF processing)

## Scripts

| Command | Description |
|---------|-------------|
| `npm run dev` | Laravel HTTP + queue worker (recommended) |
| `npm run test` | `php artisan test` |

## Important

- Do **not** use `php artisan serve` alone for admin PDF uploads — use `npm run dev` (upload limits + worker).
- Legacy Node backend lives in `../backend-node-legacy/` and must **not** be used.

See also: `docs/TEXTBOOK_UPLOAD_WORKFLOW.md`
