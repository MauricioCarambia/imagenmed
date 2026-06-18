<?php
// Incluir al tope de cada página del admin:
// require_once __DIR__ . '/_layout.php';
// Al final: require_once __DIR__ . '/_layout_end.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();
require_once __DIR__ . '/../config/helpers.php';
$u = sesionUsuario();
purgarEstudiosViejos();

// ── Sesión activa: registrar/actualizar ──────────────────────
try {
    $sid = session_id();
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
    db()->prepare('INSERT INTO sesiones_activas (usuario_id,session_id,ip,user_agent)
                   VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE last_seen=NOW(),ip=?,user_agent=?')
       ->execute([$u['id'], $sid, $ip, $ua, $ip, $ua]);
    // Limpiar sesiones inactivas más de 8 horas
    db()->exec("DELETE FROM sesiones_activas WHERE last_seen < DATE_SUB(NOW(), INTERVAL 8 HOUR)");
} catch (Throwable) {}

// ── Notificaciones ───────────────────────────────────────────
try {
    $nPendientes = (int)db()->query(
        "SELECT COUNT(*) FROM estudios WHERE estado='pendiente'"
    )->fetchColumn();
    $nSinFirmar = (int)db()->query(
        "SELECT COUNT(*) FROM informes WHERE firmado_en IS NULL"
    )->fetchColumn();
    $nTotal = $nPendientes + $nSinFirmar;
} catch (Throwable) { $nPendientes = $nSinFirmar = $nTotal = 0; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ImagenMed · <?= $pageTitle ?? 'Panel' ?></title>
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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/admin.css">
</head>
<body>

<div id="sidebar">
  <div class="brand">
    <h2>ImagenMed</h2>
    <small>Centro de Diagnóstico</small>
  </div>
  <nav>
    <div class="nav-section">Principal</div>
    <a href="<?= BASE_URL ?>/admin/" class="<?= ($activePage??'')==='inicio' ? 'active':'' ?>">
      <i class="bi bi-grid"></i> Inicio
    </a>
    <a href="<?= BASE_URL ?>/admin/estudios.php" class="<?= ($activePage??'')==='estudios' ? 'active':'' ?>">
      <i class="bi bi-file-medical"></i> Estudios
    </a>
    <?php if (puedeHacer('crear_estudio')): ?>
    <a href="<?= BASE_URL ?>/admin/nuevo_estudio.php" class="<?= ($activePage??'')==='nuevo' ? 'active':'' ?>">
      <i class="bi bi-plus-circle"></i> Nuevo estudio
    </a>
    <a href="<?= BASE_URL ?>/admin/agenda.php" class="<?= ($activePage??'')==='agenda' ? 'active':'' ?>">
      <i class="bi bi-calendar-week"></i> Agenda
    </a>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/admin/pacientes.php" class="<?= ($activePage??'')==='pacientes' ? 'active':'' ?>">
      <i class="bi bi-people"></i> Pacientes
    </a>
    <a href="<?= BASE_URL ?>/admin/comparar.php" class="<?= ($activePage??'')==='comparar' ? 'active':'' ?>">
      <i class="bi bi-layout-split"></i> Comparar
    </a>
    <a href="<?= BASE_URL ?>/admin/plantillas.php" class="<?= ($activePage??'')==='plantillas' ? 'active':'' ?>">
      <i class="bi bi-file-text"></i> Plantillas
    </a>
    <?php if (($u['rol'] ?? '') === 'admin'): ?>
    <div class="nav-section">Administración</div>
    <?php if (puedeHacer('ver_usuarios')): ?>
    <a href="<?= BASE_URL ?>/admin/usuarios.php" class="<?= ($activePage??'')==='usuarios' ? 'active':'' ?>">
      <i class="bi bi-person-gear"></i> Usuarios
    </a>
    <?php endif; ?>
    <?php if (puedeHacer('gestionar_config')): ?>
    <a href="<?= BASE_URL ?>/admin/permisos.php" class="<?= ($activePage??'')==='permisos' ? 'active':'' ?>">
      <i class="bi bi-shield-check"></i> Permisos
    </a>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/admin/sesiones.php" class="<?= ($activePage??'')==='sesiones' ? 'active':'' ?>">
      <i class="bi bi-person-check"></i> Sesiones activas
    </a>
    <a href="<?= BASE_URL ?>/admin/auditoria.php" class="<?= ($activePage??'')==='auditoria' ? 'active':'' ?>">
      <i class="bi bi-clock-history"></i> Auditoría
    </a>
    <?php if (puedeHacer('gestionar_config')): ?>
    <a href="<?= BASE_URL ?>/admin/configuracion.php" class="<?= ($activePage??'')==='configuracion' ? 'active':'' ?>">
      <i class="bi bi-gear"></i> Configuración
    </a>
    <?php endif; ?>
    <?php endif; ?>
  </nav>
  <div class="user-bar">
    <a href="<?= BASE_URL ?>/admin/perfil.php" class="d-flex align-items-center gap-2 text-decoration-none flex-grow-1" title="Mi perfil">
      <div class="avatar"><?= strtoupper(substr($u['nombre'] ?? 'U', 0, 2)) ?></div>
      <div>
        <div class="name"><?= e($u['nombre'] ?? '') ?></div>
        <div class="role"><?= e($u['rol'] ?? '') ?></div>
      </div>
    </a>
    <a href="<?= BASE_URL ?>/admin/logout.php" class="text-white opacity-50" title="Salir">
      <i class="bi bi-box-arrow-right"></i>
    </a>
  </div>
</div>

<div id="sidebar-overlay"></div>

<div id="topbar">
  <div class="d-flex align-items-center gap-2">
    <button id="sidebar-toggle" type="button" class="btn btn-sm btn-outline-secondary d-lg-none" title="Menú">
      <i class="bi bi-list"></i>
    </button>
    <h1><?= $pageTitle ?? 'Panel' ?></h1>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <form action="<?= BASE_URL ?>/admin/buscar.php" method="get" class="d-none d-sm-block">
      <input type="search" name="q" class="form-control form-control-sm" style="width:220px;"
             placeholder="Buscar por DNI, nombre o código...">
    </form>
    <?php if ($nTotal > 0): ?>
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-secondary position-relative" data-bs-toggle="dropdown" title="Notificaciones">
        <i class="bi bi-bell"></i>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;"><?= $nTotal ?></span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:280px;font-size:.83rem;">
        <li><h6 class="dropdown-header">Notificaciones</h6></li>
        <?php if ($nPendientes > 0): ?>
        <li>
          <a class="dropdown-item py-2" href="<?= BASE_URL ?>/admin/estudios.php?estado=pendiente">
            <i class="bi bi-hourglass-split text-warning me-2"></i>
            <strong><?= $nPendientes ?></strong> estudio<?= $nPendientes!=1?'s':'' ?> pendiente<?= $nPendientes!=1?'s':'' ?> de informe
          </a>
        </li>
        <?php endif; ?>
        <?php if ($nSinFirmar > 0): ?>
        <li>
          <a class="dropdown-item py-2" href="<?= BASE_URL ?>/admin/estudios.php?estado=informado">
            <i class="bi bi-pen text-primary me-2"></i>
            <strong><?= $nSinFirmar ?></strong> informe<?= $nSinFirmar!=1?'s':'' ?> sin firmar
          </a>
        </li>
        <?php endif; ?>
      </ul>
    </div>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/admin/nuevo_estudio.php" class="btn btn-sm" style="background:var(--accent);color:#fff;">
      <i class="bi bi-plus-lg"></i> Nuevo estudio
    </a>
    <button id="theme-toggle" type="button" class="btn btn-sm btn-outline-secondary" title="Cambiar tema">
      <i class="bi bi-moon-stars"></i>
    </button>
  </div>
</div>

<div id="content">
  <div class="inner"></div>