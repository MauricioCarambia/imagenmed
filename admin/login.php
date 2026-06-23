<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';

if (usuarioLogueado()) redir(BASE_URL . '/admin/');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    $minBloqueo = loginBloqueado($email);
    if ($minBloqueo > 0) {
        $error = "Demasiados intentos fallidos. Probá de nuevo en {$minBloqueo} minuto" . ($minBloqueo != 1 ? 's' : '') . ".";
    } elseif (login($email, $pass)) {
        loginRegistrarExito($email);
        redir(BASE_URL . '/admin/');
    } else {
        loginRegistrarFallo($email);
        $error = 'Email o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ImagenMed · Ingresar</title>
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
    <h5>Iniciar sesión</h5>
    <?php if ($error): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['registrado'])): ?>
      <div class="alert alert-success">Cuenta creada correctamente. Ya podés iniciar sesión.</div>
    <?php endif; ?>
    <form method="post">
      <?php csrfField(); ?>
      <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" required autofocus
               value="<?= e($_POST['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Contraseña</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <button type="submit" class="btn btn-primary">Ingresar</button>
      <a href="<?= BASE_URL ?>/admin/registro.php" class="btn btn-link-secondary">Crear cuenta</a>
      <a href="<?= BASE_URL ?>/admin/recuperar.php" class="btn btn-link-secondary">¿Olvidaste tu contraseña?</a>
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