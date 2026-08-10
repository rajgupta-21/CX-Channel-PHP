# Deploying CX-Channel-PHP on cPanel

The app runs on standard shared hosting (Apache + PHP + MySQL). Verified against Apache 2.4 + PHP 8.2.

## Requirements
- PHP **8.1 or newer** (select via cPanel → *Select PHP Version*)
- PHP extensions: `pdo_mysql`, `mbstring` (both on by default in most cPanel PHP configs)
- MySQL database (create in cPanel → *MySQL Databases*)

## 1. Upload the code
Upload the project to a folder inside `public_html` (or `public_html` itself).

**Important — after uploading, create `/path/.env`** from the bundled `.env.example`:
```bash
cp .env.example .env
nano .env   # set your real DATABASE_URL + SMTP values
```

For cPanel MySQL:
```
DATABASE_URL="mysql://myuser_mydbuser:myuser_dbpass@localhost:3306/myuser_cxchannel"
```

`localhost` is the correct host for databases created inside cPanel. The username/database name get your cPanel account prefix (shown in *MySQL Databases*).

## 2. Upload dependencies (PHPMailer)
The `vendor/` folder is git-ignored, so it is **not** in the repo. Do one of:
- **Terminal:** run `composer install --no-dev` in the project folder (cPanel usually has Composer; otherwise enable it in *Setup PHP Composer Application*), or
- **Manually:** run `composer install` locally and upload `composer.json`, `composer.lock` and `vendor/` via FTP.

## 3. Set up the database
Option A — the app auto-creates `server/uploads/` at runtime.
Option B — create tables manually by importing `schema.sql` in cPanel → *phpMyAdmin* → your database → *Import*.

Then create the login users:
```bash
php seed.php        # creates admin/admin123, service/service123, customer1/cust123
```

## 4. Point the domain / subdomain at the app
- Put the app files in `public_html` for your main domain, **or**
- In cPanel → *Subdomains* → create a subdomain (e.g. `portal.example.com`) with document root = the project folder.

If the app lives in a **subfolder** (not the docroot), add one line at the top of `.htaccess`:
```
RewriteBase /your-subfolder-name
```

## 5. Permissions
- `server/uploads/` must be writable by PHP (set to `755`; the app creates missing folders automatically).
- Files `644`, folders `755` (cPanel defaults are fine).

## 6. Optional hardening
- The real SMTP password lives in `.env`. That file is git-ignored — do not commit it.
- Rotate the SMTP password if the old `.env` was ever pushed to a public repo (it accidentally was: see git history `HEAD:.env`).
- For HTTPS, use cPanel's *AutoSSL* / Let's Encrypt — the app has no hardcoded `https://` URLs, it detects the origin.

## Verification checklist
- `https://yourdomain/` → landing page loads
- `https://yourdomain/api/stats` → valid JSON
- Log in with `admin/admin123`
- Submit a request (uploads an image) → confirmation email sent
- Approve a request → customer gets RMA approval email
- Team dashboard → CSV export downloads