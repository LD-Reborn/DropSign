# DropSign

Drag & Drop – PDFs cryptographically signieren.

Ein PHP-Einzeldatei-Tool, das per Drag & Drop hochgeladene PDFs mit einem X.509-Zertifikat digital signiert und das signierte PDF zum Download bereitstellt.

## Voraussetzungen

- PHP ≥ 7.4 mit den Extensions `openssl`, `gd`, `mbstring`
- Composer
- Ein gültiges Signaturzertifikat (PKCS#12 `.p12` oder separiert als PEM)

## Installation

```bash
git clone <repo> dropsign
cd dropsign
composer install
```

## Konfiguration

`.env` anlegen (oder `.env.example` kopieren und anpassen):

### Variante A – PEM (getrennt)

```env
CERT_FILE=fullchain6.pem
PRIVKEY_FILE=privkey.pem
PRIVKEY_PASSWORD=
```

### Variante B – PKCS#12 (z. B. von Let's Encrypt / Hausverwaltung)

```env
PKCS12_FILE=certificate.p12
PKCS12_PASSWORD=dein-password
```

### Signatur-Metadaten (optional)

```env
SIGNATURE_NAME=John Doe
SIGNATURE_REASON=Approved
SIGNATURE_LOCATION=Berlin, Germany
SIGNATURE_CONTACT=john@example.com
```

## Verwendung

### Entwicklung

```bash
php -S localhost:8000
```

→ Browser öffnen, PDF hineinziehen – signiertes PDF wird heruntergeladen.

### Produktion (nginx)

Dokumenten-Wurzel auf das Projektverzeichnis zeigen lassen, **nur `index.php` und `.pdf`-Dateien** freigeben:

```nginx
server {
    listen 443 ssl;
    server_name dropsign.example.com;

    ssl_certificate     /etc/letsencrypt/live/…/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/…/privkey.pem;

    root /pfad/zu/dropsign;
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

> `.env`, `composer.json`, `vendor/`, `*.pem`, `*.key`, `*.p12` etc. sind damit automatisch geschützt.

## Funktionsweise

1. Der Benutzer zieht eine PDF-Datei per Drag & Drop in die Weboberfläche.
2. Die PDF wird per `fetch`-POST an `index.php` gesendet.
3. Das Script importiert jede Seite der Original-PDF via FPDI in TCPDF.
4. TCPDF signiert das neue PDF mit dem hinterlegten Zertifikat (PEM oder PKCS#12).
5. Das signierte PDF wird als `signed_<originalname>.pdf` heruntergeladen.
