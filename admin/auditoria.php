<?php
$pageTitle  = 'Auditoría';
$activePage = 'auditoria';
require_once __DIR__ . '/_layout.php';

if (($u['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    echo '<div class="alert alert-danger">No tenés permiso para acceder a esta sección.</div>';
    require_once __DIR__ . '/_layout_end.php';
    exit;
}

$pag    = max(1, (int)($_GET['pag'] ?? 1));
$porPag = 30;
$offset = ($pag - 1) * $porPag;

$total = (int)db()->query('SELECT COUNT(*) FROM auditoria')->fetchColumn();
$paginas = max(1, ceil($total / $porPag));

$stmt = db()->prepare(
    "SELECT a.*, u.nombre AS usuario_nombre
     FROM auditoria a
     LEFT JOIN usuarios u ON u.id = a.usuario_id
     ORDER BY a.created_at DESC
     LIMIT $porPag OFFSET $offset"
);
$stmt->execute();
$rows = $stmt->fetchAll();

$accionLabels = [
    'crear'            => ['Creó', 'success'],
    'editar'           => ['Editó', 'primary'],
    'eliminar'         => ['Eliminó', 'danger'],
    'eliminar_imagen'  => ['Eliminó imagen', 'danger'],
    'cambiar_estado'   => ['Cambió estado', 'warning'],
    'reset_password'   => ['Restableció contraseña', 'warning'],
    'cambiar_password' => ['Cambió contraseña', 'warning'],
];
?>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <span class="fw-semibold small">Registro de actividad</span>
    <span class="text-muted small"><?= $total ?> registro<?= $total!=1?'s':'' ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 small align-middle">
      <thead class="table-light">
        <tr>
          <th>Fecha</th><th>Usuario</th><th>Acción</th><th>Entidad</th><th>Detalle</th><th>IP</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-nowrap"><?= fmtFecha(substr($r['created_at'], 0, 10)) ?> <?= substr($r['created_at'], 11, 5) ?></td>
          <td><?= e($r['usuario_nombre'] ?? '—') ?></td>
          <td>
            <?php $lbl = $accionLabels[$r['accion']] ?? [$r['accion'], 'secondary']; ?>
            <span class="badge bg-<?= $lbl[1] ?>"><?= e($lbl[0]) ?></span>
          </td>
          <td><?= e($r['entidad']) ?><?= $r['entidad_id'] ? ' #'.$r['entidad_id'] : '' ?></td>
          <td><?= e($r['detalle'] ?? '') ?></td>
          <td><?= e($r['ip'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Sin actividad registrada aún.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($paginas > 1): ?>
  <div class="card-footer bg-white d-flex justify-content-center">
    <nav><ul class="pagination pagination-sm mb-0">
      <?php for ($i = 1; $i <= $paginas; $i++): ?>
        <li class="page-item <?= $i===$pag?'active':'' ?>">
          <a class="page-link" href="?pag=<?= $i ?>"><?= $i ?></a>
        </li>
      <?php endfor; ?>
    </ul></nav>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_layout_end.php'; ?>
