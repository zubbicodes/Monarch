# Contributing to Monarch

Thanks for helping improve Monarch. This guide explains how to report problems, set up a development environment, and get a change merged.

## Reporting bugs

Open an [issue](https://github.com/zubbicodes/Monarch/issues) and include:

- What you did, what you expected, and what happened instead.
- **The activity log.** In Monarch, use the log's *Download* button and attach the `.txt` file. It already contains server versions, table names, row counts and any errors.
- Source and destination environment: WordPress, PHP, and MySQL/MariaDB versions, plus your hosting provider if you know it.
- For database import errors: the statement number and error message from the error box. If you can share it, add the SQL shown under *Show SQL*.

Remove anything private (domains, emails, keys) before you post. **Never attach a `.pbk` package publicly.** It contains your full database, including user password hashes.

### Security issues

Don't open a public issue for a vulnerability. Report it privately through [GitHub Security Advisories](https://github.com/zubbicodes/Monarch/security/advisories/new). We'll confirm receipt and work on a fix before disclosure.

## Suggesting features

Open an issue that describes the problem you want solved, not only the solution. Migrations run on very different hosts, so tell us about the environment where you need it.

## Development setup

### Requirements

- Git
- Docker (with Docker Compose)
- Node.js 18 or newer (used by the test script to read JSON)
- Bash (Git Bash works on Windows)

You don't need PHP installed locally; the tests run PHP inside Docker.

### Getting started

```bash
git clone https://github.com/zubbicodes/Monarch.git
cd Monarch
cd test && docker compose up -d
```

This starts two WordPress sites built to be as different as possible:

| Site | URL | PHP | Database | Table prefix |
|------|-----|-----|----------|--------------|
| Source | http://localhost:8081 | 7.4 | MySQL 8 | `src_` |
| Destination | http://localhost:8082 | 8.3 | MariaDB 10.4, `max_allowed_packet` = 256 KB | `dst_` |

The `monarch-site-migration/` folder is mounted into both sites, so your edits apply immediately. No build step is needed.

### Running the end-to-end test

```bash
cd test
bash e2e.sh upload   # transfer by chunked upload
bash e2e.sh croc     # transfer with croc between the two containers
```

The test seeds the source site with difficult data:
- 1,500 posts, Unicode and emoji;
- serialized objects of classes that don't exist on the destination;
- JSON-escaped URLs;
- a table with no primary key and the `utf8mb4_0900_ai_ci` collation;
- binary BLOBs, a 200 KB row, and a 700 KB row larger than the destination's packet limit;
- a 5 MB file and hundreds of small files.

It then exports, transfers and imports with a time budget of 0.05 seconds per request, so every resume path runs, and checks the result with 22 assertions. All of them must pass.

To reset:

```bash
docker compose down -v
```

`test/runner.php` drives the plugin's real AJAX router from the command line. It is a **test harness only** and must never be deployed to a real site.

### Linting

```bash
# PHP syntax on the oldest and newest supported versions
docker run --rm -v "$PWD/monarch-site-migration:/p" php:7.4-cli sh -c 'find /p -name "*.php" -exec php -l {} \;'
docker run --rm -v "$PWD/monarch-site-migration:/p" php:8.3-cli sh -c 'find /p -name "*.php" -exec php -l {} \;'

# JavaScript syntax
node --check monarch-site-migration/assets/admin.js
```

GitHub Actions runs the PHP 7.4 and JavaScript checks on every push to `main`.

## Code guidelines

Monarch has to work on cheap shared hosting, so these rules matter more than usual.

### General

- **No dependencies.** Plain PHP 7.4+, vanilla JavaScript and CSS: no Composer packages, npm packages or build step.
- **Follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/):** tabs, Yoda conditions, `array()` syntax and DocBlocks on every method.
- **Classes are autoloaded.** `Monarch_Foo_Bar` lives in `monarch-site-migration/includes/class-monarch-foo-bar.php`.
- **Prefix everything.** Options, hooks, AJAX actions and CSS classes use `monarch-site-migration` / `pb-`.

### Long-running work

- **Never do unbounded work in one request.** Every long operation is a step function that:
  - checks `Monarch_Util::time_up()`, which covers both the time budget and memory;
  - saves its cursor in the job state;
  - returns, so the browser can call it again.
- **Never load a whole file or table into memory.** Stream with `fopen`/`fgets`/`fread` and paginate queries.
- **Make every step resumable and idempotent.** A request can be killed at any moment, and running the step again must still give a correct result.
- **Log what the user would want to know.** Use `Monarch_Log::add( $job, $message, $level )` with `info`, `ok`, `warn`, `err` or `step`. Write messages in plain language and include numbers (rows, bytes, tables).

### Security

- Every AJAX sub-action checks the nonce and the `manage_options` capability. Add new sub-actions to the whitelist in `Monarch_Plugin`.
- Escape all output, sanitize all input, and pass shell arguments through `escapeshellarg()`.
- Validate file names and archive paths. The archive reader rejects absolute paths and `..`.

### Database compatibility

- Test against both MySQL 8 and MariaDB. The Docker setup already gives you one of each.
- If you add an automatic repair to `Monarch_DB_Import`, log it as a `warn` entry so users can see what changed.

## Submitting a pull request

1. Fork the repository and create a branch from `main`, for example `fix/collation-mariadb-10-3`.
2. Keep the change focused: one fix or feature per pull request.
3. Run the end-to-end test and the lint commands, and make sure everything passes.
4. If you change the UI, add a screenshot to the pull request. Check it at desktop and phone widths, and in a non-default admin color scheme.
5. Describe *what* changed and *why*. Link the issue it fixes.
6. Use clear commit messages in the imperative mood, for example "Split oversized INSERTs on MariaDB".

## Releases

Maintainers release by bumping the version number. Nothing else needs to be done by hand.

1. Update the version in three places:
   - the `Version:` header in `monarch-site-migration/monarch-site-migration.php`;
   - the `MONARCH_VERSION` constant in the same file;
   - `Stable tag:` in `monarch-site-migration/readme.txt`.
2. Add a `= x.y.z =` section at the top of the changelog in `monarch-site-migration/readme.txt`.
3. Push to `main`.

The [Release workflow](.github/workflows/release.yml) then:
- checks that both version numbers match;
- lints the code;
- builds `monarch-site-migration.zip`;
- publishes release `vX.Y.Z` with the changelog entry as release notes.

Pushes that don't change the version only run the checks.

We use [semantic versioning](https://semver.org/):

| Change | Version part |
|--------|--------------|
| Fixes | patch |
| New features | minor |
| Changes to the `.pbk` format that older versions can't read | major |

## License

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE).
