<?php
$pageTitle  = 'Configuración del sistema';
$activePage = 'configuracion';
require_once __DIR__ . '/_layout.php';

// Solo admin
if (($u['rol'] ?? '') !== 'admin') {
    echo '<div class="alert alert-danger">Acceso restringido.</div>';
    require_once __DIR__ . '/_layout_end.php';
    exit;
}

$ok  = false;
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    csrfCheck();
    $campos = ['nombre_centro','subtitulo_centro','direccion','ciudad','telefono','email_centro','matricula','pie_informe','dias_retencion_estudios'];
    $db = db();
    $db->beginTransaction();
    try {
        foreach ($campos as $c) {
            $val = trim($_POST[$c] ?? '');
            $db->prepare('INSERT INTO configuracion (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?')
               ->execute([$c, $val, $val]);
        }
        // Logo
        if (!empty($_FILES['logo']['name'])) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','svg']) && $_FILES['logo']['size'] < 2*1024*1024) {
                $nombre = 'logo_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $dest   = __DIR__ . '/../uploads/' . $nombre;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                    // Borrar logo anterior
                    $prev = $db->prepare('SELECT valor FROM configuracion WHERE clave=?');
                    $prev->execute(['logo_filename']);
                    $prevFile = $prev->fetchColumn();
                    if ($prevFile && basename($prevFile) === $prevFile && is_file(__DIR__.'/../uploads/'.$prevFile)) {
                        @unlink(__DIR__.'/../uploads/'.$prevFile);
                    }
                    $db->prepare('INSERT INTO configuracion (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?')
                       ->execute(['logo_filename', $nombre, $nombre]);
                }
            }
        }
        $db->commit();
        registrarAuditoria('editar', 'configuracion', null, 'Configuración del sistema actualizada');
        $ok = true;
    } catch (Throwable $e) {
        $db->rollBack();
        $err = 'Error al guardar: ' . $e->getMessage();
    }
}

// Leer valores actuales
$cfg = db()->query('SELECT clave, valor FROM configuracion')->fetchAll(PDO::FETCH_KEY_PAIR);
$campos_form = [
    'nombre_centro'    => 'Nombre del centro',
    'subtitulo_centro' => 'Subtítulo / especialidad',
    'direccion'        => 'Dirección',
    'ciudad'           => 'Ciudad / provincia',
    'telefono'         => 'Teléfono',
    'email_centro'     => 'Email del centro',
    'matricula'        => 'Matrícula / habilitación',
    'pie_informe'      => 'Texto de pie de informe',
];
$diasRetencion = (int)($cfg['dias_retencion_estudios'] ?? 0);
?>

<?php if ($ok): ?>
  <div class="alert alert-success py-2 mb-3">Configuración guardada correctamente.</div>
<?php elseif ($err): ?>
  <div class="alert alert-danger py-2 mb-3"><?= e($err) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <?php csrfField(); ?>
      <input type="hidden" name="accion" value="guardar">
      <div class="row g-3">
        <?php foreach ($campos_form as $clave => $label): ?>
        <div class="col-md-6">
          <label class="form-label small fw-semibold"><?= e($label) ?></label>
          <?php if ($clave === 'pie_informe'): ?>
            <textarea name="<?= $clave ?>" class="form-control form-control-sm" rows="3"><?= e($cfg[$clave] ?? '') ?></textarea>
          <?php else: ?>
            <input type="<?= $clave==='email_centro'?'email':'text' ?>" name="<?= $clave ?>"
                   class="form-control form-control-sm" value="<?= e($cfg[$clave] ?? '') ?>">
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <div class="col-md-6">
          <label class="form-label small fw-semibold">Logo del centro</label>
          <?php if (!empty($cfg['logo_filename'])): ?>
            <div class="mb-2">
              <img src="<?= BASE_URL ?>/uploads/<?= e($cfg['logo_filename']) ?>"
                   alt="Logo" style="max-height:60px;max-width:200px;object-fit:contain;border:1px solid #e3e3e6;border-radius:6px;padding:4px;">
            </div>
          <?php endif; ?>
          <input type="file" name="logo" class="form-control form-control-sm" accept="image/*">
          <div class="form-text">JPG, PNG o SVG. Máx 2 MB.</div>
        </div>
      </div>

      <hr class="my-4">
      <h6 class="fw-semibold mb-3" style="font-size:.85rem;text-transform:uppercase;letter-spacing:.06em;color:var(--accent);">
        <i class="bi bi-trash3"></i> Retención de estudios
      </h6>
      <div class="row g-3 align-items-end">
        <div class="col-md-4">
          <label class="form-label small fw-semibold">Eliminar estudios después de (días)</label>
          <input type="number" name="dias_retencion_estudios" class="form-control form-control-sm"
                 min="0" max="9999" value="<?= $diasRetencion ?>">
          <div class="form-text">Poné <strong>0</strong> para desactivar la eliminación automática.</div>
        </div>
        <div class="col-md-8">
          <div class="alert alert-warning py-2 mb-0 small">
            <i class="bi bi-exclamation-triangle-fill"></i>
            Los estudios (y sus imágenes) se eliminan permanentemente pasados los días indicados desde su fecha de creación.
            La purga corre automáticamente una vez por día al ingresar al panel.
            <?php if ($diasRetencion > 0): ?>
              <br><strong>Activo:</strong> estudios con más de <?= $diasRetencion ?> días serán eliminados.
            <?php else: ?>
              <br><strong>Inactivo:</strong> los estudios no se eliminan automáticamente.
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn btn-sm" style="background:var(--accent);color:#fff;">
          <i class="bi bi-floppy"></i> Guardar configuración
        </button>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/_layout_end.php'; ?>
