<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';

if (usuarioLogueado()) redir(BASE_URL . '/admin/');

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$ok = false;

$stmt = db()->prepare('SELECT id FROM usuarios WHERE reset_token = ? AND reset_expira > NOW()');
$stmt->execute([$token]);
$row = $stmt->fetch();

if (!$row) {
    $error = 'El enlace de recuperación es inválido o expiró. Solicitá uno nuevo.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass  = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (strlen($pass) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } elseif ($pass !== $pass2) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        db()->prepare('UPDATE usuarios SET password = ?, reset_token = NULL, reset_expira = NULL WHERE id = ?')
            ->execute([password_hash($pass, PASSWORD_DEFAULT), $row['id']]);
        $ok = true;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ImagenMed · Restablecer contraseña</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<script>
(function () {
  var t = localStorage.getItem('theme') || 'light';
  document.documentElement.setAttribute('data-bs-theme', t);
})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/auth.css">
</head>
<body>
<button id="theme-toggle" class="theme-toggle" type="button" title="Cambiar tema">
  <i class="bi bi-moon-stars"></i>
</button>
<div class="login-card">
  <div class="brand">
    <h1>ImagenMed</h1>
    <p>Centro de Diagnóstico por Imágenes</p>
  </div>
  <div class="card">
    <h5>Restablecer contraseña</h5>

    <?php if ($ok): ?>
      <div class="alert alert-success">Contraseña actualizada correctamente. Ya podés iniciar sesión.</div>
      <a href="<?= BASE_URL ?>/admin/login.php" class="btn btn-primary">Ir al login</a>
    <?php elseif (!$row): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
      <a href="<?= BASE_URL ?>/admin/recuperar.php" class="btn btn-link-secondary">Solicitar nuevo enlace</a>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="form-group">
          <label class="form-label">Nueva contraseña</label>
          <input type="password" name="password" class="form-control" minlength="6" required autofocus>
        </div>
        <div class="form-group">
          <label class="form-label">Repetir contraseña</label>
          <input type="password" name="password2" class="form-control" minlength="6" required>
        </div>
        <button type="submit" class="btn btn-primary">Guardar contraseña</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<script>
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
</body>
</html>
