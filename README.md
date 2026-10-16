# Secure File Storage & Sharing System (Symfony)

A PHP / Symfony port of the Django project
[secure-file-storage](https://github.com/parthkajavadra/secure-file-storage): a REST API for storing files
securely and sharing them, with JWT authentication, access control, virus scanning, expiring public links,
rate limiting and access logging.

The API keeps the same URLs, request fields and JSON responses as the Django/DRF version, so existing
clients keep working (see [Differences from the Django version](#differences-from-the-django-version)).

---

## Features

- **User authentication:** JWT access + refresh tokens (LexikJWTAuthenticationBundle + JWTRefreshTokenBundle)
- **Secure file upload:** size and type checks, then a ClamAV scan before the file is stored
- **File access control:** files are only accessible to their owner, users they're shared with, or everyone if public
- **Expiring public links:** shareable URLs with an expiry time
- **Download monitoring & centralized logging:** suspicious and security-relevant events go to `access.log`, server errors to `error.log`
- **Rate limiting:** same limits as the DRF throttles (200/min per user, 5/min anonymous, 10/hour for public links)
- **Background scanning:** Symfony Messenger (replaces Celery)
- **Tests:** PHPUnit functional tests

## Tech stack

| Django project | This project |
|---|---|
| Django 5 + Django REST Framework | Symfony 7.4 (LTS) |
| Django ORM + migrations | Doctrine ORM 3 + Doctrine Migrations |
| djangorestframework-simplejwt | lexik/jwt-authentication-bundle + gesdinet/jwt-refresh-token-bundle |
| pyClamd | Built-in ClamAV client (`src/Service/ClamAvScanner.php`, clamd `INSTREAM` over TCP) |
| Celery + Redis | Symfony Messenger |
| DRF throttling | Symfony RateLimiter |
| Python `logging` (RotatingFileHandler) | Monolog |
| Django `TestCase` + coverage.py | PHPUnit |

---

## Setup

Requirements: PHP 8.2+ with the `ctype`, `fileinfo`, `iconv`, `openssl` and `pdo_sqlite` extensions, Composer,
and a running ClamAV daemon (`clamd`) for uploads.

```bash
# 1. Install dependencies
composer install

# 2. Local settings (optional): copy values from .env into .env.local and change them,
#    at least APP_SECRET and JWT_PASSPHRASE
cp .env .env.local

# 3. Generate the JWT signing keys (written to config/jwt/)
php bin/console lexik:jwt:generate-keypair

# 4. Create the database (SQLite by default, var/data.db)
php bin/console doctrine:migrations:migrate

# 5. Run the server
symfony serve            # with the Symfony CLI, or:
php -S 127.0.0.1:8000 -t public
```

### ClamAV

Uploads are scanned by `clamd` at `CLAMAV_HOST:CLAMAV_PORT` (default `127.0.0.1:3310`). Files are streamed
to the daemon, so it can run in another container. For local development:

```bash
docker run -d --name clamav -p 3310:3310 clamav/clamav
```

As in the original project, **if ClamAV can't be reached the upload is rejected** (fail closed).

### Background scanning (replaces Celery)

Each stored file is also queued for a background re-scan (`App\Message\ScanFileMessage`). By default
`MESSENGER_TRANSPORT_DSN=sync://` runs it immediately. To use Redis like the Celery setup:

```bash
composer require symfony/redis-messenger
# .env.local
MESSENGER_TRANSPORT_DSN=redis://localhost:6379/messages
# worker
php bin/console messenger:consume async
```

### Testing by hand in the browser

With the server running in the dev environment (the default), open **http://127.0.0.1:8000/tester**.
It's a small page for trying every endpoint: register and log in several users, switch between them,
upload files (including an EICAR test virus and a disallowed file type), download, share, create and open
public links. Every request and response shows up in a log on the right.

The page only exists when `APP_ENV=dev` (route in `config/routes/dev/`); in production it returns 404.

### Running tests

```bash
vendor/bin/phpunit
```

The tests use a separate SQLite database (`var/test.db`), create their own throw-away JWT keys, and replace
ClamAV with a fake scanner, so no external services are needed.

---

## API

All endpoints except those marked *public* need `Authorization: Bearer <access token>`.

| Method | Path | Body | Description |
|---|---|---|---|
| POST | `/api/token/` | JSON `{"username", "password"}` | *Public.* Returns `{"refresh", "access"}` |
| POST | `/api/token/refresh/` | `{"refresh"}` | *Public.* Returns a new `access` token |
| POST | `/api/storage/register/` | `{"username", "password"}` | *Public.* Create an account |
| POST | `/api/storage/upload/` | multipart: `file`, optional `title`, `access_level` (`private`/`public`/`shared`), `shared_with[]` (user ids) | Upload a file (max 2 MB; PDF, JPEG, PNG or plain text) |
| GET | `/api/storage/files/` | | Files you own, public files, and files shared with you |
| GET | `/api/storage/download/{id}/` | | Download a file you can access |
| POST | `/api/storage/share/` | `{"file_id", "share_with_username"}` | Share your file with another user |
| POST | `/api/storage/share/{id}/` | `{"expires_in_minutes"}` | Create an expiring public link for your file |
| GET | `/api/storage/public/{token}/` | | *Public.* Download via a public link |

Example:

```bash
curl -X POST http://127.0.0.1:8000/api/storage/register/ -H 'Content-Type: application/json' \
     -d '{"username": "alice", "password": "secret"}'

ACCESS=$(curl -s -X POST http://127.0.0.1:8000/api/token/ -H 'Content-Type: application/json' \
     -d '{"username": "alice", "password": "secret"}' | jq -r .access)

curl -X POST http://127.0.0.1:8000/api/storage/upload/ -H "Authorization: Bearer $ACCESS" \
     -F file=@notes.txt -F title="My notes"
```

### Rate limits

Same as the DRF settings in the original: authenticated users 200 requests/minute; anonymous clients
5 requests/minute per IP — shared across login, token refresh, register and public downloads, exactly as
DRF's `anon` throttle was — and public-link downloads additionally 10/hour per IP. Over the limit the API
returns `429` with a `Retry-After` header.

### Logs

`var/log/access-YYYY-MM-DD.log` and `var/log/error-YYYY-MM-DD.log` (rotated daily, 3 files kept).

---

## Project structure

```
src/
  Controller/StorageController.php   ← storage/views.py + serializers
  Controller/AuthController.php      ← token endpoint routes (handled by the security firewall)
  Entity/                            ← storage/models.py (+ User, RefreshToken)
  Repository/                        ← query logic (e.g. "accessible files")
  Service/ClamAvScanner.php          ← storage/utils/virus_scan.py
  Service/FileStorage.php            ← where uploads are stored
  Message/, MessageHandler/          ← storage/tasks.py (Celery task)
  EventSubscriber/AccessLogSubscriber.php ← core/middleware/access_log_middleware.py
  EventSubscriber/ThrottleSubscriber.php  ← DRF throttles + storage/throttles.py
  EventSubscriber/JwtResponseSubscriber.php ← SimpleJWT-compatible token responses
config/packages/                     ← core/settings.py
migrations/                          ← storage/migrations/
tests/                               ← storage/tests.py
```

---

## Differences from the Django version

Mostly deliberate security fixes; everything else behaves the same.

- **Files are never served from a public URL.** Django served `media/` directly in DEBUG mode, which bypassed
  the access checks. Files now live in `var/storage/` (outside the web root) and are only available through the
  download endpoints. The `file` field in responses is the stored path; a new `download_url` field gives the
  access-checked URL.
- **File type is detected from the content**, not trusted from the client's `Content-Type` header.
- **Upload names are sanitized** and the upload is scanned in place. The original wrote to `/tmp/<client
  filename>`, which allowed path traversal.
- **Client IPs use trusted proxies only.** The original took the first `X-Forwarded-For` value, which any
  client can forge in logs. Set `TRUSTED_PROXIES` if you run behind a reverse proxy.
- **View log messages reach `access.log`.** In the Django code the views logged to `access_logger`, which
  wasn't configured, so those messages never reached the file.
- `expires_in_minutes` is capped at 10 years so the expiry date can't overflow (Django would return a 500).
- `/api/token/` accepts JSON only (DRF also accepted form data). A form-encoded request gets a 400 explaining this.
- Uploaded duplicate names get a random 7-character suffix, similar to Django's storage.

### Not ported

- **Django admin** (`/admin/`). If you need one, [EasyAdmin](https://symfony.com/bundles/EasyAdminBundle) is the usual choice.
- `storage/permissions.py` (`IsOwnerSharedOrPublic`) and `UserFileListView` were not used by any route
  in the original; the same rule lives in `File::canBeAccessedBy()`.
- **Existing data:** passwords stored by Django (PBKDF2) can't be verified by this app, so existing users would need
  to reset their passwords if you migrate an existing database.
