# Form Dashboard

Centralized PHP/MySQL dashboard for monitoring form submissions across many WordPress sites. Supports Forminator, Contact Form 7, Gravity Forms, WPForms, Fluent Forms, and Elementor Forms.

## How it works

Each WordPress site runs a small bridge plugin. When a form is submitted, the plugin POSTs the submission to this dashboard's `/ingest.php` endpoint with an HMAC-signed body. The dashboard verifies the signature, stores the submission, fires email alerts that match, and shows everything in a single UI.

```
[WP site #1] ─┐
[WP site #2] ─┼──► HTTPS POST + HMAC ──► dashboard /ingest.php ──► MySQL ──► UI
[WP site #N] ─┘
```

## Why this architecture (vs pull or direct DB)

Pulling via REST API would require six different scrapers, one per form plugin, and most of them don't expose submissions through REST anyway. Direct MySQL access across 16-50 sites is a security and operations headache. A push webhook fires the moment a submission happens, works the same for all six plugins, and only needs outbound HTTPS from each site.

## Server requirements

- PHP 8.0+
- MySQL 5.7+ or MariaDB 10.3+
- HTTPS for the dashboard (HMAC signing assumes nobody is reading payloads in transit)

## Install

1. Upload the project to your server. Point your web server document root at the `public/` directory. (If you can't, the included `.htaccess` blocks PHP execution outside `public/`.)

2. Create a MySQL database and user:

   ```sql
   CREATE DATABASE form_dashboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'form_dashboard'@'localhost' IDENTIFIED BY 'a-strong-password';
   GRANT ALL ON form_dashboard.* TO 'form_dashboard'@'localhost';
   ```

3. Copy `config.php` to `config.local.php` and fill in your DB credentials, base URL, and timezone.

4. Run the installer once from the command line. It creates the schema and prompts for the first admin account:

   ```
   php install.php
   ```

5. Visit `https://your-dashboard.example.com/login.php` and sign in.

## Adding a WordPress site

1. In the dashboard, go to **Sites → Add site**. Enter a name and URL. You'll be shown an API key and a secret. The secret is shown once — copy it.

2. On the WordPress site, install the plugin from `wp-plugin/form-dashboard-bridge/`. Either zip that folder and upload via the WP plugin uploader, or copy it into `wp-content/plugins/`. Activate it.

3. In WP, go to **Settings → Form Dashboard**. Paste:
   - Ingest endpoint: `https://your-dashboard.example.com/ingest.php`
   - API key: (from step 1)
   - Secret: (from step 1)

4. Click **Send test ping**. You should get an HTTP 200 response. The site appears in the dashboard with a `last_seen` timestamp.

5. Submit a form on the WP site. It should show up under **Submissions** within a second.

## Features

- Overview with totals, a 30-day chart, top sites by volume, recent submissions
- Sites: add/pause/delete, rotate webhook secrets
- Forms: list of every form auto-discovered across all sites, filter by site or plugin
- Submissions: full-text search across payload, filter by site/form/plugin/date range, view individual submission with all fields, paginated
- CSV export of any filtered view
- Email alerts: rules can match any-site/any-form, a specific site, or a specific form
- User accounts with admin/viewer roles
- HMAC-signed webhooks with timestamp anti-replay
- Webhook log table for debugging

## Security notes

- The HMAC secret is stored in plaintext in the `sites.secret_hash` column because it has to be used to verify incoming HMACs. Restrict DB access accordingly.
- Rotate a site's secret from **Sites → Rotate** if it's ever exposed. Update the WP plugin afterwards.
- The ingest endpoint rejects requests with a timestamp more than 5 minutes off (`abs(time() - timestamp) > 300`), which prevents replay of captured payloads.
- Sessions use `httponly` and `samesite=Lax` cookies. Run the dashboard over HTTPS and the `Secure` flag is set automatically.

## Performance

For 16-50 sites with normal contact-form traffic, this runs comfortably on a 1-CPU/2GB VPS. The expensive query is full-text search over `payload_json` — if you ever need it to scale further, add a `FULLTEXT` index or a generated column for the most-searched fields.

The retry queue in the WP plugin caps at 500 pending submissions and gives up after 5 attempts per item, so a long dashboard outage won't fill up `wp_options`.

## File layout

```
form-dashboard/
├── config.php              # template — copy to config.local.php
├── install.php             # CLI installer
├── sql/schema.sql          # database schema
├── src/
│   ├── bootstrap.php       # DB, session, helpers
│   ├── layout.php          # shared header/sidebar
│   └── layout_foot.php
├── public/                 # web docroot
│   ├── index.php           # overview
│   ├── sites.php
│   ├── forms.php
│   ├── submissions.php     # list, detail, CSV export
│   ├── alerts.php
│   ├── users.php
│   ├── login.php
│   ├── logout.php
│   └── ingest.php          # webhook receiver
└── wp-plugin/
    └── form-dashboard-bridge/
        └── form-dashboard-bridge.php
```
