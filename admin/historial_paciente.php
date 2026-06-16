<?php
$pageTitle  = 'Historial del paciente';
$activePage = 'pacientes';
require_once __DIR__ . '/_layout.php';

$pacId = (int)($_GET['pac_id'] ?? 0);
if (!$pacId) { echo '<div class="alert alert-danger">Paciente no especificado.</div>'; require_once __DIR__.'/_layout_end.php'; exit; }

$pac = db()->prepare('SELECT * FROM pacientes WHERE id=?');
$pac->execute([$pacId]);
$pac = $pac->fetch();
if (!$pac) { echo '<div class="alert alert-danger">Paciente no encontrado.</div>'; require_once __DIR__.'/_layout_end.php'; exit; }

$estudios = db()->prepare(
    'SELECT e.*, u.nombre AS usuario_nombre,
            (SELECT COUNT(*) FROM imagenes WHERE estudio_id=e.id) AS n_imgs,
            (SELECT cuerpo FROM informes WHERE estudio_id=e.id LIMIT 1) AS informe_cuerpo,
            (SELECT filename FROM imagenes WHERE estudio_id=e.id ORDER BY orden LIMIT 1) AS thumb
     FROM estudios e
     LEFT JOIN usuarios u ON u.id=e.usuario_id
     WHERE e.paciente_id=?
     ORDER BY e.fecha_estudio DESC'
);
$estudios->execute([$pacId]);
$estudiosArr = $estudios->fetchAll();

$estadoColors = ['pendiente'=>'bg-warning text-dark','informado'=>'bg-primary','entregado'=>'bg-success'];
$estadoLabels = ['pendiente'=>'Pendiente','informado'=>'Informado','entregado'=>'Entregado'];
?>

<!-- Cabecera del paciente -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1"><?= e($pac['apellido'].', '.$pac['nombre']) ?></h5>
        <div class="text-muted small">
          DNI: <?= e($pac['dni']) ?>
          <?php if ($pac['fecha_nac']): ?> · Nac. <?= fmtFecha($pac['fecha_nac']) ?><?php endif; ?>
          <?php if ($pac['obra_social']): ?> · <?= e($pac['obra_social']) ?><?php endif; ?>
          <?php if ($pac['telefono']): ?> · <?= e($pac['telefono']) ?><?php endif; ?>
          <?php if ($pac['email']): ?> · <?= e($pac['email']) ?><?php endif; ?>
        </div>
      </div>
      <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/admin/pacientes.php" class="btn btn-sm btn-outline-secondary py-0">
          <i class="bi bi-arrow-left"></i> Volver
        </a>
        <a href="<?= BASE_URL ?>/admin/nuevo_estudio.php" class="btn btn-sm py-0" style="background:var(--accent);color:#fff;">
          <i class="bi bi-plus-lg"></i> Nuevo estudio
        </a>
      </div>
    </div>
    <div class="mt-2 d-flex gap-3 small text-muted">
      <span><strong><?= count($estudiosArr) ?></strong> estudio<?= count($estudiosArr)!==1?'s':'' ?></span>
    </div>
  </div>
</div>

<!-- Estudios -->
<?php if (!$estudiosArr): ?>
  <div class="text-center text-muted py-5">Este paciente no tiene estudios registrados.</div>
<?php else: ?>
  <div class="d-flex flex-column gap-3">
  <?php foreach ($estudiosArr as $est): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-body p-3">
      <div class="d-flex gap-3 align-items-start">
        <!-- Thumbnail -->
        <div style="flex-shrink:0;width:72px;height:72px;border-radius:8px;overflow:hidden;background:#0a0e1a;display:flex;align-items:center;justify-content:center;">
          <?php if ($est['thumb']): $ext=strtolower(pathinfo($est['thumb'],PATHINFO_EXTENSION)); ?>
            <?php if ($ext==='dcm'): ?>
              <div style="color:#cbd5e1;font-size:.6rem;font-weight:700;text-align:center;"><i class="bi bi-file-medical fs-5 d-block"></i>DCM</div>
            <?php else: ?>
              <img src="<?= e(urlImagen($est['thumb'])) ?>" style="width:100%;height:100%;object-fit:cover;" alt="">
            <?php endif; ?>
          <?php else: ?>
            <i class="bi bi-image" style="color:#475569;font-size:1.4rem;"></i>
          <?php endif; ?>
        </div>

        <!-- Info -->
        <div class="flex-grow-1 min-w-0">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
            <div>
              <span class="badge-tipo badge-<?= e($est['tipo']) ?> me-2"><?= e(labelTipo($est['tipo'])) ?></span>
              <span class="badge <?= $estadoColors[$est['estado']??'pendiente'] ?>" style="font-size:.7rem;"><?= $estadoLabels[$est['estado']??'pendiente'] ?></span>
            </div>
            <div class="text-muted small"><?= fmtFecha($est['fecha_estudio']) ?></div>
          </div>
          <?php if ($est['descripcion']): ?>
            <div class="small text-muted mb-1"><?= e($est['descripcion']) ?></div>
          <?php endif; ?>
          <?php if ($est['medico_der']): ?>
            <div class="small text-muted mb-1">Derivante: <?= e($est['medico_der']) ?></div>
          <?php endif; ?>
          <?php if ($est['informe_cuerpo']): ?>
            <div class="small mt-1 p-2" style="background:#f7f7f8;border-radius:6px;color:#44464d;max-height:60px;overflow:hidden;line-height:1.5;">
              <?= e(mb_substr($est['informe_cuerpo'], 0, 200)) ?><?= mb_strlen($est['informe_cuerpo']) > 200 ? '…' : '' ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Acciones -->
        <div class="d-flex flex-column gap-1" style="flex-shrink:0;">
          <a href="<?= BASE_URL ?>/admin/ver_estudio.php?id=<?= $est['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.75rem;white-space:nowrap;">
            <i class="bi bi-eye"></i> Ver
          </a>
          <?php if ($est['informe_cuerpo']): ?>
          <a href="<?= BASE_URL ?>/admin/imprimir_informe.php?id=<?= $est['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:.75rem;">
            <i class="bi bi-printer"></i> Inf.
          </a>
          <?php endif; ?>
          <a href="<?= BASE_URL ?>/ver/<?= e($est['codigo_acceso']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:.75rem;">
            <i class="bi bi-box-arrow-up-right"></i> Pub.
          </a>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/_layout_end.php'; ?>
