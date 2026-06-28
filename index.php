<?php
require_once __DIR__ . '/vendor/autoload.php';

use setasign\Fpdi\Tcpdf\Fpdi;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

// ── HTTP Basic Auth ──
$authUser = $_ENV['AUTH_USERNAME'] ?? '';
$authPass = $_ENV['AUTH_PASSWORD'] ?? '';

if ($authUser !== '' || $authPass !== '') {
    if (!isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if (preg_match('/Basic\s+(.+)/i', $authHeader, $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']]
                    = explode(':', $decoded, 2);
            }
        }
    }

    $valid = ($_SERVER['PHP_AUTH_USER'] ?? '') === $authUser
           && ($_SERVER['PHP_AUTH_PW'] ?? '') === $authPass;

    if (!$valid) {
        header('WWW-Authenticate: Basic realm="DropSign"');
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DropSign</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #0f0f13;
    color: #e0e0e0;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
  }
  h1 {
    font-size: 2.5rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    background: linear-gradient(135deg, #a78bfa, #6366f1);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
  }
  .subtitle {
    color: #888;
    margin-bottom: 2rem;
  }
  #dropzone {
    border: 2px dashed #333;
    border-radius: 1.25rem;
    padding: 4rem 5rem;
    text-align: center;
    transition: all .25s;
    cursor: pointer;
    width: 520px;
    max-width: 90vw;
    background: #18181b;
  }
  #dropzone.dragover {
    border-color: #a78bfa;
    background: #1e1e2a;
  }
  .drop-icon { font-size: 3rem; margin-bottom: .75rem; opacity: .4; }
  .drop-text { font-size: 1.1rem; color: #aaa; }
  .drop-hint { font-size: .8rem; color: #555; margin-top: .5rem; }
  #dropzone input[type="file"] { display: none; }
  #status {
    margin-top: 2rem;
    padding: .75rem 1.25rem;
    border-radius: .75rem;
    background: #18181b;
    font-size: .9rem;
    min-width: 320px;
    max-width: 90vw;
    text-align: center;
    display: none;
  }
  #status.loading { display: block; border: 1px solid #a78bfa; color: #a78bfa; }
  #status.success { display: block; border: 1px solid #4ade80; color: #4ade80; }
  #status.error  { display: block; border: 1px solid #f87171; color: #f87171; }
  .config-note { margin-top: 1.5rem; font-size: .75rem; color: #444; }
</style>
</head>
<body>
  <h1>DropSign</h1>
  <p class="subtitle">Drop a PDF &mdash; get it cryptographically signed</p>
  <div id="dropzone">
    <div class="drop-icon">🔐</div>
    <div class="drop-text">Drag &amp; drop a PDF here</div>
    <div class="drop-hint">or click to browse</div>
    <input type="file" id="fileInput" accept=".pdf,application/pdf">
  </div>
  <div id="status"></div>
  <div class="config-note">Configure certificate in <code>.env</code></div>
<script>
const dropzone = document.getElementById('dropzone');
const fileInput = document.getElementById('fileInput');
const statusEl = document.getElementById('status');

['dragenter', 'dragover'].forEach(e => {
  dropzone.addEventListener(e, ev => { ev.preventDefault(); dropzone.classList.add('dragover'); });
});
['dragleave', 'drop'].forEach(e => {
  dropzone.addEventListener(e, ev => { ev.preventDefault(); dropzone.classList.remove('dragover'); });
});
dropzone.addEventListener('drop', ev => {
  const files = ev.dataTransfer.files;
  if (files.length) handleFile(files[0]);
});
dropzone.addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', () => {
  if (fileInput.files.length) handleFile(fileInput.files[0]);
});

function handleFile(file) {
  if (file.type !== 'application/pdf' && !file.name.endsWith('.pdf')) {
    showStatus('Please drop a PDF file.', 'error');
    return;
  }
  showStatus('Signing...', 'loading');
  const form = new FormData();
  form.append('pdf', file);
  fetch('<?= $_SERVER['SCRIPT_NAME'] ?>', { method: 'POST', body: form })
    .then(async res => {
      if (!res.ok) { const err = await res.json().catch(() => ({error:'Server error'})); throw new Error(err.error); }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = 'signed_' + file.name;
      document.body.appendChild(a); a.click(); a.remove();
      URL.revokeObjectURL(url);
      showStatus('Signed! Download started.', 'success');
    })
    .catch(err => { showStatus(err.message, 'error'); });
}
function showStatus(msg, type) { statusEl.textContent = msg; statusEl.className = type; }
</script>
</body>
</html>
<?php
    exit;
}

// --- POST: receive PDF, sign it, return it ---

if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload failed']);
    exit;
}

$env = $_ENV;

$certPem = $privKeyPem = $privKeyPass = '';

$certFile = $env['CERT_FILE'] ?? '';
$keyFile  = $env['PRIVKEY_FILE'] ?? '';

if ($certFile && $keyFile) {
    $certPath  = __DIR__ . '/' . $certFile;
    $keyPath   = __DIR__ . '/' . $keyFile;
    $privKeyPass = $env['PRIVKEY_PASSWORD'] ?? '';

    if (!file_exists($certPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Certificate not found: ' . $certFile]);
        exit;
    }
    if (!file_exists($keyPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Private key not found: ' . $keyFile]);
        exit;
    }

    $certPem   = file_get_contents($certPath);
    $privKeyPem = file_get_contents($keyPath);
} else {
    $p12Path = __DIR__ . '/' . ($env['PKCS12_FILE'] ?? 'certificate.p12');
    $p12Pass = $env['PKCS12_PASSWORD'] ?? '';

    if (!file_exists($p12Path)) {
        http_response_code(500);
        echo json_encode(['error' => 'Certificate file not found. Set CERT_FILE+PRIVKEY_FILE or PKCS12_FILE in .env']);
        exit;
    }

    $p12Content = file_get_contents($p12Path);
    if (!openssl_pkcs12_read($p12Content, $certs, $p12Pass)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to read PKCS#12 certificate. Check password.']);
        exit;
    }

    $certPem    = $certs['cert'];
    $privKeyPem = $certs['pkey'];
    $privKeyPass = $p12Pass;
}

try {
    $pdf = new Fpdi();
    $pageCount = $pdf->setSourceFile($_FILES['pdf']['tmp_name']);

    for ($i = 1; $i <= $pageCount; $i++) {
        $tplId = $pdf->importPage($i);
        $size = $pdf->getTemplateSize($tplId);
        $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
        $pdf->AddPage($orientation, [$size['width'], $size['height']]);
        $pdf->useTemplate($tplId);
    }

    $pdf->setSignature(
        $certPem,
        $privKeyPem,
        $privKeyPass,
        '', // extracerts (chain already in fullchain6.pem)
        2,  // cert_type (CMS)
        [
            'Name'        => $env['SIGNATURE_NAME'] ?? '',
            'Location'    => $env['SIGNATURE_LOCATION'] ?? '',
            'Reason'      => $env['SIGNATURE_REASON'] ?? '',
            'ContactInfo' => $env['SIGNATURE_CONTACT'] ?? '',
        ],
        '' // approval
    );

    $outPath = tempnam(sys_get_temp_dir(), 'dropsign_') . '.pdf';
    $pdf->Output($outPath, 'F');

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="signed_' . basename($_FILES['pdf']['name']) . '"');
    header('Content-Length: ' . filesize($outPath));
    readfile($outPath);
    unlink($outPath);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
