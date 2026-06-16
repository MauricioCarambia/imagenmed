<?php
$pageTitle  = 'Mi perfil';
$activePage = 'perfil';
require_once __DIR__ . '/_layout.php';

$error = '';
$ok    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'datos') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email  = trim($_POST['email'] ?? '');

        if ($nombre === '' || $email === '') {
            $error = 'Completá nombre y email.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'El email no es válido.';
        } else {
            $existe = db()->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ?');
            $existe->execute([$email, $u['id']]);
            if ($existe->fetch()) {
                $error = 'Ya existe otro usuario con ese email.';
            } else {
                db()->prepare('UPDATE usuarios SET nombre=?, email=? WHERE id=?')
                    ->execute([$nombre, $email, $u['id']]);
                $_SESSION['usuario']['nombre'] = $nombre;
                $u['nombre'] = $nombre;
                registrarAuditoria('editar', 'perfil', $u['id']);
                $ok = 'Datos actualizados correctamente.';
            }
        }
    }

    if ($accion === 'guardar_firma') {
        $dataUrl = $_POST['firma_img'] ?? '';
        if (!preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $dataUrl)) {
            jsonOut(['ok' => false, 'msg' => 'Imagen inválida']);
        }
        if (strlen($dataUrl) > 500000) jsonOut(['ok' => false, 'msg' => 'Firma demasiado grande']);
        db()->prepare('UPDATE usuarios SET firma_img=? WHERE id=?')->execute([$dataUrl, $u['id']]);
        jsonOut(['ok' => true]);
    }

    if ($accion === 'borrar_firma') {
        db()->prepare('UPDATE usuarios SET firma_img=NULL WHERE id=?')->execute([$u['id']]);
        jsonOut(['ok' => true]);
    }

    if ($accion === 'password') {
        $actual = $_POST['actual'] ?? '';
        $nueva  = $_POST['nueva'] ?? '';
        $nueva2 = $_POST['nueva2'] ?? '';

        $stmt = db()->prepare('SELECT password FROM usuarios WHERE id = ?');
        $stmt->execute([$u['id']]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($actual, $hash)) {
            $error = 'La contraseña actual es incorrecta.';
        } elseif (strlen($nueva) < 6) {
            $error = 'La nueva contraseña debe tener al menos 6 caracteres.';
        } elseif ($nueva !== $nueva2) {
            $error = 'Las contraseñas nuevas no coinciden.';
        } else {
            db()->prepare('UPDATE usuarios SET password = ? WHERE id = ?')
                ->execute([password_hash($nueva, PASSWORD_DEFAULT), $u['id']]);
            registrarAuditoria('cambiar_password', 'perfil', $u['id']);
            $ok = 'Contraseña actualizada correctamente.';
        }
    }
}

$stmt = db()->prepare('SELECT nombre, email, rol, created_at, firma_img FROM usuarios WHERE id = ?');
$stmt->execute([$u['id']]);
$miUsuario = $stmt->fetch();
?>

<?php if ($error): ?>
  <div class="alert alert-danger py-2 small"><?= e($error) ?></div>
