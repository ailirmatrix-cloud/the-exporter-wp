=== The Exporter ===
Contributors: theexporter
Tags: migration, export, import, large site, backup
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Verification-first WordPress migration for very large sites (50GB+).

== Description ==

The Exporter supports browser transfer (small sites), manual folder transfer (SFTP / cPanel), and Connected site mode (automatic HTTPS push between export and import hosts).

**Features:**

* Export database, uploads, themes, plugins, mu-plugins, custom wp-content dirs, and config as separate packages
* Chunked segments (500MB–2GB) — never a single huge archive
* SHA-256 checksums for every file and segment
* Migration manifest with metadata, versions, and validation info
* Individual package import with pre-validation and dry-run
* Resumable jobs, restore points, and audit logging
* WP-CLI commands for production use
* Modern dark glassmorphism admin UI

== Installation ==

1. Upload the plugin to `/wp-content/plugins/the-exporter/`
2. Activate through the 'Plugins' menu
3. Go to The Exporter in the admin menu

== Usage ==

**Export (source server):**

1. Dashboard → Start New Export
2. Select components and export
3. Finalize manifest
4. Download via SFTP/rsync from the export path
5. Verify checksums locally

**Import (destination server):**

1. Upload packages via SFTP to the import path
2. Run validation
3. Confirm manual verification
4. Import components in recommended order

**WP-CLI:**

`wp the-exporter export init`
`wp the-exporter export component database --migration-id=<id>`
`wp the-exporter export finalize --migration-id=<id>`
`wp the-exporter import validate --migration-id=<id>`
`wp the-exporter import database --migration-id=<id> --confirm`

== Changelog ==

= 1.0.0 =
* Initial release
