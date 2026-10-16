# Secure File Storage & Sharing System

A REST API built with PHP and Symfony for storing files securely and sharing them, with JWT authentication,
access control, virus scanning, expiring public links, rate limiting and access logging.

---

## Run it locally

You need **PHP 8.2+**, **[Composer](https://getcomposer.org/download/)** and **ClamAV**
(see [ClamAV](#clamav) below for installing it).

```bash
git clone https://github.com/parthkajavadra/secure-file-storage-php.git
cd secure-file-storage-php
composer install
php bin/console lexik:jwt:generate-keypair
php bin/console doctrine:migrations:migrate -n
```

Start ClamAV and the server (keep this window open):

```bash
clamd
php -S 127.0.0.1:8000 -t public
```

Then open **http://127.0.0.1:8000/tester** in your browser to try the API, or run the tests with
`vendor/bin/phpunit`.

Every setting has a working default (see [Configuration](#configuration)), so nothing needs configuring to
run it locally.

---

## Features

- **User authentication:** JWT access and refresh tokens
- **Secure file upload:** size and type checks, then a ClamAV virus scan before the file is stored
- **File access control:** files are only accessible to their owner, users they're shared with, or everyone if public
- **Expiring public links:** shareable download URLs with an expiry time
- **Download monitoring & centralized logging:** security-relevant events go to `access.log`, server errors to `error.log`
- **Rate limiting:** per-user and per-IP request limits to prevent abuse
- **Background scanning:** stored files are re-scanned asynchronously with Symfony Messenger
- **Tests:** PHPUnit functional test suite

## Tech stack

| Area | Technology |
|---|---|
| Framework | Symfony 7.4 (LTS), PHP 8.2+ |
| Database | Doctrine ORM 3 + Doctrine Migrations (SQLite by default; MySQL/PostgreSQL supported) |
| Authentication | lexik/jwt-authentication-bundle + gesdinet/jwt-refresh-token-bundle |
| Virus scanning | ClamAV (`clamd`) via a built-in client (`src/Service/ClamAvScanner.php`) |
| Background jobs | Symfony Messenger |
| Rate limiting | Symfony RateLimiter |
| Logging | Monolog |
| Testing | PHPUnit |

---

## Setup

Requirements: PHP 8.2+ with the `ctype`, `fileinfo`, `iconv`, `openssl` and `pdo_sqlite` extensions, Composer,
and a running ClamAV daemon (`clamd`) for uploads.

```bash
composer install
php bin/console lexik:jwt:generate-keypair
php bin/console doctrine:migrations:migrate
php -S 127.0.0.1:8000 -t public
```

1. `composer install` installs the dependencies.
2. `lexik:jwt:generate-keypair` creates the keys used to sign login tokens (in `config/jwt/`).
3. `doctrine:migrations:migrate` creates the database (`var/data.db`).
4. `php -S ...` starts the development server (or use `symfony serve` with the Symfony CLI).

### Configuration

All settings have defaults in `config/services.yaml`:

| Setting | Default |
|---|---|
| `APP_ENV` | `dev` |
| `APP_SECRET` | placeholder, **change for production** |
| `DATABASE_URL` | SQLite file `var/data.db` |
| `JWT_SECRET_KEY` / `JWT_PUBLIC_KEY` | `config/jwt/private.pem` / `public.pem` |
| `JWT_PASSPHRASE` | `change_me`, **change for production** |
| `STORAGE_DIR` | `var/storage` |
| `CLAMAV_HOST` / `CLAMAV_PORT` | `127.0.0.1` / `3310` |
| `MESSENGER_TRANSPORT_DSN` | `sync://` |
| `TRUSTED_PROXIES` | empty |

To change one, set it as an environment variable, or create a `.env` file in the project root with just the
values you need, for example:

```
CLAMAV_PORT=3311
DATABASE_URL="mysql://user:pass@127.0.0.1:3306/secure_storage?serverVersion=8.0"
```

`.env` and `.env.local` are local files and must not be committed.

### ClamAV

Uploads are scanned by `clamd` at `CLAMAV_HOST:CLAMAV_PORT` (default `127.0.0.1:3310`). Files are streamed to
the daemon, so it can also run on another machine or in a container.

On macOS with Homebrew:

```bash
brew install clamav
cd /opt/homebrew/etc/clamav
cp freshclam.conf.sample freshclam.conf
cp clamd.conf.sample clamd.conf
sed -i '' 's/^Example/#Example/' freshclam.conf clamd.conf
printf "TCPSocket 3310\nTCPAddr 127.0.0.1\n" >> clamd.conf
mkdir -p /opt/homebrew/var/lib/clamav
freshclam
clamd
```

Or with Docker: `docker run -d --name clamav -p 3310:3310 clamav/clamav`

**If ClamAV can't be reached, uploads are rejected** (fail closed), so no file is ever stored unscanned.

### Background scanning

Each stored file is also queued for a background re-scan (`App\Message\ScanFileMessage`). By default
`MESSENGER_TRANSPORT_DSN=sync://` runs it immediately. To use a real queue with Redis:

```bash
composer require symfony/redis-messenger
```

Set `MESSENGER_TRANSPORT_DSN=redis://localhost:6379/messages` (see [Configuration](#configuration)), then run a worker:

```bash
php bin/console messenger:consume async
```

---

## Testing

### Automated tests

```bash
vendor/bin/phpunit
```

The tests use a separate SQLite database (`var/test.db`), create their own throw-away JWT keys, and replace
ClamAV with a fake scanner, so no external services are needed.

### Testing by hand in the browser

With the server running in the dev environment (the default), open **http://127.0.0.1:8000/tester**.
It's a small page for trying every endpoint: register and log in several users, switch between them,
upload files, download, share, and create and open public links. It also has buttons that send an EICAR
test virus and a disallowed file type to check that both are rejected. Every request and response shows up
in a log on the right.

The page only exists when `APP_ENV=dev` (route in `config/routes/dev/`); in production it returns 404.

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

Access tokens are valid for 5 minutes and refresh tokens for 1 day.

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

| Who | Limit |
|---|---|
| Authenticated users | 200 requests/minute |
| Anonymous clients (login, token refresh, register, public downloads) | 5 requests/minute per IP, shared |
| Public-link downloads | additionally 10/hour per IP |

Over the limit the API returns `429` with a `Retry-After` header.

### Logs

`var/log/access-YYYY-MM-DD.log` and `var/log/error-YYYY-MM-DD.log` (rotated daily, 3 files kept). The access
log records refused requests (400/401/403/404/405), rejected uploads, public link creation and downloads,
and attempts to use invalid or expired links.

---

## Security design

- **Files are never served from a public URL.** Uploads live in `var/storage/`, outside the web root, and are
  only available through the access-checked download endpoints.
- **File type is detected from the file's content**, not trusted from the client's `Content-Type` header.
- **Upload names are sanitized** (no path traversal), and duplicates get a random suffix instead of overwriting.
- **Every upload is virus-scanned before it is stored**, and rejected if the scanner is unavailable.
- **Client IPs are only taken from trusted proxies**, so `X-Forwarded-For` can't be forged to dodge rate limits
  or falsify logs. Set `TRUSTED_PROXIES` if you run behind a reverse proxy.
- **Passwords are hashed** with Symfony's `auto` hasher (bcrypt/Argon2).

---

## Project structure

```
src/
  Controller/StorageController.php       ← storage, sharing and public-link endpoints
  Controller/AuthController.php          ← token endpoint routes (handled by the security firewall)
  Controller/DevTesterController.php     ← browser test page (dev only)
  Entity/                                ← User, File, PublicShareLink, RefreshToken
  Repository/                            ← database queries (e.g. "files a user can access")
  Service/ClamAvScanner.php              ← ClamAV client
  Service/FileStorage.php                ← where uploads are stored
  Message/, MessageHandler/              ← background virus re-scan
  EventSubscriber/AccessLogSubscriber.php    ← access and error logging
  EventSubscriber/ThrottleSubscriber.php     ← rate limiting
  EventSubscriber/JwtResponseSubscriber.php  ← token response format and auth errors
  Http/                                  ← request parsing, validation and JSON responses
config/packages/                         ← framework, security, database, logging configuration
migrations/                              ← database schema
tests/                                   ← PHPUnit tests
tools/tester.html                        ← browser test page
```
