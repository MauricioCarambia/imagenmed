<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';

if (usuarioLogueado()) redir(BASE_URL . '/admin/');

$error  = '';
$nombre = trim($_POST['nombre'] ?? '');
$email  = trim($_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass    = $_POST['password'] ?? '';
    $pass2   = $_POST['password2'] ?? '';

    // Honeypot: campo oculto que solo un bot completaría
    $honeypot = $_POST['sitio_web'] ?? '';
    // Tiempo mínimo de carga del formulario, para descartar envíos automáticos
    $cargado = (int)($_POST['form_ts'] ?? 0);
    $tardanza = time() - $cargado;

    if ($honeypot !== '' || $cargado === 0 || $tardanza < 2) {
        $error = 'No se pudo procesar el formulario. Intentá nuevamente.';
    } elseif ($nombre === '' || $email === '' || $pass === '') {
        $error = 'Completá todos los campos.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El email no es válido.';
    } elseif (strlen($pass) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } elseif ($pass !== $pass2) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $existe = db()->prepare('SELECT id FROM usuarios WHERE email = ?');
        $existe->execute([$email]);
        if ($existe->fetch()) {
            $error = 'Ya existe una cuenta con ese email.';
        } else {
            $stmt = db()->prepare('INSERT INTO usuarios (nombre, email, password, rol, activo) VALUES (?, ?, ?, ?, 1)');
            $stmt->execute([$nombre, $email, password_hash($pass, PASSWORD_DEFAULT), 'tecnico']);
            redir(BASE_URL . '/admin/login.php?registrado=1');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ImagenMed · Crear cuenta</title>
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
    <h5>Crear cuenta</h5>
    <?php if ($error): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="form_ts" value="<?= time() ?>">
      <div style="position:absolute;left:-9999px;" aria-hidden="true">
        <label for="sitio_web">Dejar vacío</label>
        <input type="text" id="sitio_web" name="sitio_web" tabindex="-1" autocomplete="off">
      </div>
      <div class="form-group">
        <label class="form-label">Nombre completo</label>
        <input type="text" name="nombre" class="form-control" required autofocus
               value="<?= e($nombre) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" required
               value="<?= e($email) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Contraseña</label>
        <input type="password" name="password" class="form-control" minlength="6" required>
      </div>
      <div class="form-group">
        <label class="form-label">Repetir contraseña</label>
        <input type="password" name="password2" class="form-control" minlength="6" required>
      </div>
      <button type="submit" class="btn btn-primary">Crear cuenta</button>
      <a href="<?= BASE_URL ?>/admin/login.php" class="btn btn-link-secondary">Ya tengo cuenta</a>
    </form>
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
