# Mizban (میزبان)

## درگاه Self-Hosted مدیریت API و مدل‌های هوش مصنوعی

> **Mizban را روی هاست یا VPS خود نصب کنید، چند Provider و مدل را مدیریت کنید، و از طریق یک API واحد با آن‌ها کار کنید.**

**Mizban** یک **AI API Gateway** خودمیزبان است: مدیریت Provider، مدل، کلید API، مسیریابی درخواست، صف، Load Balancing، Failover و پنل ادمین — با **PHP خالص + MySQL** (بدون Composer و بدون فریم‌ورک سنگین).

به‌جای اتصال مستقیم هر برنامه به ده‌ها API خارجی، برنامه شما فقط با Mizban صحبت می‌کند.

```text
برنامه شما
       │
       │ یک API واحد
       ▼
┌─────────────────────────────┐
│           Mizban            │
│  احراز هویت · محدودیت نرخ   │
│  صف · مسیریابی · Key Pool   │
│  Health · Circuit Breaker   │
│  Failover · پیگیری درخواست  │
└──────────────┬──────────────┘
               │
       ┌───────┼────────┬──────────┐
       ▼       ▼        ▼          ▼
   DeepSeek  Gemini  OpenRouter  NVIDIA
               │
               ▼
            پاسخ → برنامه شما
```

> English documentation: [`README.md`](README.md)

---

## Mizban چیست؟

لایهٔ مرکزی بین اپلیکیشن شما و سرویس‌های AI مثل:

* DeepSeek، Gemini، OpenRouter، NVIDIA NIM  
* Z.ai / GLM، GapGPT، RapidAPI  
* APIهای سازگار با OpenAI  
* Providerهای سفارشی (با درایور)

بدون Gateway، هر پروژه باید جداگانه URL، کلید، مدل، محدودیت، Retry، Failover، صف و لاگ را پیاده کند. Mizban این‌ها را متمرکز می‌کند؛ کلاینت لازم نیست بداند کدام Provider پاسخ داده است.

---

## چرا Mizban؟

> **برنامه شما با یک API پایدار حرف بزند؛ پیچیدگی چند Provider با Mizban باشد.**

* یک Integration به‌جای چند Integration  
* مدیریت متمرکز از پنل ادمین  
* جایگزینی Provider در صورت قطعی / سهمیه / کندی (با Failover)  
* چند کلید برای یک Provider (Key Pool)  
* استقلال نسبی از یک Vendor  
* Self-Hosted روی هاست خودتان  
* توسعه‌پذیر: OpenAI-compatible از پنل؛ فرمت خاص با `providers/`  

---

## قابلیت‌های اصلی

### مدیریت Provider و مدل

از پنل: نام، slug، Base URL، نوع، وضعیت، مدل‌ها و کلیدها.  
مدل‌ها جدا از Provider هستند و می‌توانند **fallback** داشته باشند.

### مسیریابی

* `specific` — مدل را کلاینت مشخص می‌کند  
* `general` — Mizban مسیر را انتخاب می‌کند  

بر اساس سلامت Provider، کلید، وزن، بار، latency، Circuit Breaker، Policy کلاینت و Fallback.

### Load Balancing و Failover

توزیع روی چند کلید؛ در صورت خطا، مسیر بعدی طبق تنظیمات.

### Circuit Breaker و Health

پس از خطاهای مکرر، مسیر موقتاً کنار گذاشته می‌شود؛ امتیاز سلامت و latency در مسیریابی نقش دارند.

### کلیدهای API

ذخیرهٔ **رمزنگاری‌شده** با AES-256-CBC و `MIZBAN_MASTER_SECRET` (بدون secret → fail-closed).

> هرگز `.env`، کلید واقعی، یا دامپ دیتابیس را در GitHub نگذارید.

### کلاینت و Policy

کد کلاینت + secret هش‌شده + درجه ۱–۱۰:

* درجه ۱–۳ — مدل‌های پایه  
* درجه ۴–۷ — مدل‌های chat  
* درجه ۸–۱۰ — شامل vision / ocr / agent / tool  

### Rate Limit و صف

محدودیت دقیقه / ساعت / روز / ماه.  
وضعیت صف: `queued` → `running` → `completed` / `failed` / `retry` / `delayed` / `dead`

