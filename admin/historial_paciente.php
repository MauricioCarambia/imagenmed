<?php
$pageTitle  = 'Historial del paciente';
$activePage = 'pacientes';
require_once __DIR__ . '/_layout.php';

if (!puedeHacer('ver_estudios')) {
    echo '<div class="alert alert-warning">No tenés permiso para ver historiales de pacientes.</div>';
    require_once __DIR__.'/_layout_end.php'; exit;
}

$pacId = (int)($_GET['pac_id'] ?? 0);
if (!$pacId) { echo '<div class="alert alert-danger">Paciente no especificado.</div>'; require_once __DIR__.'/_layout_end.php'; exit; }

$pac = db()->prepare('SELECT * FROM pacientes WHERE id=?');
$pac->execute([$pacId]);
$pac = $pac->fetch();
if (!$pac) { echo '<div class="alert alert-danger">Paciente no encontrado.</div>'; require_once __DIR__.'/_layout_end.php'; exit; }

// Filtros
$filtroTipo = $_GET['tipo'] ?? '';
$where  = 'e.paciente_id=?';
$params = [$pacId];
if ($filtroTipo && array_key_exists($filtroTipo, TIPOS_ESTUDIO)) {
    $where   .= ' AND e.tipo=?';
    $params[] = $filtroTipo;
}

$estudios = db()->prepare(
    "SELECT e.*,
            u.nombre AS usuario_nombre,
            (SELECT COUNT(*) FROM imagenes WHERE estudio_id=e.id) AS n_imgs,
            (SELECT cuerpo    FROM informes WHERE estudio_id=e.id LIMIT 1) AS informe_cuerpo,
            (SELECT firmado_en FROM informes WHERE estudio_id=e.id LIMIT 1) AS informe_firmado,
            (SELECT filename  FROM imagenes WHERE estudio_id=e.id ORDER BY orden LIMIT 1) AS thumb
     FROM estudios e
     LEFT JOIN usuarios u ON u.id=e.usuario_id
     WHERE $where
     ORDER BY e.fecha_estudio DESC"
);
$estudios->execute($params);
$estudiosArr = $estudios->fetchAll();

// Estadísticas
$statsTipo = [];
foreach ($estudiosArr as $est) {
    $statsTipo[$est['tipo']] = ($statsTipo[$est['tipo']] ?? 0) + 1;
}
arsort($statsTipo);

$estadoColors = ['pendiente'=>'bg-warning text-dark','informado'=>'bg-primary','entregado'=>'bg-success'];
$estadoLabels = ['pendiente'=>'Pendiente','informado'=>'Informado','entregado'=>'Entregado'];

// Agrupar por año
$porAnio = [];
foreach ($estudiosArr as $est) {
    $anio = substr($est['fecha_estudio'], 0, 4);
    $porAnio[$anio][] = $est;
}
?>

<style>
.timeline-year { font-size:.72rem; font-weight:700; text-transform:uppercase;
                 letter-spacing:.1em; color:var(--accent); margin:1.5rem 0 .75rem; }
.study-card { border-left:3px solid var(--border-c); padding-left:1rem; margin-bottom:.75rem;
              position:relative; }
