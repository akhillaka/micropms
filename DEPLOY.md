# Deploy to Hostinger

This app must keep `pms_core` **next to** `public_html`. PHP loads core from `../pms_core` (guest portal) and `../../pms_core` (admin pages).

```
domains/yourdomain.com/
  pms_core/       ← repo pms_core/
  public_html/    ← repo public_html/  (Hostinger document root)
  .env            ← created once on the server, never in git
```

Do not dump the GitHub repo into `public_html` (that nests `public_html/public_html`). Use the GitHub Action below, or the zip fallback.

## Never overwrite

| Path | Why |
|---|---|
| `.env` | Live DB and API secrets |
| `public_html/uploads/` | Guest / public files |
| `pms_core/uploads/` | ID photos and similar |

The Action overwrites code files only. It does **not** delete extra files on the server.

## GitHub Action (normal path)

Deploys are **manual**. A push to `main` does **not** go live by itself. You run the workflow (from GitHub, or from SaaS → Deploy).

There are **two different secrets**. They are not interchangeable.

| What | Where it lives | What it is for |
|---|---|---|
| FTP/SFTP secrets | GitHub repo → Settings → Secrets and variables → Actions | The Action logs into Hostinger and uploads folders |
| `GITHUB_DEPLOY_TOKEN` | **Server** `.env` only (sibling of `public_html`) | Optional. Lets SaaS admin click Deploy and start that same Action via GitHub’s API |

The Action never uses `GITHUB_DEPLOY_TOKEN` to talk to Hostinger. Hostinger is FTP. The PAT only talks to GitHub.

### 1. FTP account in hPanel

hPanel → **Files** → **FTP Accounts** (or SFTP). Create a user whose home is the **domain folder** (the parent of `public_html`), so it can see both `public_html` and `pms_core`.

- FTP: port `21`, protocol `ftp` or `ftps`
- SFTP (typical Hostinger): port `65002`, protocol `sftp`

Hostname is usually `ftp.yourdomain.com` or the host shown in hPanel (not `github.com`). PHP **8.1+**.

### 2. GitHub Actions secrets (required to upload)

Open `https://github.com/akhillaka/micropms` → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**. Add each name exactly:

| Secret | Value |
|---|---|
| `FTP_SERVER` | Hostinger FTP/SFTP hostname from hPanel |
| `FTP_USERNAME` | FTP user |
| `FTP_PASSWORD` | FTP password |
| `FTP_PORT` | `21` or `65002` |
| `FTP_PROTOCOL` | `ftp`, `ftps`, or `sftp` |

Do not put these in the repo, in chat, or in `.env.example`.

The workflow file is [`.github/workflows/deploy-hostinger.yml`](.github/workflows/deploy-hostinger.yml). It runs only on **workflow_dispatch** (manual). It checks out git, installs `lftp`, then uploads:

- `public_html/` → `/public_html/`
- `pms_core/` → `/pms_core/`
- `db_migrations/` → `/db_migrations/`

It skips `.env` and `uploads/`. It does **not** delete extra files on the server.

### 3. Push code, then run the Action

1. Commit locally and `git push origin main`.
2. GitHub → **Actions** → **Deploy to Hostinger** → **Run workflow** → branch `main` → **Run workflow**.
3. Open the run. A green check means files are on Hostinger. A red X: open the failed step (almost always FTP host/user/port/protocol).
4. After a green run: open the apex landing page, log in on `admin.`, and open one stay on `guest.`.
5. If this release added files under `db_migrations/`, run `/admin/run_migration.php` on live (see below).

GitHub’s built-in `GITHUB_TOKEN` is enough for checkout inside the Action. You do **not** add a PAT as an Actions secret unless you want the SaaS Deploy button (next section).

### 4. Optional: SaaS page Deploy button (`GITHUB_DEPLOY_TOKEN`)

This token is **not** for Hostinger. It is a GitHub Personal Access Token so live PHP can call:

`POST /repos/akhillaka/micropms/actions/workflows/deploy-hostinger.yml/dispatches`

That is the same as clicking **Run workflow** on GitHub.

Create it:

1. GitHub → your avatar → **Settings** → **Developer settings** → **Personal access tokens**.
2. Fine-grained token (preferred): Resource owner = your user, Repository access = **Only** `akhillaka/micropms`. Permissions → **Actions: Read and write**. Contents can stay Read-only.
3. Classic token fallback: scope `repo` + `workflow`.
4. Copy the token once (`github_pat_...` or `ghp_...`). Put it only in the **server** `.env`:

```
GITHUB_DEPLOY_TOKEN=github_pat_...
GITHUB_REPO=akhillaka/micropms
GITHUB_DEPLOY_WORKFLOW=deploy-hostinger.yml
GITHUB_DEPLOY_REF=main
```

5. On live: `https://saas.yourdomain.com/` → **Deploy** → start the Action. Refresh for status.

If this token is missing, deploys still work from GitHub → Actions. Do not use Hostinger hPanel Git for this folder layout.

## Database migrations on live

Code deploy does **not** change the MySQL schema by itself. After a deploy that includes new files in `db_migrations/`:

1. Log in as **superadmin**.
2. Open `https://admin.yourdomain.com/admin/run_migration.php` (or `/admin/run_migration.php` on localhost).
3. Click run. Pending SQL files apply in order; already-applied ones are skipped (safe to click again).

Do this after the Action is green, before you assume new features work. Do not upload a full database dump.

## Module hosts (same public_html)

The primary domain document root is always `public_html`. You cannot change that in hPanel, and you do not need to.

All product UIs share **one** copy of the app. Create subdomains in hPanel, then set each subdomain’s document root to the **same** `public_html` as the primary domain. Do **not** let Hostinger create `public_html/admin`, `public_html/guest`, or `domains/admin.yourdomain.com/public_html`. Nested roots break `../../pms_core`.

Set `APP_BASE_DOMAIN=yourdomain.com` in the server `.env`.

| Host | Surface |
|---|---|
| `yourdomain.com` | Marketing landing (`/`) |
| `guest.yourdomain.com` | Guest portal |
| `admin.yourdomain.com` | Staff admin |
| `assistant.yourdomain.com` | Hotel Assistant |
| `saas.yourdomain.com` | SaaS control panel + public `/register` |

Issue SSL for each hostname (or a wildcard). Landing **Login** goes to staff admin; **Register** creates a property on a SaaS plan and signs the owner into admin. Platform operators use `saas.` login.

## Local: paths vs subdomains

You do **not** need subdomains on your Mac. `http://localhost:8000` stays path-based:

| URL | Surface |
|---|---|
| `http://localhost:8000/` | Marketing landing |
| `http://localhost:8000/login` | Staff login |
| `http://localhost:8000/admin` | Staff dashboard |
| `http://localhost:8000/admin?hotelId=1000` | Same dashboard, property 1000 |
| `http://localhost:8000/guest-login` | Guest portal login |
| `http://localhost:8000/assistant` | Hotel Assistant |
| `http://localhost:8000/saas-admin` | SaaS panel |
| `http://localhost:8000/register` | Public hotel register |

Start the app from `public_html` so routing works:

```bash
cd public_html
php -S 127.0.0.1:8000 router.php
```

`http://localhost:8000/index.php?hotelId=1000` is an old bookmark. It now opens the staff dashboard for that property (you must already be logged in). Prefer `/admin?hotelId=1000`.

### Optional: fake subdomains on localhost

PHP’s built-in server has no hPanel. To exercise `guest.` / `admin.` hosts locally:

1. Add to `/etc/hosts` (needs your Mac password):

```
127.0.0.1 guest.localhost admin.localhost assistant.localhost saas.localhost
```

2. In the **local** `.env` (repo parent of `public_html`, not git):

```
APP_BASE_DOMAIN=localhost
```

3. Start the same server bound to `127.0.0.1` (so every hostname hits it):

```bash
cd public_html
php -S 127.0.0.1:8000 router.php
```

4. Open `http://admin.localhost:8000/`, `http://guest.localhost:8000/`, `http://saas.localhost:8000/`, `http://127.0.0.1:8000/` (landing still on loopback).

Login links keep port `:8000`. You still do **not** create extra folders.

## First time on the server

1. Run the Action once so the two folders exist.
2. Create `.env` in the **domain folder** (sibling of `public_html` and `pms_core`). Copy [`.env.example`](.env.example). Use Hostinger MySQL (`DB_HOST` is usually `localhost`), a strong `INVOICE_SECRET`, and `APP_BASE_DOMAIN`.
3. Confirm `https://yourdomain.com/` shows the landing page, then log in on `admin.`.

Telegram, Sheets, and WhatsApp queues use [`pms_core/cron_worker.php`](pms_core/cron_worker.php). Code deploy does **not** create that cron. Add it in hPanel → Cron Jobs if it is not already there.

## Emergency zip

If GitHub Actions cannot reach FTP:

```bash
bash scripts/build_deployment_zip.sh
```

Upload `deployment.zip` in File Manager, extract so `pms_core` and `public_html` stay siblings, and do not replace `.env` or `uploads/`.