### Sync و Async

همزمان، یا Async با ACK (موقعیت صف و زمان تقریبی) + Poll یا `callback_url` (با دفاع SSRF).

### Idempotency و Correlation

```json
{ "idempotency_key": "order-42", "correlation_id": "my-trace-1" }
```

### پنل ادمین دو زبانه

مدیریت Provider / کلید / مدل / کلاینت، درخواست‌ها، لاگ، تست زنده، آمار، تغییر رمز.  
🇮🇷 فارسی (RTL) · 🇬🇧 English (LTR) — فایل‌ها: `lang/fa.json` و `lang/en.json`

---

## چرخهٔ درخواست

```text
کلاینت → احراز هویت → Rate Limit → اعتبارسنجی → صف
      → Scheduler → Load Balancer / Key Pool / Circuit Breaker
      → درایور Provider → API خارجی → نتیجه
```

### Endpointها

| Endpoint | Method | Auth | توضیح |
| --- | --- | --- | --- |
| `api.php?action=send` | POST | کلاینت | ارسال درخواست |
| `api.php?action=status&id=…` | GET | کلاینت / ادمین | وضعیت |
| `api.php?action=result&id=…` | GET | کلاینت / ادمین | نتیجه |
| `api.php?action=models` | GET | عمومی | لیست مدل‌های فعال |
| `api.php?action=policy` | GET | کلاینت | سیاست کلاینت |
| `api.php?action=cost_models` / `cost_estimate` | GET | عمومی | برآورد هزینه |
| `api.php?action=admin_*` | مختلف | نشست ادمین | API پنل |
| `cron.php?worker&token=…` | GET / CLI | توکن cron | پردازش پس‌زمینه |

### مثال Sync

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
          "messages": [{ "role": "user", "content": "سلام!" }]
        }
      }'
```

### مسیریابی عمومی

```json
{
  "mode": "general",
  "action": "chat",
  "payload": {
    "messages": [{ "role": "user", "content": "سلام" }]
  }
}
```

### Async + Callback

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
    "messages": [{ "role": "user", "content": "سلام" }]
  }
}
```

HMAC اختیاری (وقتی `security.hmac_enabled` روشن باشد): `X-Timestamp`، `X-Nonce`، `X-Signature`.

---

## معماری و ساختار

```text
api.php → Dispatcher → Queue → Worker → Scheduler
       → Load balancer / Key pool / Circuit breaker
       → Provider driver → External API
```

```text
.
├── api.php / cron.php / index.php
├── worker_runner.php / migrate.php
├── core.php / dispatcher.php / queue.php / worker.php / scheduler.php
├── load_balancer.php / key_pool.php / circuit_breaker.php
├── providers.php / provider_registry.php / providers/
├── config.php / .env.example
├── lang.php / lang/fa.json / lang/en.json
├── schema.sql / assets.css / assets.js
├── tests/smoke.php
├── README.md / README.fa.md / CONTRIBUTING.md / SECURITY.md / LICENSE
└── .htaccess.example
```

---

## نیازمندی‌ها

* PHP **8.0+** با `pdo_mysql`، `curl`، `openssl`، `mbstring`، `json`  
* MySQL **5.7+** یا MariaDB **10.2+**  
* Apache / cPanel / هاست اشتراکی / VPS / محیط محلی  

بدون Composer و بدون Node برای هسته.

---

## نصب

1. دیتابیس و کاربر MySQL بسازید.  
2. فایل‌ها را در `public_html` (یا زیرپوشه) آپلود کنید.  
3. محیط را تنظیم کنید:

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
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
```

4. یک‌بار `https://YOUR_DOMAIN/index.php` را باز کنید (ساخت جدول‌ها). اختیاری: `schema.sql` یا `php migrate.php`.  
5. **فوراً رمز ادمین را عوض کنید.**  
6. Provider، کلید و مدل را از پنل اضافه کنید.  
7. در صورت تمایل `.htaccess.example` را به `.htaccess` کپی کنید.

### حساب‌های دمو (قبل از Production عوض کنید)

