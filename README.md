# Matukutire Pharmacies

One global repository and one Railway service for Lomagundi Pharmacy in Chinhoyi and Forestal
Machipisa Pharmacy in Harare.

## What ships

- Multi-page pharmacy website with branch, service, catalogue, gallery, contact, and privacy pages.
- Server-side OpenRouter pharmacy assistant on every page.
- Hardened PHP contact-form handler.
- Server-side PHP proxy for the assistant (`chat-handler.php`).
- One Apache/PHP Docker image configured for Railway's dynamic `PORT`.
- Normalized Lomagundi and Forestal image library under `assets/images/`.

## Project structure

```text
.
├── index.html
├── about.html
├── branches.html
├── services.html
├── shop.html
├── gallery.html
├── contact.html
├── privacy.html
├── contact-handler.php
├── chat-handler.php
├── assets/
│   ├── css/
│   ├── fonts/
│   ├── images/
│   │   ├── lomagundi/
│   │   └── forestal/
│   └── js/main.js
├── docker/
├── Dockerfile
├── docker-compose.yml
└── railway.json
```

## Run locally

With Docker:

```powershell
docker compose up --build --detach
```

Open `http://localhost:8080`.

With PHP:

```powershell
php -S 127.0.0.1:8080
```

## OpenRouter assistant

Open the floating **AI** button on any page and start chatting. A single OpenRouter API key is kept
in a Railway environment variable (`OPENROUTER_API_KEY`) and handled by `chat-handler.php`; the key
is never exposed to the browser, so every visitor can use the assistant without setting anything up.

### Model selection

The chat toolbar has a dropdown: **three free models plus one paid one**. The visitor's pick is
remembered in `localStorage`, and the first free model is used when nothing has been chosen — so
spending money is always a deliberate step down the list.

The free three are not hard-coded. On open, the widget asks `chat-handler.php?models=1`, which pulls
OpenRouter's live catalogue, keeps only entries genuinely priced at zero, drops the
guardrail/embedding/coding-agent ones, and takes the top three — preferring the DeepSeek, Qwen,
Llama, Mistral, Google, Z-AI and Moonshot families. This is deliberate: OpenRouter retires free
models continually, so any pinned free model stops working within months. Pulling the list live
means the picker always reflects what is actually free today.

The paid entry is `deepseek/deepseek-v4-flash` — about $0.14 per million input tokens and $0.28 per
million output, with a 1M-token context. At this site's traffic that is a few cents a month. It is
labelled *"— paid"* in the dropdown, and it is also what the handler falls back to if OpenRouter's
catalogue is unreachable and nothing is cached.

The list is cached on disk for six hours, so traffic to the site does not become traffic to
OpenRouter. **The browser's choice is validated server-side against that same list** — an
unauthenticated visitor cannot name an arbitrary expensive model and spend the key on it.

Two environment overrides:

- `OPENROUTER_PAID_MODEL` — swaps the paid entry for another model id, or set it to `none` to offer
  free models only.
- `OPENROUTER_MODEL` — pins one model for everybody. It wins over the picker, and the dropdown
  hides itself.

Free models carry an account-wide daily request cap (roughly 50/day below 10 purchased credits,
about 1000/day above it). `chat-handler.php` applies a 20-requests-per-hour per-IP limit, but that
only slows a single visitor — it does not stop the account cap being reached. If visitors see
*"The assistant is busy right now"*, the Railway logs will show the upstream 429 and they can switch
to the paid option in the dropdown.

The assistant provides general branch, service, and product information. Its system prompt tells it
not to diagnose, prescribe, claim live inventory, or replace a pharmacist or doctor.

### Checking the deployment

`GET /chat-handler.php?health=1` reports whether the container can see the key, and by which route,
without revealing it:

```json
{"success":true,"configured":true,"keySource":"server","keyLength":73,"keyPrefixOk":true,
 "pinnedModel":"","paidModel":"deepseek/deepseek-v4-flash","curl":true,"mbstring":true}
```

`keySource` is `server` (Apache `PassEnv`), `env` (process environment), or `none`. If it is `none`
the variable never reached the container — check the Railway service and environment it was added
to. `docker/apache-port.sh` also logs a line to stderr at boot for each variable it does not find.

`GET /chat-handler.php?models=1` shows the free models the picker is currently offering, which is
the quickest way to confirm outbound calls to OpenRouter work at all.

## Deploy once on Railway

1. Import this repo into Railway.
2. Railway reads `railway.json` and builds the root `Dockerfile`.
3. In the Railway project **Variables** tab, add:
   - `OPENROUTER_API_KEY` — your OpenRouter API key (`sk-or-v1-…`).
   - `OPENROUTER_MODEL` *(optional)* — pins one model and hides the picker.
   - `OPENROUTER_PAID_MODEL` *(optional)* — swaps the paid dropdown entry, or `none` to drop it.
   - Add them to the **service** (not only as an unlinked shared variable) and to the environment
     you are actually deploying.
4. Redeploy, then open `/chat-handler.php?health=1` and confirm `"configured": true`.
5. In the [OpenRouter dashboard](https://openrouter.ai/), set a monthly credit/spend limit on the
   key as a cost safety net.
6. **Custom domain with Route 53:**
   - Railway **Settings → Domains → Custom Domain** → enter `www.<your-domain>`.
   - Copy the CNAME target Railway gives you.
   - In your Route 53 hosted zone, create a **CNAME** record for `www` pointing to that target.
   - Wait for Railway to provision TLS automatically.
   - Route 53 cannot CNAME the apex (root) domain to an external target. Use `www.<your-domain>`
     for the site, or add an S3 static-website redirect bucket for the apex that sends visitors to
     `www`.

Railway injects `PORT`; the container configures Apache to listen on it automatically.

## Editing the front end

`docker/security.conf` serves CSS and JS with a one-year cache lifetime and every HTML page with
`Cache-Control: no-cache`. **After changing `assets/css/styles.css` or `assets/js/main.js`, bump the
`?v=` number on their `<link>` and `<script>` tags in all eight HTML pages.** Without that bump
returning visitors keep running the previous file and will see a different site from a first-time
visitor.

## Contact form

The contact handler validates input, strips control characters, uses a honeypot, and avoids email
header injection. PHP `mail()` still requires a configured mail transport. Until an SMTP or
HTTP-email provider is connected, failed submissions return a safe message directing visitors to
phone or WhatsApp.

