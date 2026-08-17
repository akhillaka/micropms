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

Deploys are **manual**. A push to `main` does not go live until you run the workflow.

### 1. FTP account in hPanel

Create an FTP/SFTP user whose home is the **domain folder** (the parent of `public_html`), so it can see both `public_html` and `pms_core`.

- FTP: port `21`, protocol `ftp` or `ftps`
- SFTP (typical Hostinger): port `65002`, protocol `sftp`

PHP **8.1+**.

### 2. GitHub secrets

Repo → **Settings** → **Secrets and variables** → **Actions**. Do not put these in the repo or in chat.

| Secret | Example |
|---|---|
| `FTP_SERVER` | Hostinger FTP/SFTP hostname |
| `FTP_USERNAME` | FTP user |
| `FTP_PASSWORD` | FTP password |
| `FTP_PORT` | `21` or `65002` |
| `FTP_PROTOCOL` | `ftp`, `ftps`, or `sftp` |

### 3. Run a deploy

1. Push your changes to `main` (or the branch you will check out).
2. GitHub → **Actions** → **Deploy to Hostinger** → **Run workflow**.
3. After a green run: log in to `/admin` and open one guest portal stay.

The workflow uploads:

- `public_html/` → `/public_html/`
- `pms_core/` → `/pms_core/`
- `db_migrations/` → `/db_migrations/`

It skips `.env` and `uploads/`.

## Database migrations on live

Code deploy does **not** change the MySQL schema by itself. After a deploy that includes new files in `db_migrations/`:

1. Log in as **superadmin**.
2. Open `https://yourdomain.com/admin/run_migration.php`.
3. Click run. Pending SQL files apply in order; already-applied ones are skipped (safe to click again).

Do this after the Action is green, before you assume new features work. Do not upload a full database dump.

## Deploy from the SaaS page

On live, open `/saas-admin` → **Deploy**. That starts the same GitHub Action (no zip upload).

Add to the **server** `.env` (not git):

```
GITHUB_DEPLOY_TOKEN=github_pat_...
GITHUB_REPO=akhillaka/micropms
```

Create the token on GitHub → Settings → Developer settings → Personal access tokens, with **Actions: Read and write** on this repo.

You can still run it from GitHub → **Actions** → **Deploy to Hostinger** → **Run workflow**. Do not use Hostinger hPanel Git for this folder layout.

## First time on the server

1. Run the Action once so the two folders exist.
2. Create `.env` in the **domain folder** (sibling of `public_html` and `pms_core`). Copy [`.env.example`](.env.example). Use Hostinger MySQL (`DB_HOST` is usually `localhost`) and a strong `INVOICE_SECRET`.
3. Confirm `/admin` works.

Telegram, Sheets, and WhatsApp queues use [`pms_core/cron_worker.php`](pms_core/cron_worker.php). Code deploy does **not** create that cron. Add it in hPanel → Cron Jobs if it is not already there.

## Emergency zip

If GitHub Actions cannot reach FTP:

```bash
bash scripts/build_deployment_zip.sh
```

Upload `deployment.zip` in File Manager, extract so `pms_core` and `public_html` stay siblings, and do not replace `.env` or `uploads/`.
