# MicroPMS — Hostinger deployment

Use this file for first go-live and every later update. PHP **8.1+**. Do not use hPanel Git.

## Folder layout (do not change)

Hostinger’s primary document root is always `public_html`. Keep app code **next to** it:

```
domains/yourdomain.com/          ← FTP home (parent of public_html)
  .env                           ← created once on the server, never in git
  public_html/                   ← repo public_html/  (web root)
  pms_core/                      ← repo pms_core/     (not web-accessible)
  db_migrations/                 ← repo db_migrations/
```

Do not dump the GitHub repo into `public_html` (that creates `public_html/public_html`). Do not put `pms_core` inside `public_html`.

Never overwrite on the server:

- `.env` — live DB and API secrets
- `public_html/uploads/` — guest / public files
- `pms_core/uploads/` — ID photos and similar

### Hostinger preview URL (`*.hostingersite.com`)

SSL on the temporary site is issued only for  
`https://something.hostingersite.com`  
not for `admin.something.hostingersite.com`. Chrome `ERR_CERT_COMMON_NAME_INVALID` is that mismatch. Do not create `admin.` / `guest.` subdomains on the preview host.

Use paths on the preview host:

- Landing: `https://something.hostingersite.com/`
- Staff login: `https://something.hostingersite.com/login`
- Admin: `https://something.hostingersite.com/admin`
- SaaS: `https://something.hostingersite.com/saas-admin`
- Leads form: `https://something.hostingersite.com/register`

Leave `APP_BASE_DOMAIN` empty. All staff, guest, assistant, and SaaS screens are paths on this host (`/login`, `/admin`, `/guest-login`). Do not create module subdomains.

---

## 1. First time on Hostinger

### A. PHP and MySQL

1. hPanel → **PHP Configuration** → **8.1 or newer**. Enable `pdo_mysql`, `curl`, `mbstring`, `openssl`.
2. hPanel → **MySQL** → create a database and user. Note host (usually `localhost`), name, user, password.

### B. FTP user (parent of public_html)

hPanel → **Files** → **FTP Accounts**. Home directory = the **domain folder** (the parent of `public_html`), so the user can see both `public_html` and `pms_core`.

| | Typical |
|---|---|
| Host | value shown in hPanel (`ftp.yourdomain.com` or the server hostname) |
| Protocol | `sftp` (port **65002**) or `ftp` / `ftps` (port **21**) |

### C. GitHub Actions secrets (required to upload)

Repo `akhillaka/micropms` → **Settings** → **Secrets and variables** → **Actions**. Add these names exactly:

| Secret | Value |
|---|---|
| `FTP_SERVER` | Hostinger FTP/SFTP hostname |
| `FTP_USERNAME` | FTP user |
| `FTP_PASSWORD` | FTP password |
| `FTP_PORT` | `65002` or `21` |
| `FTP_PROTOCOL` | `sftp`, `ftp`, or `ftps` |

Do not put these in git or in `.env.example`.

Workflow: [`.github/workflows/deploy-hostinger.yml`](.github/workflows/deploy-hostinger.yml) (manual **workflow_dispatch** only). A push to `main` does **not** go live by itself.

### D. First upload

1. Confirm `main` on GitHub is the code you want live.
2. GitHub → **Actions** → **Deploy to Hostinger** → **Run workflow** → branch `main`.
3. Green check = `public_html/`, `pms_core/`, and `db_migrations/` are on the server. It skips `.env` and `uploads/`. It does not delete extra files.

### E. Server `.env`

In File Manager, create `.env` in the **domain folder** (sibling of `public_html` and `pms_core`). Copy [`.env.example`](.env.example). Minimum for first login:

```
DB_HOST=localhost
DB_PORT=3306
DB_NAME=your_db
DB_USER=your_user
DB_PASS=your_db_password

INVOICE_SECRET=long_random_string_here
APP_ENV=production
# Unused. App is path-only.
APP_BASE_DOMAIN=
```

