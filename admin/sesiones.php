<?php
$pageTitle  = 'Sesiones activas';
$activePage = 'sesiones';
require_once __DIR__ . '/_layout.php';

if (($u['rol'] ?? '') !== 'admin') {
    echo '<div class="alert alert-warning">Solo administradores.</div>';
    require_once __DIR__ . '/_layout_end.php';
    exit;
}

$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $sid    = $_POST['session_id'] ?? '';

    if ($accion === 'cerrar' && $sid) {
        db()->prepare('DELETE FROM sesiones_activas WHERE session_id=?')->execute([$sid]);
        // Destruir el archivo de sesión PHP si existe
        $savePath = session_save_path() ?: sys_get_temp_dir();
        $archivo  = $savePath . '/sess_' . preg_replace('/[^a-zA-Z0-9,-]/', '', $sid);
        if (is_file($archivo)) @unlink($archivo);
        registrarAuditoria('cerrar_sesion', 'sesion', 0, 'session_id: ' . substr($sid, 0, 16) . '…');
        $ok = 'Sesión cerrada.';
    }

    if ($accion === 'cerrar_todas') {
        $miSid = session_id();
        $otras = db()->prepare('SELECT session_id FROM sesiones_activas WHERE session_id != ?');
        $otras->execute([$miSid]);
        $savePath = session_save_path() ?: sys_get_temp_dir();
        foreach ($otras->fetchAll(PDO::FETCH_COLUMN) as $s) {
            $archivo = $savePath . '/sess_' . preg_replace('/[^a-zA-Z0-9,-]/', '', $s);
            if (is_file($archivo)) @unlink($archivo);
        }
        db()->prepare('DELETE FROM sesiones_activas WHERE session_id != ?')->execute([$miSid]);
        registrarAuditoria('cerrar_sesion', 'sesion', 0, 'Todas las sesiones excepto la propia');
        $ok = 'Todas las demás sesiones fueron cerradas.';
    }
}

// Sesiones activas (últimas 8h)
$sesiones = db()->query(
    'SELECT s.*, u.nombre, u.email, u.rol
     FROM sesiones_activas s
     JOIN usuarios u ON u.id = s.usuario_id
     WHERE s.last_seen > DATE_SUB(NOW(), INTERVAL 8 HOUR)
     ORDER BY s.last_seen DESC'
)->fetchAll();

$miSid = session_id();
?>

<?php if ($ok): ?>
  <div class="alert alert-success py-2 small"><?= e($ok) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <span class="fw-semibold small">Sesiones activas — últimas 8 horas</span>
    <?php if (count($sesiones) > 1): ?>
    <form method="post" onsubmit="return confirm('¿Cerrar todas las demás sesiones?')">
      <input type="hidden" name="accion" value="cerrar_todas">
      <button class="btn btn-sm btn-outline-danger py-0">
        <i class="bi bi-x-circle"></i> Cerrar todas las demás
      </button>
    </form>
    <?php endif; ?>
  </div>
  <?php if (empty($sesiones)): ?>
    <div class="card-body text-muted small text-center py-4">Sin sesiones activas registradas.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover mb-0 small align-middle">
      <thead class="table-light">
        <tr>
          <th>Usuario</th>
          <th>Rol</th>
          <th>IP</th>
          <th>Navegador</th>
          <th>Inicio</th>
          <th>Último acceso</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($sesiones as $s): ?>
        <tr class="<?= $s['session_id']===$miSid ? 'table-success' : '' ?>">
          <td>
            <?= e($s['nombre']) ?>
            <?php if ($s['session_id'] === $miSid): ?>
              <span class="badge bg-success ms-1" style="font-size:.65rem;">Esta sesión</span>
            <?php endif; ?>
            <div class="text-muted" style="font-size:.75rem;"><?= e($s['email']) ?></div>
          </td>
          <td><span class="badge bg-secondary"><?= e($s['rol']) ?></span></td>
          <td><code style="font-size:.75rem;"><?= e($s['ip'] ?: '—') ?></code></td>
          <td class="text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
              title="<?= e($s['user_agent']) ?>">
            <?= e($s['user_agent'] ? mb_substr($s['user_agent'], 0, 60).'…' : '—') ?>
          </td>
          <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($s['created_at'])) ?></td>
          <td class="text-nowrap">
            <?php
            $diff = time() - strtotime($s['last_seen']);
            if ($diff < 60) echo 'Hace ' . $diff . 's';
            elseif ($diff < 3600) echo 'Hace ' . floor($diff/60) . 'min';
            else echo date('d/m H:i', strtotime($s['last_seen']));
            ?>
          </td>
          <td>
            <?php if ($s['session_id'] !== $miSid): ?>
            <form method="post" onsubmit="return confirm('¿Cerrar esta sesión?')">
              <input type="hidden" name="accion" value="cerrar">
              <input type="hidden" name="session_id" value="<?= e($s['session_id']) ?>">
              <button class="btn btn-sm btn-outline-danger py-0">
                <i class="bi bi-x"></i> Cerrar
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_layout_end.php'; ?>
