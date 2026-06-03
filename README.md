# MagDyn

PHP 7+ / MySQL admin console built on the MagDyn design system.

## What's in here

| Layer | Files |
|---|---|
| **Config (preserved on upgrade)** | `config/app.config.php`, `config/db.config.php` |
| **Bootstrap & helpers** | `includes/bootstrap.php`, `includes/db.php`, `includes/helpers.php`, `includes/csrf.php`, `includes/auth.php`, `includes/permissions.php`, `includes/sso.php`, `includes/header.php`, `includes/footer.php` |
| **Auth** | `login.php`, `logout.php`, `sso_begin.php`, `sso_callback.php` |
| **Module pages** | `index.php` (Dashboard), `users.php`, `roles.php`, `modules.php`, `mobile_settings.php`, `notifications.php`, `training.php`, `audit.php` |
| **PWA** | `manifest.php`, `service-worker.js`, `mobile/index.php`, `api/push_subscribe.php` |
| **Database** | `sql/schema.sql` |
| **Design** | `assets/css/magdyn-base.css` (vendor), `assets/css/app.css` (overrides), `assets/img/{logo,icon-192,icon-512}.png`, `assets/screenshots/training-{1,2,3}.svg` |
| **JS** | `assets/js/shortcuts.js`, `assets/js/app.js` |
| **First-run seed** | `setup.php` (delete after running) |

## Install

1. Drop the repo under your web root, e.g. `/var/www/magdyn`.
2. Create a MySQL database and import the schema:
   ```sh
   mysql -u root -p magdyn < sql/schema.sql
   ```
3. Open `config/db.config.php` and set host/port/db/user/password.
4. Open `config/app.config.php`:
   - Set `base_url` to the path the app is served under (`/magdyn`, or `''` for the document root).
   - Set `password_pepper` to a long random string.
   - If you want SSO, set `sso.enabled = true` and fill in the chosen mode block.
   - If you want web-push, fill in the `vapid` keys (generate with the `web-push` CLI or the `minishlink/web-push` library).
5. Hit `https://your-host/setup.php` once — it creates the seed users:
   - `admin` / `admin123` (Administrator)
   - `viewer` / `viewer123` (Viewer)
6. **Delete `setup.php`** — it's a one-shot bootstrap and shouldn't stay reachable.
7. Sign in at `https://your-host/login.php`.

## Architecture notes

### Configuration kept separate from code
`config/app.config.php` and `config/db.config.php` hold every site-specific setting. Upgrades that replace the rest of the code do not need to touch these files. Both directories have a deny-all `.htaccess` to keep configs off the web.

### Pluggable SSO
`config/app.config.php` → `sso` switches between `local` (default), `oidc`, `saml`, and `header`. The dispatch lives in `includes/sso.php`; OIDC/SAML are stubs ready for a library like `jumbojett/openid-connect-php` or `onelogin/php-saml`. Local users and SSO users share one `users` table (the canonical "global login schema") — SSO users carry `sso_provider` + `external_id`, and on first sign-in auto-provisioning creates a local row with the default role.

### Granular RBAC
`modules` × `permissions` × `roles` × `role_permissions` × `user_roles`. The Roles & Permissions page exposes the full matrix. Adding a new permission only requires inserting into the `permissions` table (or extending `sql/schema.sql` and rolling forward) — no code changes.

### "View as user" (impersonation)
Admins with `users.impersonate` see a "View as" button on every user row. After clicking, `$_SESSION['impersonate_uid']` is set and `current_user()` resolves to the target user. The yellow banner at the top of every page shows the active impersonation and offers `Alt+X` / "Exit view-as". Start and stop events go to `audit_log`.

### PWA + push notifications
- `manifest.php` returns the manifest dynamically (so theme colour and start URL respect `base_url`).
- `service-worker.js` lives at the app root so its scope covers the whole app. It pre-caches the shell, network-first for HTML, cache-first for assets, and listens for `push` + `notificationclick`.
- `mobile/index.php` is the PWA start URL — a chrome-less tile grid showing only the modules the user has selected on `mobile_settings.php`.
- Users enable push from `mobile_settings.php` via the "Enable push" button, which subscribes via `assets/js/app.js` and posts the subscription to `api/push_subscribe.php`. Sending pushes is a separate server-side job; rows live in `push_subscriptions` ready for a library like `minishlink/web-push`.

### Notification preferences
Each user picks their channels (in-app, email, push) per notification type on `notifications.php`. New notification types are added by inserting into `notification_types`.

### Keyboard shortcuts
`assets/js/shortcuts.js` implements:
- **Alt-highlight**: holding Alt adds `.alt-active` to `<body>`, which highlights every `<u>` shortcut letter and outlines `[data-shortcut]` buttons.
- **Global** shortcuts (Alt+H/U/T/N/L/X/`/`, Esc).
- **Local** shortcuts: any `[data-shortcut="X"]` element is clicked when its letter is pressed. Pages register custom shortcuts via `MagDyn.shortcuts.register('Q', fn)`.
- **Underscored letters** in labels — every button/menu uses the `shortcut_label('Save','S')` helper, which wraps the letter in `<u>`.
- **Initial focus** — pages set `$focus_id` before including `includes/header.php`; the script focuses that element on `DOMContentLoaded`. Tab order is set via explicit `tabindex` attributes on every interactive control.

### Training with role-based access
- Courses live in `training_courses` with rich HTML bodies and any number of screenshots.
- `training_role_access` decides which roles see which courses.
- Each course's edit page has a screenshot uploader (PNG/JPG/GIF/WEBP, max size from `upload_max_mb`).
- The seed includes a "Welcome to MagDyn" course with three SVG placeholder screenshots under `assets/screenshots/`.

### File naming with IST timestamps
Source files carry the IST creation timestamp in their header comment, e.g. `Created: 20260515_060024_IST`. The SQL schema is also dated. Filenames themselves are stable because PHP routing and the service-worker scope depend on it; if you want versioned releases, tag the repo or include the IST timestamp in your zip filename when shipping.

## Default keyboard shortcuts cheat-sheet

| Combo | Action |
|---|---|
| `Alt+H` | Dashboard |
| `Alt+U` | Users |
| `Alt+T` | Training |
| `Alt+N` | Notifications |
| `Alt+L` | Sign out |
| `Alt+X` | Exit "View as" |
| `Alt+/` | Focus search |
| `Esc` | Close modal / blur input |
| `Alt+letter` | Any underlined letter on the page |

## Upgrade path

To deploy a new version without losing config:
1. Zip up the changed files only, **preserving folder structure**.
2. Unzip over the existing install.
3. `config/` and `uploads/` are untouched.
4. If schema changes, ship a `sql/migration_YYYYMMDD_HHMMSS_IST.sql` and apply it manually.

## Apache notes

- `mod_rewrite` is **not** required — the app uses flat `.php` files.
- The deny-all `.htaccess` files inside `config/`, `includes/`, and `sql/` are critical; verify they are honoured (set `AllowOverride All` on the directory if needed).
- For nginx, replicate by `location ~ ^/(config|includes|sql)/ { return 403; }` and `location /uploads/ { location ~ \.php$ { return 403; } }`.
