# Mizban (میزبان)

## Self-Hosted AI API Gateway & Model Management Platform

> **Run Mizban on your own hosting or VPS, connect and manage multiple AI models and API providers, and expose them through one unified API.**

**Mizban** is a self-hosted, extensible **AI API Gateway, Model Manager, Provider Manager, Request Router, Queue Engine, and API Management Platform** built with plain PHP and MySQL.

It is designed for developers, teams, SaaS applications, websites, bots, plugins, and services that need to work with multiple AI providers and models without managing every provider independently inside the application.

Instead of connecting your application directly to many external AI APIs, you install **Mizban on your own hosting or VPS**, add providers and models from the admin panel, configure API keys, limits and policies, and then communicate with Mizban through a single unified API.

```text
Your Application
       │
       │ One unified API
       ▼
┌─────────────────────────────┐
│           Mizban            │
│                             │
│ Authentication              │
│ Rate Limiting               │
│ Request Validation          │
│ Queue Management            │
│ Model Routing               │
│ Load Balancing              │
│ API Key Pool                │
│ Health Monitoring           │
│ Circuit Breaker             │
│ Failover                    │
│ Provider Management         │
│ Request Tracking            │
└──────────────┬──────────────┘
               │
       ┌───────┼────────┬──────────┐
       ▼       ▼        ▼          ▼
   DeepSeek  Gemini  OpenRouter  NVIDIA
       │       │        │          │
       └───────┴────────┴──────────┘
                    │
                    ▼
                 Response
                    │
                    ▼
              Your Application
```

---

## Documentation

* English: this README
* فارسی: [`README.fa.md`](README.fa.md)

---

## What is Mizban?

Mizban is a centralized gateway between your applications and external AI services.

Your application may need providers such as:

* DeepSeek
* Gemini
* OpenRouter
* NVIDIA NIM
* Z.ai / GLM
* GapGPT
* RapidAPI-based AI services
* OpenAI-compatible APIs
* Custom providers (via driver)

Without a gateway, each app must handle API URLs, keys, model names, auth, rate limits, retries, failures, timeouts, fallbacks, load balancing, queues, health, logging, and client authentication separately.

Mizban centralizes those responsibilities: your app talks to **Mizban**, and Mizban talks to the right upstream provider.

The client does not need to know which provider handled the request.

---

## Why Mizban?

> **Your application should talk to one stable API. Mizban handles the complexity of multiple upstream AI providers.**

* **One API instead of many** — one integration for your apps, bots, and plugins  
* **Centralized management** — providers, keys, models, limits and policies from the admin panel  
* **Replaceable providers** — if one route fails, is slow, or hits quota, failover can use another configured route  
* **Multiple API keys** — key pools with load balancing  
* **Provider independence** — less lock-in to a single vendor  
* **Self-hosted** — your install, config, and database stay under your control  
* **Extensible** — OpenAI-compatible providers from the panel; custom formats via `providers/` drivers  

---

## Main capabilities

### AI provider management

Add and manage providers from the admin panel:

* Name, slug, base URL, type  
* Models and API keys  
* Status, health, configuration  

OpenAI-compatible providers can usually be configured without changing core source code.

### AI model management

Providers and models are separate:

```text
Provider
 ├── API Key 1
 ├── API Key 2
 └── API Key 3

Models
 ├── model-a
 ├── model-b
 └── model-c
```

Models can be enabled/disabled independently and can define a **fallback model**.

### Smart request routing

Depending on configuration, routing can use:

* Provider health  
* API key availability  
* Weights / load / latency  
* Circuit breaker state  
* Model availability  
* Client policy  
* Priority  
* Fallback configuration  

Modes:

* `specific` — client chooses the model  
* `general` — Mizban picks a route  

### Load balancing

Requests can be distributed across a provider’s key pool:

```text
                 Mizban
                    │
          ┌─────────┼─────────┐
          │         │         │
          ▼         ▼         ▼
        Key #1    Key #2    Key #3
                    │
                    ▼
                Provider
```

### Automatic failover

```text
Request → Provider A
            ├── SUCCESS → Response
            └── FAILURE → Provider B → …
```

Actual behavior depends on configured models, fallbacks, and routing policies.

### Circuit breaker

Repeated failures can temporarily open a circuit so unhealthy routes are skipped until recovery.

