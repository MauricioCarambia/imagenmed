<?php
$pageTitle  = 'Permisos por rol';
$activePage = 'permisos';
require_once __DIR__ . '/_layout.php';

if (!puedeHacer('gestionar_config')) {
    echo '<div class="alert alert-warning">Acceso restringido.</div>';
    require_once __DIR__ . '/_layout_end.php';
    exit;
}

$roles = ['radiologo', 'recepcionista', 'tecnico', 'usuario'];

$acciones = [
    'Estudios' => [
        'ver_estudios'     => 'Ver estudios',
        'crear_estudio'    => 'Crear estudio',
        'editar_estudio'   => 'Editar estudio',
        'eliminar_estudio' => 'Eliminar estudio',
        'cambiar_estado'   => 'Cambiar estado',
        'subir_imagenes'   => 'Subir imágenes',
    ],
    'Informe' => [
        'escribir_informe' => 'Escribir / firmar informe',
    ],
    'Pacientes' => [
        'ver_pacientes'    => 'Ver pacientes',
        'editar_pacientes' => 'Editar pacientes',
    ],
    'Administración' => [
        'ver_usuarios'     => 'Ver y gestionar usuarios',
        'gestionar_config' => 'Configuración del sistema',
    ],
];

$ok    = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = db();
    $db->beginTransaction();
    try {
        // Borra todos los permisos de roles no-admin y reconstruye desde los checkboxes
        $db->exec("DELETE FROM permisos_rol WHERE rol != 'admin'");
        $ins = $db->prepare('INSERT INTO permisos_rol (rol, accion) VALUES (?, ?)');
        foreach ($roles as $rol) {
            foreach (array_merge(...array_values($acciones)) as $accion => $_) {
                if (!empty($_POST[$rol][$accion])) {
                    $ins->execute([$rol, $accion]);
                }
            }
        }
        $db->commit();
        registrarAuditoria('editar', 'permisos_rol', 0, 'Permisos actualizados');
        $ok = 'Permisos guardados correctamente.';
    } catch (Throwable $e) {
        $db->rollBack();
        $error = 'Error al guardar: ' . $e->getMessage();
    }
}

// Carga permisos actuales
$rows = db()->query("SELECT rol, accion FROM permisos_rol WHERE rol != 'admin'")->fetchAll();
$activos = [];
foreach ($rows as $r) {
    $activos[$r['rol']][$r['accion']] = true;
}

$roleLabels = [
    'radiologo'     => 'Radiólogo',
    'recepcionista' => 'Recepcionista',
    'tecnico'       => 'Técnico',
    'usuario'       => 'Usuario general',
];
?>

<?php if ($error): ?>
  <div class="alert alert-danger py-2 small"><?= e($error) ?></div>
<?php endif; ?>
<?php if ($ok): ?>
  <div class="alert alert-success py-2 small"><?= e($ok) ?></div>
<?php endif; ?>

<form method="post">
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <div>
      <span class="fw-semibold small">Permisos por rol</span>
      <span class="text-muted small ms-2">El rol <strong>Administrador</strong> tiene acceso total siempre.</span>
    </div>
    <button class="btn btn-sm" style="background:var(--accent);color:#fff;">
      <i class="bi bi-floppy"></i> Guardar cambios
    </button>
  </div>
  <div class="table-responsive">
    <table class="table table-bordered mb-0 small align-middle" style="min-width:600px;">
      <thead class="table-light">
        <tr>
          <th style="min-width:200px;">Permiso</th>
          <?php foreach ($roles as $rol): ?>
          <th class="text-center" style="min-width:110px;"><?= $roleLabels[$rol] ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($acciones as $grupo => $items): ?>
        <tr>
          <td colspan="<?= count($roles) + 1 ?>" class="fw-semibold text-uppercase"
              style="font-size:.7rem;letter-spacing:.08em;color:var(--accent);background:#f8f9fb;padding:6px 12px;">
            <?= e($grupo) ?>
          </td>
        </tr>
        <?php foreach ($items as $accion => $label): ?>
        <tr>
          <td class="ps-3"><?= e($label) ?></td>
          <?php foreach ($roles as $rol): ?>
          <td class="text-center">
            <input type="checkbox" name="<?= $rol ?>[<?= $accion ?>]" value="1"
                   class="form-check-input"
                   <?= !empty($activos[$rol][$accion]) ? 'checked' : '' ?>>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</form>

<?php require_once __DIR__ . '/_layout_end.php'; ?>
