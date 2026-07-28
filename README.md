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

The default model is `google/gemini-2.5-flash-lite` (~$0.10/$0.40 per 1M tokens). Set the optional
`OPENROUTER_MODEL` environment variable to switch to another cheap model.

The assistant provides general branch, service, and product information. Its system prompt tells it
not to diagnose, prescribe, claim live inventory, or replace a pharmacist or doctor.

## Deploy once on Railway

1. Import this repo into Railway.
2. Railway reads `railway.json` and builds the root `Dockerfile`.
3. In the Railway project **Variables** tab, add:
   - `OPENROUTER_API_KEY` — your OpenRouter API key (`sk-or-v1-…`).
   - `OPENROUTER_MODEL` *(optional)* — e.g. `google/gemini-2.5-flash-lite` (default) or any other cheap model.
4. Redeploy so the variables are available to `chat-handler.php`.
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

## Contact form

The contact handler validates input, strips control characters, uses a honeypot, and avoids email
header injection. PHP `mail()` still requires a configured mail transport. Until an SMTP or
HTTP-email provider is connected, failed submissions return a safe message directing visitors to
phone or WhatsApp.