### Provider health monitoring

Health score, success/error counts, latency, cooldown, and availability feed into routing.

### API key management

Provider keys are managed in the panel and **encrypted at rest** (AES-256-CBC) with `MIZBAN_MASTER_SECRET`. Encryption is **fail-closed** if the master secret is missing.

> Never commit `.env`, production credentials, provider API keys, or database dumps to GitHub.

### Client management

Clients authenticate with:

* Client code  
* Client secret (stored hashed)  
* Status  
* Degree (1–10) → priority and policy tier  
* Rate limits  

Policy by degree (see `policy_engine.php`):

* Degree 1–3 — basic models only  
* Degree 4–7 — chat models  
* Degree 8–10 — includes vision / ocr / agent / tool modalities  

### Rate limiting

Multi-window limits (minute / hour / day / month) for clients and API keys (configured in `config.php` / runtime settings).

### Queue system

```text
queued → running → completed
                 → failed
                 → retry
                 → delayed
                 → dead
```

### Retry

Temporary upstream errors (timeouts, rate limits, network issues) can be retried with backoff; exhausted attempts move to `dead`.

### Idempotency & correlation

```json
{
  "idempotency_key": "order-42",
  "correlation_id": "my-trace-1"
}
```

### Sync & async

**Synchronous** — client waits for the upstream response.  
**Asynchronous** — request is accepted (`ack`, queue position, estimated time), processed by workers, then polled or delivered via callback.

### Callback / webhook

```json
{
  "async": true,
  "callback_url": "https://example.com/webhook"
}
```

Callbacks retry on failure. URLs are validated against private/localhost targets (SSRF defense).

### Request tracking

Each request stores status, provider, model, timing, attempts, result, and errors — visible in the admin panel and via `status` / `result`.

### Extensible providers

```text
providers/
└── <slug>/
    ├── manifest.json
    └── driver.php   (optional, for custom formats)
```

Bundled manifests include DeepSeek, NVIDIA, Z.ai, GapGPT. OpenRouter, Gemini, RapidAPI and others can be configured as providers (OpenAI-compatible or custom drivers).

---

## Unified API lifecycle

```text
Client
  → Authentication
  → Rate limit
  → Validation
  → Queue
  → Scheduler
  → Load balancer / Key pool / Circuit breaker
  → Provider driver
  → External AI API
  → Result (sync / callback / poll)
```

### Endpoints

| Endpoint | Method | Auth | Description |
| --- | --- | --- | --- |
| `api.php?action=send` | POST | Client | Submit an AI request |
| `api.php?action=status&id=…` | GET | Client / Admin | Check status |
| `api.php?action=result&id=…` | GET | Client / Admin | Get result |
| `api.php?action=models` | GET | Public | List active models |
| `api.php?action=policy` | GET | Client | Client policy |
| `api.php?action=cost_models` | GET | Public | Model cost info |
| `api.php?action=cost_estimate` | GET | Public | Cost estimate |
| `api.php?action=admin_*` | Various | Admin session | Admin panel APIs |
| `cron.php?worker&token=…` | GET / CLI | Cron token | Background processing |

### Sync example

```bash
curl -X POST "https://YOUR_DOMAIN/api.php?action=send" \
  -H "Content-Type: application/json" \
  -H "X-Client-Code: miz_basic_001" \
  -H "X-Client-Secret: basic_secret_CHANGE_ME" \
  -d '{
        "mode": "specific",
        "model": "gpt-4o-mini",
        "action": "chat",
        "payload": {
          "messages": [{ "role": "user", "content": "Hello!" }]
        }
      }'
```

Example response:

```json
{
  "ok": true,
  "ack": true,
  "request_id": 123,
  "status": "completed",
  "sync": true,
  "provider": "gapgpt",
  "response": {
    "choices": [
      { "message": { "content": "..." } }
    ]
  }
}
```

### General auto-routing

```json
{
  "mode": "general",
  "action": "chat",
  "payload": {
    "messages": [{ "role": "user", "content": "Hello" }]
  }
}
```

### Async example

```json
{
  "mode": "specific",
  "async": true,
  "callback_url": "https://site.example/hook",
  "idempotency_key": "order-42",
  "correlation_id": "my-trace-1",
  "model": "deepseek-chat",
  "action": "chat",
  "payload": {
    "messages": [{ "role": "user", "content": "Hi" }]
  }
}
```

