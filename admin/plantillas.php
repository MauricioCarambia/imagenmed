<?php
$pageTitle  = 'Plantillas de informe';
$activePage = 'plantillas';
require_once __DIR__ . '/_layout.php';

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfCheck();

// Crear
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear') {
    $tipo   = $_POST['tipo'] ?? '';
    $nombre = trim($_POST['nombre'] ?? '');
    $cuerpo = trim($_POST['cuerpo'] ?? '');
    if ($nombre && $cuerpo) {
        db()->prepare('INSERT INTO plantillas_informe (tipo,nombre,cuerpo) VALUES (?,?,?)')
            ->execute([$tipo, $nombre, $cuerpo]);
        $msg = 'Plantilla creada.';
    }
}

// Editar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'editar') {
    $pid    = (int)($_POST['pid'] ?? 0);
    $tipo   = $_POST['tipo'] ?? '';
    $nombre = trim($_POST['nombre'] ?? '');
    $cuerpo = trim($_POST['cuerpo'] ?? '');
    if ($pid && $nombre && $cuerpo) {
        db()->prepare('UPDATE plantillas_informe SET tipo=?,nombre=?,cuerpo=? WHERE id=?')
            ->execute([$tipo, $nombre, $cuerpo, $pid]);
        $msg = 'Plantilla actualizada.';
    }
}

// Eliminar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    $pid = (int)($_POST['pid'] ?? 0);
    if ($pid) { db()->prepare('DELETE FROM plantillas_informe WHERE id=?')->execute([$pid]); $msg = 'Plantilla eliminada.'; }
}

$plantillas = db()->query('SELECT * FROM plantillas_informe ORDER BY tipo, nombre')->fetchAll();
?>

<?php if ($msg): ?><div class="alert alert-success py-2 mb-3"><?= e($msg) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold small">Nueva plantilla</div>
      <div class="card-body">
        <form method="post" id="form-plantilla">
          <?php csrfField(); ?>
          <input type="hidden" name="accion" value="crear" id="fp-accion">
          <input type="hidden" name="pid" value="" id="fp-pid">
          <div class="mb-2">
            <label class="form-label small fw-semibold">Tipo de estudio</label>
            <select name="tipo" id="fp-tipo" class="form-select form-select-sm">
              <option value="">Todos los tipos</option>
              <?php foreach (TIPOS_ESTUDIO as $k => $v): ?>
                <option value="<?= e($k) ?>"><?= e($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Nombre de la plantilla</label>
            <input type="text" name="nombre" id="fp-nombre" class="form-control form-control-sm" required placeholder="Ej: RX Tórax Normal">
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Texto</label>
            <textarea name="cuerpo" id="fp-cuerpo" class="form-control form-control-sm" rows="7" required placeholder="Texto predefinido del informe..."></textarea>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-sm" style="background:var(--accent);color:#fff;"><i class="bi bi-floppy"></i> Guardar</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="fpLimpiar()">Limpiar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold small">Plantillas guardadas (<?= count($plantillas) ?>)</div>
      <div class="card-body p-0">
        <?php if (!$plantillas): ?>
          <p class="text-muted small p-3 mb-0">No hay plantillas aún.</p>
        <?php else: ?>
        <table class="table table-sm table-hover mb-0" style="font-size:.85rem;">
          <thead class="table-light"><tr><th>Tipo</th><th>Nombre</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($plantillas as $p): ?>
          <tr>
            <td><?= e($p['tipo'] ? labelTipo($p['tipo']) : 'General') ?></td>
            <td><?= e($p['nombre']) ?></td>
            <td class="text-end" style="white-space:nowrap;">
              <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;"
                onclick="fpEditar(<?= $p['id'] ?>,<?= e(json_encode($p['tipo'])) ?>,<?= e(json_encode($p['nombre'])) ?>,<?= e(json_encode($p['cuerpo'])) ?>)">
                <i class="bi bi-pencil"></i>
              </button>
              <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar esta plantilla?')">
                <?php csrfField(); ?>
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="pid" value="<?= $p['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:11px;"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
function fpEditar(id, tipo, nombre, cuerpo) {
  document.getElementById('fp-accion').value = 'editar';
  document.getElementById('fp-pid').value = id;
  document.getElementById('fp-tipo').value = tipo;
  document.getElementById('fp-nombre').value = nombre;
  document.getElementById('fp-cuerpo').value = cuerpo;
  document.getElementById('form-plantilla').scrollIntoView({behavior:'smooth'});
}
function fpLimpiar() {
  document.getElementById('fp-accion').value = 'crear';
  document.getElementById('fp-pid').value = '';
  document.getElementById('fp-tipo').value = '';
  document.getElementById('fp-nombre').value = '';
  document.getElementById('fp-cuerpo').value = '';
}
</script>

<?php require_once __DIR__ . '/_layout_end.php'; ?>
