# Security Policy

## Supported versions

Security fixes are applied to the latest stable release. Older releases are
supported on a best-effort basis.

| Version | Supported |
| --- | --- |
| latest | ✅ |
| older | ⚠️ best effort only |

## Reporting a vulnerability

Please **do not open a public issue** for security problems. Instead email the
maintainer or open a private security advisory on GitHub
(GitHub → *Security* → *Report a vulnerability*).

Include in your report:

1. Type of issue (e.g., XSS, SQL injection, IDOR, auth bypass, secret leak)
2. Affected endpoint/file
3. Steps to reproduce (without leaking production data)
4. Suggested fix (optional)

You should receive a response within 7 days. We will keep you updated while
the issue is triaged and fixed.

## Security notes for operators

- Delete or re-secret the **demo clients** (`miz_basic_001` / `miz_vip_001`) that
  are auto-seeded on first run before exposing the gateway publicly, or at least
  replace their sample secrets (`basic_secret_CHANGE_ME` / `vip_secret_CHANGE_ME`).

- **HTTPS is mandatory** in production.
- Set a strong random `MIZBAN_MASTER_SECRET` (64 hex chars). Without it, API
  key encryption is disabled (fail-closed).
- Change the default admin password (`admin` / `admin123`) immediately
  after install from the panel: sidebar → *Change password*.
- Never commit `.env`. Protect it with `.htaccess` (see `.htaccess.example`).
- Rotate any provider API key that may have leaked.
- Use cron over CLI (`php cron.php ...`) instead of HTTP URLs where possible;
  HTTP cron requires the random `MIZBAN_CRON_TOKEN`.
- `boroto.php` is a development console. Set a strong `BOROTO_TOKEN`
  environment variable before using it; **proxy and log endpoints are
  disabled when the token is empty**. Even with a token, target URLs are
  validated against private/internal addresses (SSRF defense).
- Callback / webhook URLs (`callback_url`) are validated against private and
  reserved IP ranges to reduce SSRF risk. Localhost and RFC1918 addresses are
  rejected.
