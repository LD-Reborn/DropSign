# DropSign

Drag & drop - cryptographically sign PDFs.

A single-file PHP tool that digitally signs uploaded PDFs with an X.509 certificate and serves the signed PDF for download.

> **Note:** DropSign is designed for **single-user use only** - e.g. on a personal server. It has no multi-user access control.

## AI disclosure
This code was co-authored by Big Pickle (opencode).

Although manual testing shows that it works, it is imperative to properly configure nginx (or whatever reverse-proxy you use) to restrict access to only the `index.php` and the `.pdf` files. **Otherwise your private key might get leaked!**

## Requirements

- PHP ≥ 7.4 with extensions `openssl`, `gd`, `mbstring`
- Composer
- A valid signing certificate (PKCS#12 `.p12` or separate PEM files)

## Installation

```bash
git clone <repo> dropsign
cd dropsign
composer install
```

## Configuration

Create a `.env` file (or copy `.env.example` and adjust):

### Option A - PEM (separate files)

```env
CERT_FILE=fullchain6.pem
PRIVKEY_FILE=privkey.pem
PRIVKEY_PASSWORD=
```

### Option B - PKCS#12 (e.g. from Let's Encrypt / certificate authority)

```env
PKCS12_FILE=certificate.p12
PKCS12_PASSWORD=your-password
```

### Signature metadata (optional)

```env
SIGNATURE_NAME=John Doe
SIGNATURE_REASON=Approved
SIGNATURE_LOCATION=Berlin, Germany
SIGNATURE_CONTACT=john@example.com
```

## Usage

### Development

```bash
php -S localhost:8000
```

→ Open the browser, drop a PDF - the signed PDF is downloaded automatically.

### Production (nginx)

Point the document root to the project directory, **allow only `index.php` and `.pdf` files**:

```nginx
server {
    listen 443 ssl;
    server_name dropsign.example.com;

    ssl_certificate     /etc/letsencrypt/live/…/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/…/privkey.pem;

    root /path/to/dropsign;
    index index.php;

    location = / {
        rewrite ^ /index.php last;
    }

    location = /index.php {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php8.x-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~* \.pdf$ {
        try_files $uri =404;
    }

    location / {
        deny all;
        return 404;
    }
}
```

> `.env`, `composer.json`, `vendor/`, `*.pem`, `*.key`, `*.p12` etc. are automatically protected this way.

## How it works

1. The user drags & drops a PDF onto the web interface.
2. The PDF is sent via `fetch` POST to `index.php`.
3. The script imports each page of the original PDF using FPDI into TCPDF.
4. TCPDF signs the new PDF with the configured certificate (PEM or PKCS#12).
5. The signed PDF is downloaded as `signed_<original-name>.pdf`.
