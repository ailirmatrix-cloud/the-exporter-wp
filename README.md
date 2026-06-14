# The Exporter

**Verification-first WordPress migration for very large sites.**

Export and import chunked, SHA-256 verified packages — built for 50GB+ sites where a single zip file is not an option.

## Features

- Separate packages for database, uploads, themes, plugins, mu-plugins, and config
- Chunked segments (500MB–2GB), never one huge archive
- Checksums and manifest validation before import
- Resumable jobs with restore points
- Manual transfer (SFTP / cPanel), connected site push, or browser transfer for small sites
- WP-CLI for production workflows

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Install

1. Download or clone into `wp-content/plugins/the-exporter/`
2. Activate **The Exporter** in **Plugins**
3. Open **The Exporter** in the admin menu

## Quick start

**Export (source site)**

1. Start a new export and select components
2. Finalize the manifest
3. Transfer packages via SFTP, connected site mode, or download

**Import (destination site)**

1. Upload packages to the import path
2. Run validation
3. Import components in the recommended order

## WP-CLI

```bash
wp the-exporter export init
wp the-exporter export component database --migration-id=<id>
wp the-exporter export finalize --migration-id=<id>
wp the-exporter import validate --migration-id=<id>
wp the-exporter import database --migration-id=<id> --confirm
```

## Development

```bash
npm install
npm run build
```

## License

GPL-2.0-or-later