### Polling

```bash
curl "https://YOUR_DOMAIN/api.php?action=status&id=123" \
  -H "X-Client-Code: miz_basic_001" \
  -H "X-Client-Secret: basic_secret_CHANGE_ME"

curl "https://YOUR_DOMAIN/api.php?action=result&id=123" \
  -H "X-Client-Code: miz_basic_001" \
  -H "X-Client-Secret: basic_secret_CHANGE_ME"
```

### Optional HMAC

When enabled (`security.hmac_enabled`):

```text
X-Timestamp
X-Nonce
X-Signature
```

Signature = HMAC-SHA256 over `method + path + timestamp + nonce + body`.

---

## Administration panel

Web admin panel includes:

* Provider / API key / model / client management  
* Request monitoring & queue visibility  
* Logs, live test, docs  
* Stats / health-oriented dashboard data  
* Password change  
* Language switch: **فارسی (RTL)** / **English (LTR)**  

Translations: `lang/fa.json`, `lang/en.json` (same keys in both).

---

## Architecture

No large framework. No Composer for the core.

```text
api.php
   → Dispatcher
   → QueueManager
   → Worker
   → Scheduler
        → Load balancer / Key pool / Circuit breaker
        → Provider driver
        → External AI API
```

### Project structure

```text
.
├── api.php / cron.php / index.php
├── worker_runner.php / migrate.php
├── core.php / dispatcher.php / queue.php / worker.php / scheduler.php
├── load_balancer.php / key_pool.php / circuit_breaker.php
├── providers.php / provider_registry.php / providers/
├── config.php / .env.example
├── lang.php / lang/fa.json / lang/en.json
├── schema.sql / VERSION.txt
├── assets.css / assets.js
├── tests/smoke.php
├── README.md / README.fa.md / CONTRIBUTING.md / SECURITY.md / LICENSE
└── .htaccess.example
```

---

## Requirements

* PHP **8.0+** (`pdo_mysql`, `curl`, `openssl`, `mbstring`, `json`)  
* MySQL **5.7+** / MariaDB **10.2+**  
* Apache / cPanel / shared hosting / VPS / local PHP+MySQL  

No Composer. No Node.js for the core backend.

Runs on cPanel, shared hosting, VPS, dedicated servers, and local environments (e.g. XAMPP).

---

## Installation

### 1. Database

Create a MySQL database and user with privileges on that database.

### 2. Upload

Copy project files to `public_html/` or a subdirectory.

### 3. Environment

```bash
cp .env.example .env
```

```ini
MIZBAN_DB_NAME=your_db_name
MIZBAN_DB_USER=your_db_user
MIZBAN_DB_PASS=your_strong_password
MIZBAN_MASTER_SECRET=64_random_hex_chars
MIZBAN_CRON_TOKEN=random_secure_token
MIZBAN_ADMIN_USER=admin
MIZBAN_ADMIN_PASSWORD=admin123
```

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # master secret
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"   # cron token
```

**Never use demo credentials in production.** Change admin password, master secret, and cron token. Do not commit `.env`, dumps, or real API keys.

### 4. First run

Open `https://YOUR_DOMAIN/index.php` — tables are initialized (`db_init`).  
Optional: import `schema.sql` and/or run `php migrate.php`.

### 5. Change admin password

Panel → Change password.

### 6–8. Configure

1. **Providers** → New provider (name, slug, base URL, type)  
2. **API Keys** → add keys (encrypted at rest)  
3. **Models** → model id, display name, provider, fallback, status  

### Background workers

```cron
* * * * * php /home/USER/public_html/cron.php worker
* * * * * php /home/USER/public_html/cron.php health
* * * * * php /home/USER/public_html/cron.php retry
* * * * * php /home/USER/public_html/cron.php lease_recovery
*/5 * * * * php /home/USER/public_html/cron.php stats
0 * * * * php /home/USER/public_html/cron.php cleanup
```

Or:

```bash
nohup php worker_runner.php --daemon &
```

HTTP cron requires a non-empty `MIZBAN_CRON_TOKEN`.

---

## Configuration