Replace `yourdomain.com` with the real apex (no `https://`, no `www`). Add Razorpay / WhatsApp / Telegram / SMTP later as needed.

### F. Same-host paths (no module subdomains)

Do **not** create `admin.` / `guest.` / `assistant.` / `saas.` subdomains. One SSL certificate on the apex is enough. Nested Hostinger roots (`public_html/admin`) still break `../../pms_core`.

| Path | What it serves |
|---|---|
| `/` | Marketing landing. **Request access** saves a lead. |
| `/login` | Staff login |
| `/admin` | Staff dashboard |
| `/guest-login` | Guest portal |
| `/assistant` | Hotel Assistant |
| `/saas-admin` | SaaS panel |
| `/register` | Lead form |

URLs have **no `.php`**.

### G. Schema and first check

1. Open `https://yourdomain.com/` — landing page.
2. If setup is not done, `/setup` runs. Otherwise log in at `https://yourdomain.com/login`.
3. Open `https://yourdomain.com/admin/run_migration` and click run (applies pending SQL, including `028`–`035`). Safe to click again.
4. SaaS: `https://yourdomain.com/saas-admin` — **Leads** tab for landing requests; **Onboarding** to create a property when you grant access.

### H. Cron (not created by the Action)

hPanel → **Cron Jobs**. Add both if they are missing (adjust the PHP path Hostinger shows):

```
* * * * * php /home/USER/domains/yourdomain.com/pms_core/cron_worker.php
* * * * * php /home/USER/domains/yourdomain.com/public_html/cron_scheduler.php
```

`cron_worker.php` = WhatsApp / Telegram / Sheets queues. `cron_scheduler.php` = night audit (property timezone), email reports (daily), holds sweep, checkout reminders, and other scheduled tasks.

---

## 2. Every later update

1. Commit and `git push origin main`.
2. GitHub → **Actions** → **Deploy to Hostinger** → **Run workflow**.
3. If this release added files under `db_migrations/`, run `/admin/run_migration` on live.
4. Smoke: landing `/`, staff `/login`, one guest stay on `/guest-login`, SaaS **Leads**.

Red Action: open the failed step. Almost always `FTP_SERVER` / user / port / protocol.

### Optional: Deploy button in SaaS

This is **not** a Hostinger password. It is a GitHub PAT so live PHP can start the same workflow.

GitHub → **Settings** → **Developer settings** → **Personal access tokens**. Fine-grained, repo `akhillaka/micropms` only, **Actions: Read and write**. Put it only in the **server** `.env`:

```
GITHUB_DEPLOY_TOKEN=github_pat_...
GITHUB_REPO=akhillaka/micropms
GITHUB_DEPLOY_WORKFLOW=deploy-hostinger.yml
GITHUB_DEPLOY_REF=main
```

Then `https://yourdomain.com/saas-admin` → **Deploy**. Without this token, GitHub → Actions still works.

---

## 3. Emergency zip (if FTP from GitHub fails)

On your Mac:

```bash
bash scripts/build_deployment_zip.sh
```

hPanel → File Manager → **domain folder** (parent of `public_html`) → upload `deployment.zip` → extract. Read `EXTRACT.txt` inside the zip. `public_html`, `pms_core`, and `db_migrations` must stay **siblings**. Do not replace `.env` or `uploads/`. Then open `/login` and run `/admin/run_migration`.

---

## 4. Local (no subdomains required)

```bash
cd public_html
php -S 127.0.0.1:8000 router.php
```

| URL | Surface |
|---|---|
| `http://localhost:8000/` | Landing |
| `http://localhost:8000/login` | Staff login |
| `http://localhost:8000/admin` | Dashboard |
| `http://localhost:8000/register` | Lead form |
| `http://localhost:8000/guest-login` | Guest portal |
| `http://localhost:8000/assistant` | Hotel Assistant |
| `http://localhost:8000/saas-admin` | SaaS |

To mimic production locally, use the same `php -S` command. All surfaces are paths on that host.
