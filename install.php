<?php
/**
 * ImagenMed — Script de instalación
 * Accedé a este archivo UNA sola vez para configurar el sistema.
 * Eliminalo del servidor una vez completada la instalación.
 */

// Bloquear si ya está instalado y la DB funciona
if (file_exists(__DIR__ . '/config/config.php') && file_exists(__DIR__ . '/.installed')) {
    die('<h2 style="font-family:sans-serif;text-align:center;margin-top:80px;">
        El sistema ya está instalado.<br>
        <a href="admin/">Ir al panel</a> · Eliminá install.php del servidor por seguridad.
    </h2>');
}

$step   = (int)($_POST['step'] ?? $_GET['step'] ?? 1);
$errors = [];
$ok     = false;

// ── Paso 2: verificar conexión ────────────────────────────────
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    $dbChar = 'utf8mb4';

    if (!$dbName) $errors[] = 'Ingresá el nombre de la base de datos.';
    if (!$dbUser) $errors[] = 'Ingresá el usuario de la base de datos.';

    if (empty($errors)) {
        try {
            $dsn = "mysql:host={$dbHost};charset={$dbChar}";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");
            // Guardar en sesión para paso 3
            session_start();
            $_SESSION['install'] = compact('dbHost','dbName','dbUser','dbPass','dbChar');
            $step = 3;
        } catch (PDOException $e) {
            $errors[] = 'No se pudo conectar a la base de datos: ' . $e->getMessage();
            $step = 2;
        }
    } else {
        $step = 2;
    }
}