| نقش | مقدار |
| --- | --- |
| ادمین | `admin` / `admin123` |
| کلاینت | `miz_basic_001` / `basic_secret_CHANGE_ME` |
| VIP | `miz_vip_001` / `vip_secret_CHANGE_ME` |

### Cron

```cron
* * * * * php /home/USER/public_html/cron.php worker
* * * * * php /home/USER/public_html/cron.php health
* * * * * php /home/USER/public_html/cron.php retry
* * * * * php /home/USER/public_html/cron.php lease_recovery
*/5 * * * * php /home/USER/public_html/cron.php stats
0 * * * * php /home/USER/public_html/cron.php cleanup
```

یا: `nohup php worker_runner.php --daemon &`  
فراخوانی HTTP کرون بدون توکن معتبر رد می‌شود.

---

## متغیرهای محیطی

| متغیر | توضیح |
| --- | --- |
| `MIZBAN_DB_*` | اتصال MySQL |
| `MIZBAN_MASTER_SECRET` | رمزنگاری کلیدها — در Production الزامی |
| `MIZBAN_CRON_TOKEN` | محافظت cron روی HTTP |
| `MIZBAN_ADMIN_USER` / `PASSWORD` | ادمین اولیه (`admin` / `admin123`) |
| `MIZBAN_DEBUG` | در Production خاموش باشد |
| `MIZBAN_TIMEZONE` | پیش‌فرض `Asia/Tehran` |
| `MIZBAN_HOST_NAME` | نام برند در پنل |
| `MIZBAN_CORS_ORIGINS` | Originهای مجاز مرورگر |
| `BOROTO_TOKEN` | برای استفاده از `boroto.php` الزامی است |

---

## امنیت

کوکی امن، Throttle لاگین، CSRF/Origin، Rate Limit، PDO، کلیدهای رمزنگاری‌شده، Callback امن در برابر SSRF، CORS کنترل‌شده، عدم افشای خطای داخلی مگر با Debug، توکن cron.

جزئیات: [`SECURITY.md`](SECURITY.md)

---

## توسعه و تست

```bash
php tests/smoke.php
for f in *.php; do php -l "$f"; done
php cron.php worker
```

* منطق Provider در `providers/`  
* هر دو فایل ترجمه را هم‌کلید نگه دارید  
* Secret هاردکد نکنید  

[`CONTRIBUTING.md`](CONTRIBUTING.md)

**Provider جدید:** سازگار با OpenAI → معمولاً فقط پنل؛ سفارشی → `providers/<slug>/manifest.json` (+ `driver.php`).

---

## مناسب برای چه کسانی؟

توسعه‌دهندگان، SaaS، سایت‌ها، ربات‌های تلگرام، پلاگین‌ها، و تیم‌هایی که می‌خواهند کلیدها و مسیریابی AI را روی هاست خود متمرکز کنند.

---

## چک‌لیست Production

```text
[ ] عوض کردن رمز ادمین پیش‌فرض
[ ] MIZBAN_MASTER_SECRET یکتا
[ ] MIZBAN_CRON_TOKEN یکتا
[ ] HTTPS
[ ] محافظت از .env
[ ] حذف/تعویض کلاینت‌های دمو
[ ] تنظیم cron / worker
[ ] بررسی CORS و callback
[ ] MIZBAN_DEBUG=false
[ ] مطالعه SECURITY.md
```

---

## مستندات مرتبط

* [`README.md`](README.md) — English  
* [`SECURITY.md`](SECURITY.md)  
* [`CONTRIBUTING.md`](CONTRIBUTING.md)  
* [`LICENSE`](LICENSE)  
* [`.htaccess.example`](.htaccess.example)  

---

## مجوز

**MIT** — [`LICENSE`](LICENSE)

**Copyright © 2026 MohammadMahd Daee**

---

## حمایت از پروژه

اگر Mizban برایتان مفید بود، لطفاً در GitHub به ریپو **⭐ Star** بدهید تا دیگران راحت‌تر پیدایش کنند.

دانلود / کلون:

```bash
git clone https://github.com/rDaeeMaMMaD/mizban.git
```

یا از صفحهٔ GitHub: **Code → Download ZIP**

---

**Mizban — یک API، چند Provider، مدیریت متمرکز.**
