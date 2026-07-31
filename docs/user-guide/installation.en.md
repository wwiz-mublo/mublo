# Installation Guide

**English** | [한국어](installation.md)

This guide covers the minimum production-oriented installation path for Mublo 1.0. For detailed hosting-specific permission guidance, refer to the [Korean installation guide](installation.md).

## Requirements

| Component | Minimum | Recommended for new deployments |
|---|---:|---:|
| PHP | 8.2 | Current supported PHP release |
| MySQL | 5.7.8 | MySQL 8.4 LTS |
| MariaDB | 10.3 | MariaDB 10.11 LTS |
| Web server | Apache or Nginx | Current stable release |

Required PHP extensions:

```text
pdo pdo_mysql mysqli mbstring openssl json curl fileinfo gd
```

Recommended extensions:

```text
zip xml intl
```

Recommended PHP settings for an operational installation:

```ini
memory_limit = 256M
upload_max_filesize = 20M
post_max_size = 25M
max_execution_time = 60
```

## 1. Get Mublo

### Release archive

Download the latest release from:

https://github.com/wwiz-mublo/mublo/releases/latest

The release archive includes production Composer dependencies.

### Source checkout

```bash
git clone https://github.com/wwiz-mublo/mublo.git
cd mublo
composer install --no-dev --optimize-autoloader
```

## 2. Configure the web root

Point the web server document root to the project's `public/` directory, not to the repository root.

```text
/var/www/mublo/
├── config/
├── packages/
├── plugins/
├── public/          <- web server document root
├── src/
├── storage/
└── views/
```

Apache requires URL rewriting. Nginx should route unresolved requests to `public/index.php` while serving existing static files directly.

## 3. Set writable directories

The PHP process must be able to create and update files in:

```text
config/
storage/
public/storage/
```

On a server where the deployment user and PHP process share the `www-data` group, one possible setup is:

```bash
chown -R deploy:www-data config storage public/storage
chmod 2770 config storage
chmod 2775 public/storage
```

Hosting environments use different users and groups, so adjust ownership rather than copying these commands blindly. Avoid recursive `777` permissions. The installer performs a real write test and reports which directories need attention.

## 4. Run the web installer

Open the following URL in a browser:

```text
https://your-domain.com/install
```

The installer guides you through six stages:

1. environment and directory checks;
2. database connection and migrations;
3. initial domain and site settings;
4. encryption, CSRF, session, and password settings;
5. administrator account creation;
6. installation completion.

If the database does not exist, the installer can create it when the configured database user has `CREATE DATABASE` permission. Otherwise, create the database first and enter its name in the installer.

## 5. Verify the installation

Open the front page:

```text
https://your-domain.com/
```

Open the administration area:

```text
https://your-domain.com/admin
```

Sign in with the administrator account created by the installer.

## 6. Apply post-install security steps

Remove the installer after a successful installation:

```bash
rm -rf public/install/
```

Confirm that generated configuration files and the installation lock are not publicly accessible and are readable only by the required deployment and PHP users. The installer attempts to create sensitive files with mode `600`; a shared group deployment may use `640` instead.

At minimum, review:

```text
config/database.php
config/app.php
config/security.php
config/mail.php
storage/installed.lock
```

For production, use:

```env
APP_ENV=production
APP_DEBUG=false
```

Keep `storage/` and `public/storage/` writable by PHP because they store runtime data and uploads.

## Troubleshooting

### The installer does not open

- Confirm that `public/` is the web server document root.
- Confirm that URL rewriting is enabled.
- Confirm that required PHP extensions are installed.
- Check the web server and PHP error logs.

### Database connection fails

- Verify the host, port, username, and password.
- Confirm that the database server is running.
- If the database user cannot create databases, create one manually first.
- Confirm that the server version meets the minimum requirement.

### Directory checks fail

- Compare the directory owner and group with the PHP process user.
- Grant write access through correct ownership or group membership.
- Do not apply recursive executable permissions to configuration and uploaded files.

### Mublo reports that it is already installed

The presence of `storage/installed.lock` intentionally blocks the installer. Do not remove the lock on a production site unless you are deliberately rebuilding the installation and have a verified backup.

---

[Back to the English README](../../README.en.md) | [Korean installation guide](installation.md)
