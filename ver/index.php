<?php
// Portal de acceso público — sin login
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';

$error = '';
$rlClave = 'pub_codigo|' . ($_SERVER['REMOTE_ADDR'] ?? '');
$rlEspera = rateLimitBloqueado($rlClave);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rlEspera === 0) {
    $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
    $codigo = preg_replace('/[^A-Z0-9]/', '', $codigo);
    if (strlen($codigo) >= 6) {
        redir(BASE_URL . '/ver/' . $codigo);
    }
    rateLimitRegistrarFallo($rlClave);
    $error = 'Ingresá un código válido.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $rlEspera > 0) {
    $error = 'Demasiados intentos. Probá de nuevo en ' . $rlEspera . ' minuto' . ($rlEspera === 1 ? '' : 's') . '.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ver mi estudio · ImagenMed</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<link rel="manifest" href="<?= BASE_URL ?>/ver/manifest.php">
<meta name="theme-color" content="#16181d">
<script>
(function () {
  var t = localStorage.getItem('theme') || 'light';
  document.documentElement.setAttribute('data-bs-theme', t);
})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root { --accent: #5b8def; --accent-dark: #3f6fd1; --brand: #16181d; --border-c: #e3e3e6; }
body { background: #f7f7f8; min-height: 100vh; display: flex; flex-direction: column;
       font-family: 'Inter', system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
.hero { background: var(--brand); color: #fff; padding: 2.5rem 1rem; text-align: center; }
.hero h1 { font-size: 1.5rem; font-weight: 500; letter-spacing: .01em; }
.hero p  { color: rgba(255,255,255,.5); font-size: .95rem; }
.main { flex: 1; display: flex; align-items: center; justify-content: center; padding: 2rem 1.5rem; }
.box { width: 100%; max-width: 420px; }
.code-inputs { display: flex; gap: 8px; justify-content: center; margin: 1rem 0; }
.code-inputs input {
  width: 44px; height: 52px; text-align: center; font-size: 1.3rem; font-weight: 500;
  border: 1.5px solid var(--border-c); border-radius: 8px; color: var(--brand);
  text-transform: uppercase;
}
.code-inputs input:focus { border-color: var(--accent); outline: none; box-shadow: 0 0 0 3px rgba(91,141,239,.15); }
.divider { display: flex; align-items: center; gap: 10px; margin: 1.2rem 0; color: #aaa; font-size: .85rem; }
.divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: #e0e0e0; }
.theme-toggle {
  position: absolute; top: 1rem; right: 1rem; width: 36px; height: 36px; border-radius: 50%;
  border: 1px solid rgba(255,255,255,.2); background: rgba(255,255,255,.08); color: #fff;
  display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 1rem;
}

/* Tarjetas */
.card { border: 1px solid var(--border-c) !important; border-radius: 10px !important; box-shadow: none !important; }

/* Modo oscuro */
[data-bs-theme="dark"] body { background: #0f1115; }
[data-bs-theme="dark"] .card { background: #1a1c22; border-color: #2c2e35 !important; }
[data-bs-theme="dark"] .code-inputs input {
  background: #24262e; border-color: #3a3d46; color: #f1f1f3;
}
[data-bs-theme="dark"] .divider { color: #64748b; }
[data-bs-theme="dark"] .divider::before, [data-bs-theme="dark"] .divider::after { background: #2c2e35; }
[data-bs-theme="dark"] .text-muted { color: #8a8d98 !important; }
</style>
</head>
<body>
<div class="hero" style="position:relative;">
  <button id="theme-toggle" class="theme-toggle" type="button" title="Cambiar tema">
    <i class="bi bi-moon-stars"></i>
  </button>
  <h1>ImagenMed</h1>
  <p>Centro de Diagnóstico por Imágenes</p>
</div>
<div class="main">
  <div class="box">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body p-4">
        <h5 class="mb-1">Acceder a mi estudio</h5>
        <p class="text-muted small mb-3">Ingresá el código que figura en tu comprobante.</p>
        <?php if ($error): ?>
          <div class="alert alert-danger py-2 small"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" id="form-codigo">
          <div class="code-inputs" id="ci">
            <?php for ($i = 0; $i < 8; $i++): ?>
              <input type="text" maxlength="1" class="cod-inp"
                     name="c<?= $i ?>" autocomplete="off" spellcheck="false">
            <?php endfor; ?>
            <input type="hidden" name="codigo" id="h-codigo">
          </div>
          <button type="submit" class="btn w-100 fw-semibold"
                  style="background:var(--accent);color:#fff;padding:.75rem;border-radius:8px;">
            Ver estudio →
          </button>
        </form>
      </div>
    </div>

    <div class="divider">o</div>

    <div class="card border-0 shadow-sm">
      <div class="card-body text-center p-4">
        <div style="font-size:2.5rem;color:var(--accent);margin-bottom:.5rem;">📷</div>
        <p class="small text-muted">Si el comprobante tiene un código QR, podés escanearlo<br>
        con la cámara del celular y acceder directamente.</p>
      </div>
    </div>

    <p class="text-center text-muted mt-3" style="font-size:.8rem;">
      Si tenés problemas para acceder, consultá en recepción.
    </p>
  </div>
</div>

<script>
var inps = document.querySelectorAll('.cod-inp');
inps.forEach(function(inp, i) {
  inp.addEventListener('input', function() {
    inp.value = inp.value.toUpperCase().replace(/[^A-Z0-9]/g,'');
    if (inp.value && i < inps.length - 1) inps[i+1].focus();
    syncHidden();
  });
  inp.addEventListener('keydown', function(e) {
    if (e.key === 'Backspace' && !inp.value && i > 0) inps[i-1].focus();
  });
  inp.addEventListener('paste', function(e) {
    e.preventDefault();
    var text = (e.clipboardData || window.clipboardData).getData('text').toUpperCase().replace(/[^A-Z0-9]/g,'');
    for (var j = 0; j < text.length && (i+j) < inps.length; j++) {
      inps[i+j].value = text[j];
    }
    syncHidden();
    var next = Math.min(i + text.length, inps.length - 1);
    inps[next].focus();
  });
});
function syncHidden() {
  document.getElementById('h-codigo').value = Array.from(inps).map(i => i.value).join('');
}
document.getElementById('form-codigo').addEventListener('submit', function() { syncHidden(); });
inps[0].focus();

(function () {
  var btn = document.getElementById('theme-toggle');
  var icon = btn.querySelector('i');
  function actualizarIcono() {
    icon.className = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
  }
  actualizarIcono();
  btn.addEventListener('click', function () {
    var nuevo = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-bs-theme', nuevo);
    localStorage.setItem('theme', nuevo);
    actualizarIcono();
  });
})();
</script>
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('<?= BASE_URL ?>/ver/sw.js', { scope: '<?= BASE_URL ?>/ver/' })
    .catch(function() {});
}
</script>
</body>
</html>