// ── Paso 3: crear tablas + admin ─────────────────────────────
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_email'])) {
    session_start();
    $cfg = $_SESSION['install'] ?? null;
    if (!$cfg) { $step = 1; goto render; }

    $baseUrl   = rtrim(trim($_POST['base_url'] ?? ''), '/');
    $adminNombre= trim($_POST['admin_nombre'] ?? '');
    $adminEmail = trim($_POST['admin_email']  ?? '');
    $adminPass  = $_POST['admin_pass'] ?? '';
    $centreName = trim($_POST['nombre_centro'] ?? '');

    if (!$adminNombre) $errors[] = 'Nombre del administrador requerido.';
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email inválido.';
    if (strlen($adminPass) < 6) $errors[] = 'La contraseña debe tener al menos 6 caracteres.';
    if (!$baseUrl) $errors[] = 'URL base requerida.';

    if (empty($errors)) {
        try {
            $pdo = new PDO(
                "mysql:host={$cfg['dbHost']};dbname={$cfg['dbName']};charset={$cfg['dbChar']}",
                $cfg['dbUser'], $cfg['dbPass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Crear tablas
            $pdo->exec("
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `rol` ENUM('admin','radiologo','recepcionista','tecnico','usuario') NOT NULL DEFAULT 'usuario',
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reset_token` VARCHAR(64) DEFAULT NULL,
  `reset_expira` DATETIME DEFAULT NULL,
  `firma_img` MEDIUMTEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pacientes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(100) NOT NULL,
  `apellido` VARCHAR(100) NOT NULL,
  `dni` VARCHAR(20) NOT NULL UNIQUE,
  `fecha_nac` DATE DEFAULT NULL,
  `telefono` VARCHAR(30) DEFAULT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `obra_social` VARCHAR(100) DEFAULT NULL,
  `nro_afiliado` VARCHAR(60) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_dni` (`dni`), KEY `idx_apellido` (`apellido`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `estudios` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `paciente_id` INT UNSIGNED NOT NULL,
  `usuario_id` INT UNSIGNED NOT NULL,
  `tipo` ENUM('RX','ECO','TAC','MAM','RMN','OTR') NOT NULL,
  `descripcion` VARCHAR(200) DEFAULT NULL,
  `medico_der` VARCHAR(150) DEFAULT NULL,
  `fecha_estudio` DATE NOT NULL,
  `codigo_acceso` CHAR(8) NOT NULL UNIQUE,
  `vence_en` DATE DEFAULT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `estado` ENUM('pendiente','informado','entregado') NOT NULL DEFAULT 'pendiente',
  FOREIGN KEY (`paciente_id`) REFERENCES `pacientes`(`id`),
  FOREIGN KEY (`usuario_id`)  REFERENCES `usuarios`(`id`),
  KEY `idx_fecha` (`fecha_estudio`), KEY `idx_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `imagenes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `estudio_id` INT UNSIGNED NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `original` VARCHAR(255) NOT NULL,
  `tipo_mime` VARCHAR(80) NOT NULL,
  `orden` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`estudio_id`) REFERENCES `estudios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `informes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `estudio_id` INT UNSIGNED NOT NULL UNIQUE,
  `usuario_id` INT UNSIGNED NOT NULL,
  `cuerpo` TEXT NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `firmado_en` DATETIME DEFAULT NULL,
  `firmado_por` INT UNSIGNED DEFAULT NULL,
  `hash_contenido` VARCHAR(64) DEFAULT NULL,
  FOREIGN KEY (`estudio_id`) REFERENCES `estudios`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `anotaciones_visor` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `estudio_id` INT UNSIGNED NOT NULL,
  `imagen_filename` VARCHAR(255) NOT NULL,
  `data` LONGTEXT NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_estudio_imagen` (`estudio_id`,`imagen_filename`),
  FOREIGN KEY (`estudio_id`) REFERENCES `estudios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `compartidos` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `estudio_id` INT UNSIGNED NOT NULL,
  `token` CHAR(32) NOT NULL UNIQUE,
  `descripcion` VARCHAR(120) DEFAULT '',
  `vence_en` DATETIME NOT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`estudio_id`) REFERENCES `estudios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `accesos_log` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `estudio_id` INT UNSIGNED NOT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(300) DEFAULT NULL,
  `accessed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`estudio_id`) REFERENCES `estudios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `auditoria` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT UNSIGNED DEFAULT NULL,
  `accion` VARCHAR(50) NOT NULL,
  `entidad` VARCHAR(50) NOT NULL,
  `entidad_id` INT UNSIGNED DEFAULT NULL,
  `detalle` VARCHAR(255) DEFAULT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_intentos` (
  `identificador` VARCHAR(190) NOT NULL PRIMARY KEY,
  `intentos` INT UNSIGNED NOT NULL DEFAULT 0,
  `bloqueado_hasta` DATETIME DEFAULT NULL,
  `actualizado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `configuracion` (
  `clave` VARCHAR(80) NOT NULL PRIMARY KEY,
  `valor` TEXT NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `plantillas_informe` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tipo` VARCHAR(10) NOT NULL DEFAULT '',
  `nombre` VARCHAR(120) NOT NULL,
  `cuerpo` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `permisos_rol` (
  `rol` VARCHAR(30) NOT NULL,
  `accion` VARCHAR(60) NOT NULL,
  PRIMARY KEY (`rol`,`accion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `turnos` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `paciente_id` INT UNSIGNED NULL,
  `nombre_pac` VARCHAR(200) NOT NULL DEFAULT '',
  `tipo` VARCHAR(10) NOT NULL DEFAULT 'OTR',
  `descripcion` VARCHAR(200) DEFAULT '',
  `medico_der` VARCHAR(150) DEFAULT '',
  `fecha` DATE NOT NULL,
  `hora` TIME NOT NULL,
  `estado` ENUM('pendiente','confirmado','cancelado','realizado') NOT NULL DEFAULT 'pendiente',
  `notas` TEXT,
  `estudio_id` INT UNSIGNED NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`paciente_id`) REFERENCES `pacientes`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`estudio_id`)  REFERENCES `estudios`(`id`)  ON DELETE SET NULL,
  FOREIGN KEY (`created_by`)  REFERENCES `usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

            // Permisos por defecto para roles no-admin
            $defaults = [
                'ver_estudios'     => ['radiologo','recepcionista','tecnico'],
                'crear_estudio'    => ['recepcionista'],
                'editar_estudio'   => ['recepcionista'],
                'subir_imagenes'   => ['tecnico','recepcionista'],
                'escribir_informe' => ['radiologo'],
                'cambiar_estado'   => ['radiologo'],
                'ver_pacientes'    => ['radiologo','recepcionista'],
                'editar_pacientes' => ['recepcionista'],
            ];
            $ins = $pdo->prepare('INSERT IGNORE INTO permisos_rol (rol,accion) VALUES (?,?)');
            foreach ($defaults as $accion => $roles) {
                foreach ($roles as $rol) $ins->execute([$rol, $accion]);
            }

            // Admin user
            $pdo->prepare('INSERT IGNORE INTO usuarios (nombre,email,password,rol,activo) VALUES (?,?,?,\'admin\',1)')
                ->execute([$adminNombre, $adminEmail, password_hash($adminPass, PASSWORD_DEFAULT)]);

            // Config básica
            $cfgVals = ['nombre_centro' => $centreName];
            $insCfg  = $pdo->prepare('INSERT INTO configuracion (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?');
            foreach ($cfgVals as $k => $v) $insCfg->execute([$k,$v,$v]);

            // Generar config.php
            $configContent = <<<PHP
<?php
define('DB_HOST',    '{$cfg['dbHost']}');
define('DB_NAME',    '{$cfg['dbName']}');
define('DB_USER',    '{$cfg['dbUser']}');
define('DB_PASS',    '{$cfg['dbPass']}');
define('DB_CHARSET', 'utf8mb4');

define('BASE_URL',  '{$baseUrl}');
define('UPLOAD_DIR', __DIR__ . '/../uploads/');

define('TIPOS_ESTUDIO', [
    'RX'  => 'Radiografía',
    'ECO' => 'Ecografía',
    'TAC' => 'Tomografía',
    'MAM' => 'Mamografía',
    'RMN' => 'Resonancia Magnética',
    'OTR' => 'Otro',
]);

define('EXT_PERMITIDAS', ['jpg','jpeg','png','gif','dcm']);
define('MAX_FILE_SIZE', 20 * 1024 * 1024);
define('DIAS_VIGENCIA', 7);

date_default_timezone_set('America/Argentina/Buenos_Aires');
define('FORCE_HTTPS', {$_POST['force_https']});

if (FORCE_HTTPS && empty(\$_SERVER['HTTPS']) && empty(\$_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    header('Location: https://' . \$_SERVER['HTTP_HOST'] . \$_SERVER['REQUEST_URI'], true, 301);
    exit;
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header(
    "Content-Security-Policy: default-src 'self'; " .
    "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; " .
    "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; " .
    "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com data:; " .
    "img-src 'self' data: blob:; " .
    "connect-src 'self' https://cdn.jsdelivr.net; " .
    "object-src 'none'; base-uri 'self'; frame-ancestors 'self';"
);
if (FORCE_HTTPS) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
PHP;

            file_put_contents(__DIR__ . '/config/config.php', $configContent);
            file_put_contents(__DIR__ . '/.installed', date('Y-m-d H:i:s'));

            $step = 4;
            $ok   = true;
        } catch (Throwable $e) {
            $errors[] = 'Error al instalar: ' . $e->getMessage();
            $step = 3;
        }
    } else {
        $step = 3;
    }
}

render:
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalación · ImagenMed</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
body { background: #f0f2f5; font-family: 'Segoe UI', system-ui, sans-serif; }
.install-box { max-width: 560px; margin: 60px auto; }
.step-bar { display: flex; gap: 0; margin-bottom: 2rem; }
.step-item { flex: 1; text-align: center; padding: 8px 4px; font-size: .78rem;
             border-bottom: 3px solid #dee2e6; color: #aaa; }
.step-item.active { border-color: #5b8def; color: #5b8def; font-weight: 600; }
.step-item.done   { border-color: #22c55e; color: #22c55e; }
.brand { font-size: 1.5rem; font-weight: 700; color: #16181d; text-align: center; margin-bottom: 1.5rem; }
.brand span { color: #5b8def; }
</style>
</head>
<body>
<div class="install-box">
  <div class="brand">Imagen<span>Med</span></div>

  <div class="step-bar">
    <?php foreach (['Bienvenida','Base de datos','Configuración','¡Listo!'] as $i => $lbl): ?>
      <div class="step-item <?= $step===($i+1)?'active':($step>$i+1?'done':'') ?>">
        <?= $lbl ?>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="alert alert-danger small">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>

  <!-- ── PASO 1: Bienvenida ── -->
  <?php if ($step === 1): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body p-4">
      <h5 class="mb-3">Bienvenido al instalador</h5>
      <p class="text-muted small">Este asistente va a configurar la base de datos y crear el primer usuario administrador.</p>
      <p class="text-muted small mb-4">Asegurate de tener a mano los datos de conexión a MySQL.</p>
      <div class="mb-3">
        <strong class="small">Requisitos del servidor:</strong>
        <ul class="small mt-1">
          <?php
          $reqs = [
            'PHP >= 8.0'       => version_compare(PHP_VERSION, '8.0.0', '>='),
            'PDO MySQL'        => extension_loaded('pdo_mysql'),
            'GD / FileInfo'    => extension_loaded('gd') || extension_loaded('fileinfo'),
            'uploads/ escribible' => is_writable(__DIR__ . '/uploads'),
            'config/ escribible'  => is_writable(__DIR__ . '/config'),
          ];
          foreach ($reqs as $label => $ok2): ?>
            <li><?= $ok2 ? '✅' : '❌' ?> <?= htmlspecialchars($label) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <a href="?step=2" class="btn btn-primary w-100">Comenzar instalación →</a>
    </div>
  </div>

  <!-- ── PASO 2: DB ── -->
  <?php elseif ($step === 2): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body p-4">
      <h5 class="mb-3">Conexión a la base de datos</h5>
      <form method="post">
        <input type="hidden" name="step" value="2">
        <div class="mb-3">
          <label class="form-label small fw-semibold">Host</label>
          <input type="text" name="db_host" class="form-control" value="localhost">
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Nombre de la base de datos</label>
          <input type="text" name="db_name" class="form-control" placeholder="imagenmed" required>
          <div class="form-text">Se creará si no existe.</div>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Usuario MySQL</label>
          <input type="text" name="db_user" class="form-control" placeholder="root" required>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Contraseña MySQL</label>
          <input type="password" name="db_pass" class="form-control">
        </div>
        <button type="submit" class="btn btn-primary w-100">Verificar conexión →</button>
      </form>
    </div>
  </div>

  <!-- ── PASO 3: Config + Admin ── -->
  <?php elseif ($step === 3): ?>
  <?php session_start(); $cfg = $_SESSION['install'] ?? []; ?>
  <div class="card shadow-sm border-0">
    <div class="card-body p-4">
      <h5 class="mb-3">Configuración del sistema</h5>
      <form method="post">
        <input type="hidden" name="step" value="3">
        <p class="small text-success mb-3">✅ Conexión a <strong><?= htmlspecialchars($cfg['dbName'] ?? '') ?></strong> exitosa.</p>

        <h6 class="small fw-semibold text-uppercase text-muted mb-2">URL del sistema</h6>
        <div class="mb-3">
          <label class="form-label small">URL base <span class="text-danger">*</span></label>
          <input type="url" name="base_url" class="form-control" required
                 placeholder="https://tudominio.com/imagenmed"
                 value="<?= htmlspecialchars((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/')) ?>">
        </div>
        <div class="mb-4">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="force_https" value="true" id="chk-https">
            <label class="form-check-label small" for="chk-https">Forzar HTTPS (activar en producción)</label>
          </div>
        </div>

        <h6 class="small fw-semibold text-uppercase text-muted mb-2">Centro de diagnóstico</h6>
        <div class="mb-4">
          <label class="form-label small">Nombre del centro</label>
          <input type="text" name="nombre_centro" class="form-control" placeholder="Centro de Diagnóstico por Imágenes">
        </div>

        <h6 class="small fw-semibold text-uppercase text-muted mb-2">Administrador</h6>
        <div class="mb-3">
          <label class="form-label small">Nombre completo <span class="text-danger">*</span></label>
          <input type="text" name="admin_nombre" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label small">Email <span class="text-danger">*</span></label>
          <input type="email" name="admin_email" class="form-control" required>
        </div>
        <div class="mb-4">
          <label class="form-label small">Contraseña <span class="text-danger">*</span></label>
          <input type="password" name="admin_pass" class="form-control" minlength="6" required>
        </div>

        <button type="submit" class="btn btn-primary w-100">Instalar →</button>
      </form>
    </div>
  </div>

  <!-- ── PASO 4: Listo ── -->
  <?php elseif ($step === 4): ?>
  <div class="card shadow-sm border-0 text-center">
    <div class="card-body p-5">
      <div class="text-success mb-3" style="font-size:3rem;">✅</div>
      <h4>¡Instalación completada!</h4>
      <p class="text-muted small mt-2 mb-4">
        ImagenMed está listo para usar.<br>
        <strong class="text-danger">Eliminá el archivo <code>install.php</code> del servidor por seguridad.</strong>
      </p>
      <a href="admin/" class="btn btn-primary">Ir al panel de administración →</a>
    </div>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
