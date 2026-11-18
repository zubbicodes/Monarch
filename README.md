<div align="center">

<img src="branding/monarch-banner.png" alt="Monarch – Site Migration" width="100%">

# Monarch – Site Migration

**Move a complete WordPress site to another server, including large databases that other migration plugins fail to import.**

[![Latest release](https://img.shields.io/github/v/release/zubbicodes/Monarch?label=release)](https://github.com/zubbicodes/Monarch/releases/latest)
[![Release workflow](https://github.com/zubbicodes/Monarch/actions/workflows/release.yml/badge.svg)](https://github.com/zubbicodes/Monarch/actions/workflows/release.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
![WordPress 5.6+](https://img.shields.io/badge/WordPress-5.6%2B-21759b)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)

[Download](https://github.com/zubbicodes/Monarch/releases/latest) · [How it works](#how-it-works) · [FAQ](#faq) · [Contributing](CONTRIBUTING.md)

</div>

---

Monarch packages your database, media, plugins, themes and the rest of `wp-content` into one file and restores it on another WordPress site. The package can move between servers in three ways:
- sent directly with **[croc](https://github.com/schollz/croc)**, end-to-end encrypted with a one-time code phrase;
- uploaded in resumable chunks;
- downloaded and uploaded by hand.

It was built for the point where most migration tools break: **importing the database**. Everything runs in small steps that each save their position, so the size of your site doesn't matter, and neither do your host's PHP time or memory limits.

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
  - [1. Export the source site](#1-export-the-source-site)
  - [2. Move the package](#2-move-the-package)
  - [3. Import on the destination site](#3-import-on-the-destination-site)
  - [Handling errors](#handling-errors)
  - [Backups](#backups)
  - [Settings](#settings)
- [How it works](#how-it-works)
- [croc transfer in detail](#croc-transfer-in-detail)
- [Security](#security)
- [FAQ](#faq)
- [Troubleshooting](#troubleshooting)
- [Development](#development)
- [Releases](#releases)
- [Contributing](#contributing)
- [Fun fact: the name and the logo](#fun-fact-the-name-and-the-logo)
- [License](#license)

## Features

### Database handling that doesn't give up

- **Chunked export.** Tables are read page by page. Pagination uses the primary key where possible, so even millions of rows stay fast. Rows are written as multi-row `INSERT`s capped at 512 KB, one row per line.
- **Chunked import.** SQL is read straight out of the package, one statement at a time, from a saved byte offset. Each request works for a few seconds, saves its position and continues in the next one.
- **Zero downtime until the last second.** Data is imported into temporary tables. The live site keeps working until one atomic `RENAME TABLE` swaps every table in at once.
- **Automatic cross-server repairs**:
  - unknown collations (`utf8mb4_0900_ai_ci` on MariaDB or MySQL 5.7);
  - servers without `utf8mb4`;
  - "Specified key was too long";
  - unknown storage engines;
  - invalid zero dates;
  - duplicate keys;
  - small `max_allowed_packet` limits, by splitting large `INSERT`s;
  - "MySQL server has gone away", by reconnecting and retrying.
- **Retry, Skip or Roll back.** If a statement still fails, you see the exact error, its statement number and the SQL, and you decide what happens next.

### Safe search and replace

- Old URLs and server paths are replaced with the new ones in every table, **inside serialized PHP data too**. A tokenizer recalculates string lengths without calling `unserialize()`, so objects of classes the destination doesn't have survive intact.
- It covers `http://`, `https://` and protocol-relative `//` URLs, JSON-escaped (`https:\/\/`) and URL-encoded variants, and tables without a primary key.
- You can add your own extra find and replace pairs.

### Flexible content selection

You can include or leave out:
- the database, with options to skip spam and trashed comments, post revisions and transients;
- uploads (the media library);
- plugins;
- themes;
- must-use plugins;
- other `wp-content` folders.

Cache folders and other backup plugins' archives are always skipped.

### Three ways to transfer

| Method | Best for |
|--------|----------|
| **croc** | Server to server, no download or upload through your browser. Fast and encrypted. |
| **Chunked upload** | Any host. Files of any size go up in pieces, and a dropped connection resumes where it stopped. |
| **Download** | Keeping an offline copy, or moving the file yourself. Downloads support resuming. |

### Clear, live feedback

- **Activity log.** A live, timestamped log with per-table and per-batch detail and every automatic repair. You can filter it to warnings, copy it or download it.
- **Progress view.** A progress bar, elapsed time and estimated time remaining, row, file and table counters, and an import stage tracker (Database → Replace URLs → Files → Activate).
- **Interface.** Admin screens follow WordPress design and your admin color scheme, and work on desktop, tablet and phone.
- **Resume after a closed tab.** An interrupted job can be resumed from where it stopped.

## Requirements

| | Minimum |
|---|---|
| WordPress | 5.6 (single site) |
| PHP | 7.4, tested up to 8.3 |
| Database | MySQL 5.6+ or MariaDB 10.1+ |
| Disk space | Enough for the package. The package is roughly the size of your database plus `wp-content`. |

**For croc transfers** you also need:
- PHP `proc_open()` enabled;
- outgoing TCP access to the croc relay (ports 9009–9013 for the public relay).

If your host blocks either one, use upload or download instead. Every other feature works without them.

Monarch must be installed on **both** the source and the destination site.

## Installation

1. Download **`monarch-site-migration.zip`** from the [latest release](https://github.com/zubbicodes/Monarch/releases/latest).
   > Use the `monarch-site-migration.zip` attachment, not GitHub's "Source code" archive, which also contains the test suite.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip and click **Install Now**.
3. Click **Activate**. **Monarch** appears in the admin menu.

To **update**, upload the new zip the same way and choose **Replace current with uploaded**. Your backups and settings are kept.

> **Upgrading from ProBackup?** Monarch is the new name of ProBackup. Install and activate Monarch, then deactivate and delete ProBackup. Monarch takes over ProBackup's backups and settings automatically the first time it runs, and existing `.pbk` packages keep working.

The destination can be a brand-new, empty WordPress installation. Its content is replaced during the import.

## Usage

### 1. Export the source site

1. Open **Monarch → Migrate → Export**.
2. Choose what to include. Your choices are remembered for next time.
3. Click **Create package** and follow the live log. You can leave the tab open in the background.

When it finishes, you can **send it with croc** or **download** the `.pbk` file.

### 2. Move the package

**With croc (recommended)**

1. On the source site, click **Send with croc**. A code phrase such as `4821-amber-tiger-ocean-maple` appears. You can also type your own.
2. On the destination site, open **Monarch → Migrate → Import → Receive with croc**, enter the code phrase and click **Receive**.
3. Both sites show progress. The package travels directly between the servers, encrypted.

The first time you use croc on a server, Monarch offers to **install it**. This is a one-click download of the official release, verified against its SHA-256 checksum.

**With upload**

On the destination site, open **Import → Upload file** and drop the `.pbk` file onto the page. If the connection drops, choose the same file again and the upload resumes.

**Without croc on the server**

If your host doesn't allow `proc_open()`, Monarch shows you the croc commands to run on your own computer instead. For example:

```bash
# receive on your computer
CROC_SECRET="your-code-phrase" croc        # Linux / macOS
croc your-code-phrase                      # Windows
```

### 3. Import on the destination site

1. Choose the package. You can receive it, upload it, or pick it from **Saved backups**.
2. Review the summary:
   - the source URL and this site's URL;
   - WordPress, PHP and database versions;
   - table and file counts and the package size.
3. Optionally, open **Additional find & replace** and add pairs, one per line:
   ```
   old@example.com => new@example.com
   ```
4. Tick **I understand that this site will be replaced** and click **Start import**.

The import runs through four stages:

| Stage | What happens | Live site affected? |
|-------|--------------|---------------------|
| Database | SQL is imported into temporary `pbt…_` tables | No |
| Replace URLs | Old URLs and paths are replaced in the temporary tables | No |
| Files | `wp-content` files are restored | Files only |
| Activate | All tables are swapped in with one atomic rename. The old tables are dropped. | Yes, in one instant |

When it's done, **log in with the source site's username and password**, because its users were imported. Then visit **Settings → Permalinks** and click **Save** once to refresh your rewrite rules.

**Kept on the destination**
- This site's URL (`siteurl` / `home`), so the migrated site runs at its new address.
- Monarch itself stays installed and active.
- The table prefix is converted automatically if the two sites use different prefixes.

### Handling errors

If a database statement fails after all automatic repairs, the import pauses and shows:
- the statement number and the database error;
- the SQL, if you want to see it;
- three choices:
  - **Retry**, for example after your host raises a limit;
  - **Skip statement and continue**;
  - **Cancel and roll back**, which drops the temporary tables and leaves your live site untouched.

If you close the tab during a job, reopen Monarch and click **Continue** to resume where it stopped.

### Backups

The **Backups** tab lists every package on the server, with its size and date. For each one you can **Download**, **Send** with croc, **Restore** it onto this site, or **Delete** it. Older packages are removed automatically based on the *Keep backups* setting.

### Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Time per step | 8 s | How long each request works before saving and continuing. Lower it if your host stops requests early (some limit them to 10–30 s). |
| Keep backups | 5 | Number of packages to keep. Older ones are deleted. `0` keeps all. |
| On uninstall | Off | Also delete all backups when the plugin is deleted. |
| croc binary | Auto | Path to your own croc binary, if you'd rather not use the automatic install. |
| Relay / Relay password | Public relay | Use your own [croc relay](https://github.com/schollz/croc#self-host-relay). Both sites must use the same one. |

## How it works

```
 SOURCE SITE                                      DESTINATION SITE
 ┌──────────────────────┐                         ┌───────────────────────────────┐
 │ DB dump (paged)      │                         │ 1. SQL → temporary tables     │
 │ wp-content scan      │──► site.pbk ──croc────► │ 2. URL / path replace         │
 │ package.json         │      (or upload)        │ 3. Files → wp-content         │
 └──────────────────────┘                         │ 4. Atomic RENAME TABLE swap   │
                                                  └───────────────────────────────┘
```

**Step machine.** Every long operation (export, import, upload) is a series of AJAX requests:
- Each request does as much work as fits in the time budget. It stops early at 75% of `memory_limit`.
- It then writes its cursor (table, last primary key, byte offset) to a JSON state file and returns.
- The browser calls again immediately.
- Network errors and server errors (`5xx`) are retried with exponential backoff.
- A lock file prevents two requests from working on the same job at once.

**The `.pbk` format.** A simple streaming archive that can be appended to:
- the magic bytes `PBK1`;
- one entry per file: a 14-byte header (path length, 64-bit size, modification time), then the path, then the raw data;
- an end marker.

Entries appear in this order: `package.json` (a manifest with source URLs, paths, versions, table prefix and tables), `database.sql`, then `content/…` files. Writers can resume halfway through a file, and readers can start from any byte offset.

**The SQL dump.**
- Every statement is preceded by a `-- PB-STMT` marker line, so the importer can split statements without parsing SQL.
- Table names use a `{{PB_PREFIX}}` placeholder, which makes changing the prefix trivial.
- Binary columns are written as hexadecimal.

## croc transfer in detail

- **Version.** Monarch uses croc **v11.6.5**. It picks the right build for the server's operating system and CPU (Linux, macOS, Windows, FreeBSD; x86-64, ARM64, ARM, 32-bit). It installs the binary only if its SHA-256 checksum matches the official checksums file.
- **Hidden code phrase.** croc runs in the background. The code phrase is passed in the `CROC_SECRET` environment variable, so it never appears in the server's process list.
- **Progress.** Monarch reads progress from croc's output and shows it live, including the raw croc output in the log.
- **Encryption.** Transfers are end-to-end encrypted (PAKE + AES-256). The relay only passes encrypted data along.
- **Cancel.** You can cancel a transfer at any time; the background process is stopped.

## Security

- **Admins only.** All actions require the `manage_options` capability and a valid nonce. Every AJAX sub-action is on a whitelist.
- **Protected storage.** Backups are stored in `wp-content/monarch-storage-<random>/`. The folder has `.htaccess`, `web.config` and `index.php` protection, and files are only served through an authenticated download handler.
- **Archive checks.** Paths inside archives are validated before extraction. Absolute paths and `..` are rejected.
- **Shell safety.** Shell arguments are escaped, and code phrases are limited to letters, digits and dashes.
- **Treat packages as sensitive.** A `.pbk` contains your whole database, including user password hashes. Store and share it accordingly.

Found a vulnerability? Please report it privately. See [CONTRIBUTING.md](CONTRIBUTING.md#security-issues).

## FAQ

<details>
<summary><strong>Which username and password do I use after importing?</strong></summary>

The **source** site's. The users table comes with the database.
</details>

<details>
<summary><strong>How big a site can it move?</strong></summary>

There is no built-in limit. The database is processed in small chunks and files are copied in pieces across requests. Your practical limit is disk space on both servers. Files can also be capped by your browser's upload speed, but croc avoids that.
</details>

<details>
<summary><strong>Does my site go down during the import?</strong></summary>

Not while the database is importing or URLs are being replaced. That work happens in temporary tables. The switch happens in one atomic `RENAME TABLE` at the very end. Files are restored just before that.
</details>

<details>
<summary><strong>Can I move between MySQL and MariaDB, or between PHP versions?</strong></summary>

Yes. Collation and charset differences, key length limits, storage engines and packet size limits are repaired automatically. The test suite migrates from MySQL 8 / PHP 7.4 to MariaDB 10.4 / PHP 8.3.
</details>

<details>
<summary><strong>Can the source and destination use different table prefixes?</strong></summary>

Yes. Tables, the `user_roles` option and user meta keys such as capabilities are converted to the destination's prefix.
</details>

<details>
<summary><strong>Can I use it for backups, not just migrations?</strong></summary>

Yes. Create a package, keep it (or download it), and use **Restore** from the Backups tab whenever you need it.
</details>

<details>
<summary><strong>Is multisite supported?</strong></summary>

Not yet. Monarch currently migrates single sites.
</details>

<details>
<summary><strong>Is croc required?</strong></summary>

No. Upload and download work on every host. croc is the faster and more convenient option when the server allows it.
</details>

## Troubleshooting

| Problem | Solution |
|---------|----------|
| WordPress says **"Incompatible Archive"** when installing | You uploaded the "Source code" zip or a damaged file. Download `monarch-site-migration.zip` from the release assets. |
| The admin page still shows an old design after updating | Hard-refresh (Ctrl+F5) and purge any page or CDN cache (LiteSpeed, Cloudflare and similar). |
| "This host does not allow running programs" | `proc_open()` is disabled. Use upload/download, or run croc on your computer with the commands shown. |
| croc waits forever for the other side | Check that both sites use the same code phrase and the same relay, and that outgoing ports 9009–9013 are open. |
| Requests stop or time out | Lower **Time per step** in Settings, for example to 5 s. |
| A single row is larger than the destination's `max_allowed_packet` | Ask your host to raise `max_allowed_packet`, then click **Retry**, or **Skip** that statement. |
| The site shows 404 errors after the import | Go to **Settings → Permalinks** and click **Save**. |
| Something else | Download the activity log and [open an issue](https://github.com/zubbicodes/Monarch/issues). |

## Development

```
monarch-site-migration/                 The plugin (this folder is what gets zipped)
├── monarch-site-migration.php          Bootstrap, constants, autoloader
├── uninstall.php                       Cleanup on plugin deletion
├── readme.txt                          WordPress.org readme and changelog
├── license.txt
├── assets/
│   ├── admin.css                       Admin UI styles (WordPress-native)
│   ├── admin.js                        Admin single-page app (vanilla JS)
│   ├── menu-icon.svg                   Single-color admin menu icon
│   └── monarch-icon.png                Logo shown in the admin header
└── includes/
    ├── class-monarch-plugin.php        Admin page, AJAX router, download handler
    ├── class-monarch-storage.php       Protected storage, job state, locks, settings
    ├── class-monarch-archive.php       .pbk reader / writer
    ├── class-monarch-export.php        Export step machine
    ├── class-monarch-db-dump.php       Chunked SQL dump
    ├── class-monarch-import.php        Import step machine and table swap
    ├── class-monarch-db-import.php     Statement-by-statement SQL import and repairs
    ├── class-monarch-replace.php       Serialization-safe search and replace
    ├── class-monarch-croc.php          croc install, send, receive, progress
    ├── class-monarch-log.php           Live activity log
    └── class-monarch-util.php          Timers, escaping, helpers
test/                                   Docker end-to-end test suite
branding/                               Logo, icon sizes, wordmark, README banner
.wordpress-org/                         Plugin directory icons and banners
.github/workflows/                      Lint and automatic releases
```

To run the full test (requires Docker, Node.js and Bash):

```bash
cd test
docker compose up -d
bash e2e.sh upload      # or: bash e2e.sh croc
docker compose down -v
```

The test migrates a deliberately difficult site from MySQL 8 / PHP 7.4 to MariaDB 10.4 / PHP 8.3, with a 256 KB packet limit and a different table prefix. It forces a resume on every request and checks the result with 22 assertions. See [CONTRIBUTING.md](CONTRIBUTING.md) for details.

**Hook:** `monarch_time_budget` (float, seconds) filters the time each request may work.

## Releases

Releases are automatic. When a push to `main` changes the plugin version, the [Release workflow](.github/workflows/release.yml) does the following:
- lints the code;
- builds `monarch-site-migration.zip`;
- publishes a GitHub release, using the matching changelog entry from `monarch-site-migration/readme.txt` as the release notes.

See [all releases](https://github.com/zubbicodes/Monarch/releases).

## Contributing

Bug reports, ideas and pull requests are welcome. Please read **[CONTRIBUTING.md](CONTRIBUTING.md)** first.

## Fun fact: the name and the logo

<img src="branding/monarch-icon-128.png" alt="Monarch logo" width="96" align="right">

**Why "Monarch"?** The monarch butterfly makes one of the most remarkable migrations in nature. Every autumn, millions of monarchs fly up to about 4,000 km (2,500 miles) from Canada and the northern United States to the same forests in central Mexico, a place none of them has ever seen. No single butterfly makes the whole round trip. The journey is spread across several generations, each carrying it a step further until the whole population arrives.

That is how this plugin moves a website. It doesn't try to carry everything in one huge request that times out halfway. It travels in small, steady steps, each one saving its position and handing over to the next, until the whole site arrives safely at its new home.

**What the logo means**

| Part of the logo | What it stands for |
|------------------|--------------------|
| The monarch butterfly | Migration: moving your whole site to a new home |
| The left wing, shaped like a **database cylinder** | The database, the part other migration tools fail on and the reason Monarch exists |
| The right wing, **breaking into small squares** | Your data travelling in small chunks and being put back together at the other end |
| The orange and black | The real monarch's colors |

The logo was generated with [Antigravity](https://antigravity.google), and the icon, banners and wordmark were built from it. All brand files are in [`branding/`](branding).

## Credits

- [croc](https://github.com/schollz/croc) by Zack Scholl (MIT), used for encrypted transfers. Monarch downloads it from its official releases; it is not bundled.

## License

Monarch is released under the [MIT License](LICENSE). © 2026 StratonAlly.
