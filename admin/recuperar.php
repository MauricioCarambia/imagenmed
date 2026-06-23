<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';

if (usuarioLogueado()) redir(BASE_URL . '/admin/');

$enviado = false;
$resetUrl = '';
$error = '';
$rlClave = 'recuperar|' . ($_SERVER['REMOTE_ADDR'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $minBloqueo = rateLimitBloqueado($rlClave);
    if ($minBloqueo > 0) {
        $error = "Demasiados intentos. Probá de nuevo en {$minBloqueo} minuto" . ($minBloqueo != 1 ? 's' : '') . ".";
    } else {
        rateLimitRegistrarFallo($rlClave, 5, 15);
        $email = trim($_POST['email'] ?? '');

        $stmt = db()->prepare('SELECT id FROM usuarios WHERE email = ? AND activo = 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if ($row) {
            $token = bin2hex(random_bytes(32));
            $expira = date('Y-m-d H:i:s', strtotime('+1 hour'));
            db()->prepare('UPDATE usuarios SET reset_token = ?, reset_expira = ? WHERE id = ?')
                ->execute([$token, $expira, $row['id']]);

            $resetUrl = BASE_URL . '/admin/restablecer.php?token=' . $token;

            $asunto = 'Recuperar contraseña · ImagenMed';
            $cuerpo = "Para restablecer tu contraseña, ingresá al siguiente link (válido por 1 hora):\n\n$resetUrl";
            $mailOk = enviarEmail($email, $asunto, $cuerpo);
            if ($mailOk) $resetUrl = '';
        }

        // Mensaje genérico: no revelamos si el email existe o no.
        $enviado = true;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ImagenMed · Recuperar contraseña</title>
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
    <h5>Recuperar contraseña</h5>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($enviado): ?>
      <div class="alert alert-success">
        Si el email ingresado corresponde a una cuenta activa, te enviamos las instrucciones para restablecer la contraseña.
      </div>
      <?php if ($resetUrl): ?>
        <div class="alert alert-warning small">
          No se detectó un servidor de correo configurado. Para continuar, usá este enlace de restablecimiento:<br>
          <a href="<?= e($resetUrl) ?>"><?= e($resetUrl) ?></a>
        </div>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>/admin/login.php" class="btn btn-link-secondary">Volver al login</a>
    <?php else: ?>
      <p class="text-muted small">Ingresá tu email y te enviaremos un enlace para restablecer tu contraseña.</p>
      <form method="post">
        <?php csrfField(); ?>
        <div class="form-group">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" required autofocus
                 value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <button type="submit" class="btn btn-primary">Enviar enlace</button>
        <a href="<?= BASE_URL ?>/admin/login.php" class="btn btn-link-secondary">Volver al login</a>
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
