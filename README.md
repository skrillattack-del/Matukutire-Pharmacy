# Matukutire Pharmacies

One global repository and one Railway service for Lomagundi Pharmacy in Chinhoyi and Forestal
Machipisa Pharmacy in Harare.

## What ships

- Multi-page pharmacy website with branch, service, catalogue, gallery, contact, and privacy pages.
- Browser-native OpenRouter pharmacy assistant on every page.
- Hardened PHP contact-form handler.
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

Open the floating **AI** button on any page, paste a throwaway OpenRouter key, select a model, and
start chatting. The key is stored only in that browser's `localStorage`; it is never committed and
never sent to this website or Railway service. The browser sends it directly to OpenRouter.

This client-side key design is intentional for the requested throwaway-key workflow. For a durable
production key, replace it with a server-side proxy and a Railway secret.

The assistant provides general branch, service, and product information. Its system prompt tells it
not to diagnose, prescribe, claim live inventory, or replace a pharmacist or doctor.

## Deploy once on Railway

1. Import `skrillattack-del/Matukutire-Pharmacy` into Railway.
2. Railway reads `railway.json` and builds the root `Dockerfile`.
3. Generate a Railway domain or attach the Route 53 domain.
4. Point the Route 53 record at the Railway-provided custom-domain target.

No OpenRouter environment variable is required because the throwaway key is entered in the UI.
Railway injects `PORT`; the container configures Apache to listen on it automatically.

## Contact form

The contact handler validates input, strips control characters, uses a honeypot, and avoids email
header injection. PHP `mail()` still requires a configured mail transport. Until an SMTP or
HTTP-email provider is connected, failed submissions return a safe message directing visitors to
phone or WhatsApp.

