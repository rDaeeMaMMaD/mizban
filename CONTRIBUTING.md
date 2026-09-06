# Contributing to Mizban

Thank you for considering contributing to **Mizban**! 🎉

## Code of Conduct

Be respectful, constructive, and inclusive. Harassment or offensive behavior
is not tolerated.

## How to contribute

1. **Fork** the repository and create a feature branch:
   ```bash
   git checkout -b feat/my-change
   ```
2. Make your changes with clear, focused commits.
3. Make sure every PHP file passes the syntax check:
   ```bash
   php -l path/to/file.php
   ```
4. If you touch user-visible strings, update **both** translation files:
   - `lang/fa.json` (Persian)
   - `lang/en.json` (English)
   (Keys must stay in sync — the English file is the reference for new keys.)
5. Run the smoke tests and a quick sanity check of the flows you changed:
   ```bash
   php tests/smoke.php
   ```
   Describe in the PR how you verified your change.
6. Open a pull request with a clear description and, if relevant, screenshots.

## What to avoid

- Do **not** commit real secrets, `.env`, log files, database dumps or zips
  (see `.gitignore`).
- Do **not** add dependencies — the project is intentionally dependency-free
  (no Composer). If you think a library is truly required, discuss it first
  in an issue.
- Avoid large refactors that change core behaviour; keep the request flow
  (`Client → Platform → Router → Provider → Response → Client`) intact.

## Project layout (quick map)

| Path | Purpose |
| --- | --- |
| `index.php`, `assets.js`, `assets.css` | Bilingual admin panel |
| `api.php` | HTTP entry point for the JSON API |
| `cron.php`, `worker_runner.php` | Background workers / cron |
| `core.php` | DB, auth, queue, rate limit, security helpers |
| `queue.php`, `worker.php`, `dispatcher.php`, `scheduler.php` | Queue & worker services |
| `providers/`, `providers.php` | Provider drivers (plugin-style) |
| `config.php`, `.env.example` | Configuration |
| `schema.sql`, `migrate.php` | Database schema & migrations |
| `lang/` | fa / en translations |

## Issues

Use issue templates when provided. Bug reports should include:

- PHP version and MySQL/MariaDB version
- Expected vs actual behaviour
- Steps to reproduce
- Relevant log lines (without secrets)

Thanks again — every contribution matters. ❤️