.study-card::before { content:''; position:absolute; left:-7px; top:18px;
                      width:11px; height:11px; border-radius:50%;
                      background:var(--accent); border:2px solid #fff; }
.informe-preview { background:var(--bg-subtle,#f8f9fb); border-radius:6px;
                   padding:8px 12px; font-size:.78rem; line-height:1.6; color:#44464d;
                   cursor:pointer; }
.informe-preview.collapsed { max-height:56px; overflow:hidden; position:relative; }
.informe-preview.collapsed::after { content:''; position:absolute; bottom:0; left:0; right:0;
                                    height:24px; background:linear-gradient(transparent,var(--bg-subtle,#f8f9fb)); }
.stat-pill { display:inline-flex; align-items:center; gap:4px; padding:3px 10px;
             border-radius:999px; font-size:.75rem; font-weight:600;
             background:var(--bg-subtle,#f8f9fb); color:#44464d; cursor:pointer; }
.stat-pill.active { background:var(--accent); color:#fff; }
</style>

<!-- Cabecera del paciente -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1"><?= e($pac['apellido'].', '.$pac['nombre']) ?></h5>
        <div class="text-muted small d-flex flex-wrap gap-2">
          <span><i class="bi bi-person-vcard"></i> DNI <?= e($pac['dni']) ?></span>
          <?php if ($pac['fecha_nac']): ?>
            <span><i class="bi bi-calendar3"></i> <?= fmtFecha($pac['fecha_nac']) ?>
              (<?= (int)((time()-strtotime($pac['fecha_nac']))/31536000) ?> años)</span>
          <?php endif; ?>
          <?php if ($pac['obra_social']): ?>
            <span><i class="bi bi-shield-plus"></i> <?= e($pac['obra_social']) ?>
              <?= $pac['nro_afiliado'] ? '· Nº '.e($pac['nro_afiliado']) : '' ?></span>
          <?php endif; ?>
          <?php if ($pac['telefono']): ?>
            <span><i class="bi bi-telephone"></i> <?= e($pac['telefono']) ?></span>
          <?php endif; ?>
          <?php if ($pac['email']): ?>
            <span><i class="bi bi-envelope"></i> <?= e($pac['email']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/admin/pacientes.php" class="btn btn-sm btn-outline-secondary py-0">
          <i class="bi bi-arrow-left"></i> Volver
        </a>
        <?php if (count($estudiosArr) >= 2): ?>
        <a href="<?= BASE_URL ?>/admin/comparar.php?pac_id=<?= $pacId ?>" class="btn btn-sm btn-outline-primary py-0">
          <i class="bi bi-layout-split"></i> Comparar
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/admin/nuevo_estudio.php?apellido=<?= urlencode($pac['apellido']) ?>&nombre=<?= urlencode($pac['nombre']) ?>&dni=<?= urlencode($pac['dni']) ?>"
           class="btn btn-sm py-0" style="background:var(--accent);color:#fff;">
          <i class="bi bi-plus-lg"></i> Nuevo estudio
        </a>
      </div>
    </div>

    <!-- Estadísticas rápidas -->
    <?php if ($estudiosArr): ?>
    <div class="mt-3 d-flex flex-wrap gap-2 align-items-center">
      <a href="?pac_id=<?= $pacId ?>" class="stat-pill <?= !$filtroTipo?'active':'' ?>">
        Todos <strong><?= count($estudiosArr) ?></strong>
      </a>
      <?php foreach ($statsTipo as $tipo => $cnt): ?>
        <a href="?pac_id=<?= $pacId ?>&tipo=<?= $tipo ?>" class="stat-pill <?= $filtroTipo===$tipo?'active':'' ?>">
          <?= e(labelTipo($tipo)) ?> <strong><?= $cnt ?></strong>
        </a>
      <?php endforeach; ?>
      <span class="text-muted small ms-2">
        Primer estudio: <?= fmtFecha(end($estudiosArr)['fecha_estudio']) ?>
        · Último: <?= fmtFecha(reset($estudiosArr)['fecha_estudio']) ?>
      </span>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Timeline de estudios -->
<?php if (!$estudiosArr): ?>
  <div class="text-center text-muted py-5">
    <i class="bi bi-folder2-open fs-2 d-block mb-2"></i>
    <?= $filtroTipo ? 'Sin estudios de ese tipo.' : 'Este paciente no tiene estudios registrados.' ?>
  </div>
<?php else: ?>
  <?php foreach ($porAnio as $anio => $ests): ?>
  <div class="timeline-year"><i class="bi bi-calendar-range me-1"></i><?= $anio ?> · <?= count($ests) ?> estudio<?= count($ests)!=1?'s':'' ?></div>
  <?php foreach ($ests as $est): ?>
  <div class="study-card">
    <div class="card border-0 shadow-sm">
      <div class="card-body p-3">
        <div class="d-flex gap-3 align-items-start">

          <!-- Thumbnail -->
          <a href="<?= BASE_URL ?>/admin/ver_estudio.php?id=<?= $est['id'] ?>"
             style="flex-shrink:0;width:72px;height:72px;border-radius:8px;overflow:hidden;background:#0a0e1a;display:flex;align-items:center;justify-content:center;text-decoration:none;">
            <?php if ($est['thumb']): $ext=strtolower(pathinfo($est['thumb'],PATHINFO_EXTENSION)); ?>
              <?php if ($ext==='dcm'): ?>
                <div style="color:#cbd5e1;font-size:.6rem;font-weight:700;text-align:center;"><i class="bi bi-file-medical fs-5 d-block"></i>DCM</div>
              <?php else: ?>
                <img src="<?= e(urlImagen($est['thumb'])) ?>" style="width:100%;height:100%;object-fit:cover;" alt="">
              <?php endif; ?>
            <?php else: ?>
              <i class="bi bi-image" style="color:#475569;font-size:1.4rem;"></i>
            <?php endif; ?>
          </a>

          <!-- Info -->
          <div class="flex-grow-1 min-w-0">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-1 mb-1">
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge-tipo badge-<?= e($est['tipo']) ?>"><?= e(labelTipo($est['tipo'])) ?></span>
                <span class="badge <?= $estadoColors[$est['estado']??'pendiente'] ?>" style="font-size:.7rem;"><?= $estadoLabels[$est['estado']??'pendiente'] ?></span>
                <?php if ($est['informe_firmado']): ?>
                  <span class="badge bg-success" style="font-size:.68rem;"><i class="bi bi-patch-check-fill"></i> Firmado</span>
                <?php endif; ?>
              </div>
              <span class="text-muted small fw-semibold"><?= fmtFecha($est['fecha_estudio']) ?></span>
            </div>
            <?php if ($est['descripcion']): ?>
              <div class="small fw-semibold mb-1"><?= e($est['descripcion']) ?></div>
            <?php endif; ?>
            <div class="small text-muted d-flex gap-3 flex-wrap">
              <?php if ($est['medico_der']): ?><span><i class="bi bi-person-badge"></i> <?= e($est['medico_der']) ?></span><?php endif; ?>
              <span><i class="bi bi-images"></i> <?= $est['n_imgs'] ?> imagen<?= $est['n_imgs']!=1?'es':'' ?></span>
              <?php if ($est['usuario_nombre']): ?><span><i class="bi bi-person"></i> <?= e($est['usuario_nombre']) ?></span><?php endif; ?>
            </div>

            <!-- Preview informe colapsable -->
            <?php if ($est['informe_cuerpo']): ?>
            <div class="informe-preview collapsed mt-2" onclick="toggleInforme(this)" title="Click para expandir">
              <?= nl2br(e($est['informe_cuerpo'])) ?>
            </div>
            <?php endif; ?>
          </div>

          <!-- Acciones -->
          <div class="d-flex flex-column gap-1" style="flex-shrink:0;">
            <a href="<?= BASE_URL ?>/admin/ver_estudio.php?id=<?= $est['id'] ?>"
               class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.75rem;">
              <i class="bi bi-eye"></i> Ver
            </a>
            <?php if ($est['informe_cuerpo']): ?>
            <a href="<?= BASE_URL ?>/admin/imprimir_informe.php?id=<?= $est['id'] ?>" target="_blank"
               class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:.75rem;">
              <i class="bi bi-printer"></i> Inf.
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/ver/<?= e($est['codigo_acceso']) ?>" target="_blank"
               class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:.75rem;">
              <i class="bi bi-box-arrow-up-right"></i>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endforeach; ?>
<?php endif; ?>

<script>
function toggleInforme(el) {
  el.classList.toggle('collapsed');
}
</script>

<?php require_once __DIR__ . '/_layout_end.php'; ?>
