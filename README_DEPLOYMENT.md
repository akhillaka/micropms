# 🚀 Hotel PMS & Assistant — Production Deployment Guide

## Overview
This project contains two primary folders:
1. **`public_html/`**: The web root containing the Admin Dashboard (`admin/`), Hotel Assistant PWA (`assistant/`), and public API endpoints (`api/`).
2. **`pms_core/`**: Private business logic, services, and database connection handlers (protected from direct HTTP access).

---

## 📋 Step-by-Step Deployment Guide

### Step 1: Upload Files to Server
Upload the contents of `deploy.zip` (or git repository) to your server directory structure:
- **Hostinger / cPanel / Shared Hosting**:
  - Place contents of `public_html/` inside your domain's `public_html/` or `htdocs/` folder.
  - Place `pms_core/` one directory above `public_html/` (e.g. `/home/username/pms_core/`), OR keep `pms_core/` inside `public_html/` (it is protected by `pms_core/.htaccess` with `Deny from all`).

---

### Step 2: Create MySQL / MariaDB Database
1. In cPanel / Hostinger Panel, go to **MySQL Databases**.
2. Create a new database (e.g. `u123456_pms_db`).
3. Create a database user (e.g. `u123456_pms_user`) with a strong password.
4. Assign full privileges (`ALL PRIVILEGES`) to the user for that database.
5. Go to **phpMyAdmin**, select your new database, and click **Import**.
6. Select `pms_core/setup.sql` and execute the import.

---

### Step 3: Configure `.env` Environment File
Copy `.env.example` to `.env` in the root folder (or inside `pms_core/`) and update your database credentials:

```env
DB_HOST=localhost
DB_NAME=u123456_pms_db
DB_USER=u123456_pms_user
DB_PASS=YourStrongPasswordHere
```

---

### Step 4: Configure Cron Job (Night Audit & Automated Notifications)
To run automated night audits, overstay alerts, and checkout reminders:
1. In Hostinger / cPanel, go to **Cron Jobs**.
2. Add a new Cron Job to run **every minute** (or every 5 minutes):
```bash
* * * * * php /home/username/public_html/cron_scheduler.php >/dev/null 2>&1
```

---

### Step 5: Verify Permissions & SSL
1. Ensure `uploads/` directory inside `public_html/assistant/` is writable (`755` or `775`).
2. Ensure SSL (HTTPS) is active for your domain (required for PWA Voice SpeechRecognition and Camera OCR features).

---

## 🔒 Security Checklist Completed
- [x] Prepared statements on all database operations
- [x] CSRF protection enabled via `CsrfToken.php`
- [x] Password and PIN hashing via `password_hash()` (bcrypt)
- [x] Direct file access blocking (`.htaccess` with `Deny from all` on `pms_core/`)
- [x] Security headers (`X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection`)