| Variable | Description |
| --- | --- |
| `MIZBAN_DB_HOST` / `NAME` / `USER` / `PASS` | MySQL |
| `MIZBAN_MASTER_SECRET` | Encryption / HMAC base (**required in production**) |
| `MIZBAN_CRON_TOKEN` | Protects HTTP cron |
| `MIZBAN_ADMIN_USER` / `PASSWORD` | Bootstrap admin (first run only; default `admin` / `admin123`) |
| `MIZBAN_DEBUG` | Internal errors to clients — keep `false` in production |
| `MIZBAN_TIMEZONE` | Default `Asia/Tehran` |
| `MIZBAN_HOST_NAME` | Brand name in the panel |
| `MIZBAN_CORS_ORIGINS` | Allowed browser origins (empty = none) |
| `BOROTO_TOKEN` | Required to use `boroto.php` proxy/log endpoints |

Queue / circuit-breaker tunables also live in the `settings` table.

---

## Security

Implemented in the codebase:

* HTTPS-ready session cookies (`HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS)  
* Login throttling, CSRF / origin checks on mutating admin actions  
* Client auth + multi-layer rate limiting  
* PDO prepared statements  
* Encrypted provider keys (fail-closed without master secret)  
* SSRF-safe callback URLs  
* Restricted upload paths for file-based actions  
* Controlled CORS  
* No internal error details unless `MIZBAN_DEBUG=true`  
* Cron token required for HTTP background endpoints  
* Session regeneration on login  

See [`SECURITY.md`](SECURITY.md).

---

## Testing & development

```bash
php tests/smoke.php
for f in *.php; do php -l "$f"; done
php cron.php worker
```

* Keep provider logic in `providers/`  
* Do not hardcode secrets  
* Keep `fa.json` / `en.json` keys in sync  
* Preserve the core request flow  

See [`CONTRIBUTING.md`](CONTRIBUTING.md).

### Adding a provider

* **OpenAI-compatible:** usually panel-only  
* **Custom:** `providers/<slug>/manifest.json` (+ optional `driver.php`)

---

## Who is it for?

* Developers who want one AI integration instead of many  
* SaaS / websites / Telegram bots / plugins needing a stable gateway  
* Internal systems that centralize upstream AI credentials  
* Operators who want self-hosted control on cPanel or VPS  

---

## Design principles

1. Centralize complexity away from clients  
2. Keep providers replaceable  
3. Fail gracefully (retry, health, failover when configured)  
4. Keep credentials out of client apps and source control  
5. Keep the core lightweight (no heavy framework stack)  
6. Stay extensible via the provider registry  
7. Self-host first  

---

## Production checklist

```text
[ ] Change default admin password (admin / admin123)
[ ] Set unique MIZBAN_MASTER_SECRET
[ ] Set unique MIZBAN_CRON_TOKEN
[ ] Enable HTTPS
[ ] Protect .env (see .htaccess.example)
[ ] Remove or re-secret demo clients
[ ] Configure cron / workers
[ ] Review CORS and callbacks
[ ] Ensure .env is gitignored and not web-accessible
[ ] MIZBAN_DEBUG=false
[ ] Read SECURITY.md
```

---

## Docs

* [`README.fa.md`](README.fa.md) — فارسی  
* [`SECURITY.md`](SECURITY.md)  
* [`CONTRIBUTING.md`](CONTRIBUTING.md)  
* [`LICENSE`](LICENSE)  
* [`.htaccess.example`](.htaccess.example)  

---

## License

**MIT License** — see [`LICENSE`](LICENSE)

**Copyright © 2026 MohammadMahd Daee**

---

## فارسی (خلاصه)

**Mizban (میزبان)** یک درگاه Self-Hosted برای مدیریت Providerها، کلیدها، مدل‌ها، صف، مسیریابی، Failover و احراز هویت کلاینت‌هاست. آن را روی هاست یا VPS نصب کنید و به‌جای اتصال مستقیم هر برنامه به ده‌ها API، فقط با یک API واحد صحبت کنید.

مستندات کامل فارسی: [`README.fa.md`](README.fa.md)

---

## Support

If Mizban helps you, please **⭐ Star** the repository on GitHub — it helps others find the project.

Download / clone:

```bash
git clone https://github.com/rDaeeMaMMaD/mizban.git
```

Or use **Code → Download ZIP** on the GitHub page.

---

**Mizban — one API, many providers, centralized control.**