<?php endif; ?>
<?php if ($ok): ?>
  <div class="alert alert-success py-2 small"><?= e($ok) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold small">Mis datos</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="accion" value="datos">
          <div class="mb-3">
            <label class="form-label small">Nombre</label>
            <input type="text" name="nombre" class="form-control form-control-sm"
                   value="<?= e($miUsuario['nombre']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label small">Email</label>
            <input type="email" name="email" class="form-control form-control-sm"
                   value="<?= e($miUsuario['email']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label small">Rol</label>
            <input type="text" class="form-control form-control-sm" value="<?= e($miUsuario['rol']) ?>" disabled>
          </div>
          <button class="btn btn-sm" style="background:var(--accent);color:#fff;">Guardar datos</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold small">Cambiar contraseña</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="accion" value="password">
          <div class="mb-3">
            <label class="form-label small">Contraseña actual</label>
            <input type="password" name="actual" class="form-control form-control-sm" required>
          </div>
          <div class="mb-3">
            <label class="form-label small">Nueva contraseña</label>
            <input type="password" name="nueva" class="form-control form-control-sm" minlength="6" required>
          </div>
          <div class="mb-3">
            <label class="form-label small">Repetir nueva contraseña</label>
            <input type="password" name="nueva2" class="form-control form-control-sm" minlength="6" required>
          </div>
          <button class="btn btn-sm" style="background:var(--accent);color:#fff;">Cambiar contraseña</button>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold small d-flex justify-content-between align-items-center">
        Firma manuscrita digitalizada
        <span class="text-muted fw-normal" style="font-size:11px;">Se imprime en los informes que firmes</span>
      </div>
      <div class="card-body">
        <?php if (!empty($miUsuario['firma_img'])): ?>
        <div class="mb-3">
          <div class="small text-muted mb-1">Firma guardada actualmente:</div>
          <div style="border:1px solid var(--border-c);border-radius:8px;padding:8px;background:#fff;display:inline-block;">
            <img src="<?= e($miUsuario['firma_img']) ?>" alt="Firma" style="max-height:80px;max-width:300px;">
          </div>
          <button class="btn btn-sm btn-outline-danger ms-2" onclick="borrarFirma()">
            <i class="bi bi-trash"></i> Borrar firma
          </button>
        </div>
        <?php endif; ?>

        <div class="small text-muted mb-2">
          Dibujá tu firma con el mouse o con el dedo en la pantalla táctil:
        </div>
        <div style="position:relative;border:2px dashed var(--border-c);border-radius:8px;background:#fff;cursor:crosshair;display:inline-block;">
          <canvas id="firma-canvas" width="400" height="160" style="display:block;touch-action:none;"></canvas>
          <div style="position:absolute;bottom:6px;left:50%;transform:translateX(-50%);font-size:10px;color:#ccc;pointer-events:none;white-space:nowrap;">
            — Dibujá tu firma aquí —
          </div>
        </div>
        <div class="mt-2 d-flex gap-2">
          <button class="btn btn-sm btn-outline-secondary" onclick="limpiarFirma()">
            <i class="bi bi-eraser"></i> Limpiar
          </button>
          <button class="btn btn-sm" style="background:var(--accent);color:#fff;" onclick="guardarFirma()">
            <i class="bi bi-floppy"></i> Guardar firma
          </button>
        </div>
        <div id="firma-msg" class="small mt-2 d-none"></div>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  var canvas = document.getElementById('firma-canvas');
  var ctx = canvas.getContext('2d');
  var drawing = false;
  var lastX = 0, lastY = 0;
  var hasStrokes = false;

  ctx.strokeStyle = '#111';
  ctx.lineWidth = 2.2;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';

  function pos(e) {
    var r = canvas.getBoundingClientRect();
    var src = e.touches ? e.touches[0] : e;
    return { x: src.clientX - r.left, y: src.clientY - r.top };
  }

  canvas.addEventListener('pointerdown', function(e) {
    drawing = true; hasStrokes = true;
    var p = pos(e); lastX = p.x; lastY = p.y;
    ctx.beginPath(); ctx.moveTo(lastX, lastY);
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function(e) {
    if (!drawing) return;
    var p = pos(e);
    ctx.lineTo(p.x, p.y); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(p.x, p.y);
    lastX = p.x; lastY = p.y;
  });
  canvas.addEventListener('pointerup', function() { drawing = false; });

  window.limpiarFirma = function() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    hasStrokes = false;
  };

  window.guardarFirma = function() {
    if (!hasStrokes) { alert('Dibujá tu firma primero.'); return; }
    var dataUrl = canvas.toDataURL('image/png');
    fetch('perfil.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'accion=guardar_firma&firma_img=' + encodeURIComponent(dataUrl)
    }).then(function(r){ return r.json(); }).then(function(d){
      var msg = document.getElementById('firma-msg');
      msg.classList.remove('d-none','text-success','text-danger');
      if (d.ok) {
        msg.textContent = '✓ Firma guardada correctamente. Se usará en tus informes.';
        msg.classList.add('text-success');
        setTimeout(function(){ location.reload(); }, 1200);
      } else {
        msg.textContent = 'Error: ' + (d.msg || 'No se pudo guardar.');
        msg.classList.add('text-danger');
      }
      msg.classList.remove('d-none');
    });
  };

  window.borrarFirma = function() {
    if (!confirm('¿Borrar tu firma guardada?')) return;
    fetch('perfil.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'accion=borrar_firma'
    }).then(function(r){ return r.json(); }).then(function(){ location.reload(); });
  };
})();
</script>

<?php require_once __DIR__ . '/_layout_end.php'; ?>
