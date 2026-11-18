=== Monarch – Site Migration ===
Contributors: stratonally
Tags: migration, backup, clone, move site, croc
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Lightweight whole-site migration with a chunked, resumable database import that does not time out — plus encrypted server-to-server transfer powered by croc.

== Description ==

Monarch moves a complete WordPress site (database, media, plugins, themes, must-use plugins and other wp-content folders) to another server.

**Built for big databases**

* Export reads every table page by page (primary-key pagination) and writes multi-row INSERTs capped at 512 KB, one row per line.
* Import reads the SQL straight out of the archive one statement at a time, from a saved byte offset. Each request works for a few seconds, saves its position and continues, so PHP time limits and memory limits never matter.
* Data goes into temporary tables first. Your live site keeps working until the very last step, when all tables are swapped in with one atomic RENAME TABLE.
* Automatic repairs for cross-server issues: unknown collations (utf8mb4_0900_ai_ci on MariaDB/MySQL 5.7), servers without utf8mb4, "key was too long", unknown storage engines, zero dates, small max_allowed_packet (statements are split), duplicate keys, and "MySQL server has gone away" (reconnect and retry).
* If a statement still fails, you see the exact error and can Retry, Skip it, or Cancel and roll back (temporary tables are dropped; nothing on the live site changed).
* URL and path replacement is serialization-safe without calling unserialize() (a tokenizer recalculates string lengths), and also handles JSON-escaped and URL-encoded URLs.

**Transfer with croc**

[croc](https://github.com/schollz/croc) sends files end-to-end encrypted between two computers using a code phrase and a relay.

1. On the source site: Export → Send with croc. Monarch starts croc in the background and shows a code phrase.
2. On the destination site: Import → Receive with croc → enter the code phrase.
3. The package travels directly between the two servers, encrypted. The code phrase is passed through the CROC_SECRET environment variable, so it never appears in the process list.

Monarch downloads the official croc release from GitHub on first use and verifies its SHA-256 checksum. You can also point it at your own croc binary and use a private relay in Settings.

Requirements for croc mode: PHP proc_open() enabled and outgoing TCP to the relay (ports 9009–9013 for the public relay). If your host blocks this, download the .pbk and upload it (chunked, resumable uploads of any size), or run croc on your own computer with the commands shown in the plugin.

== Installation ==

1. Upload the `monarch-site-migration` folder to `/wp-content/plugins/` (or upload the zip in Plugins → Add New).
2. Activate it on both the source and the destination site.
3. Open Monarch in the admin menu.

== Frequently Asked Questions ==

= Which login do I use after importing? =

The username and password of the source site, because its users table was imported.

= Where are backups stored? =

In `wp-content/monarch-storage-<random>/backups/`, protected from web access.

= Is multisite supported? =

Not yet.

== Changelog ==

= 1.1.1 =
* WordPress.org readiness: plugin slug and text domain are now monarch-site-migration.
* Database statements now run through $wpdb instead of direct mysqli calls.
* Escaped exception messages; tested up to WordPress 7.1.
* Coming from 1.1.0? Install this version, then delete the old "monarch-migration" plugin. Backups and settings are kept.

= 1.1.0 =
* ProBackup is now Monarch – Site Migration: new name, logo, admin menu icon and plugin folder (monarch-site-migration).
* Upgrading from ProBackup: Monarch takes over ProBackup's backups and settings automatically. Deactivate and delete ProBackup afterwards.
* Existing .pbk packages remain fully compatible.

= 1.0.3 =
* Monarch is now released under the MIT License (license.txt included in the plugin).
* New README with full documentation, and a contributing guide.

= 1.0.2 =
* Full-width admin screens, improved layout on tablets and phones.
* Removed the external "About croc" link from the header.
* Releases are now built and published automatically by GitHub Actions.

= 1.0.1 =
* Live, detailed activity log and redesigned admin screens.
* Assets are cache-busted on every update.

= 1.0.0 =
* First release.
