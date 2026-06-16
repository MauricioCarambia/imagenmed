<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
require_once __DIR__ . '/../config/helpers.php';

// Exportar a CSV (antes de imprimir cualquier HTML)
if (($_GET['export'] ?? '') === 'csv') {
    $buscarCsv = trim($_GET['q'] ?? '');
    $whereCsv  = '1=1';
    $paramsCsv = [];
    if ($buscarCsv) {
        $whereCsv = '(dni LIKE ? OR nombre LIKE ? OR apellido LIKE ?)';
        $likeCsv = "%$buscarCsv%";
        $paramsCsv = [$likeCsv, $likeCsv, $likeCsv];
    }
    $stmtCsv = db()->prepare(
        "SELECT p.*, (SELECT COUNT(*) FROM estudios e WHERE e.paciente_id = p.id) AS n_estudios
         FROM pacientes p WHERE $whereCsv ORDER BY p.apellido, p.nombre"
    );
    $stmtCsv->execute($paramsCsv);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="pacientes.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM para Excel
    fputcsv($out, ['Apellido', 'Nombre', 'DNI', 'Fecha nac.', 'Teléfono', 'Email', 'Obra social', 'Nº afiliado', 'Estudios']);
    while ($r = $stmtCsv->fetch()) {
        fputcsv($out, [
            $r['apellido'], $r['nombre'], $r['dni'], $r['fecha_nac'],
            $r['telefono'], $r['email'], $r['obra_social'], $r['nro_afiliado'], $r['n_estudios'],
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle  = 'Pacientes';
$activePage = 'pacientes';
require_once __DIR__ . '/_layout.php';

$error = '';
$ok    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'editar') {
    $id          = (int)($_POST['id'] ?? 0);
    $nombre      = trim($_POST['nombre'] ?? '');
    $apellido    = trim($_POST['apellido'] ?? '');
    $dni         = trim($_POST['dni'] ?? '');
    $fechaNac    = $_POST['fecha_nac'] ?? '';
    $telefono    = trim($_POST['telefono'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $obraSoc     = trim($_POST['obra_social'] ?? '');
    $nroAfil     = trim($_POST['nro_afiliado'] ?? '');

    if ($nombre === '' || $apellido === '' || $dni === '') {
        $error = 'Nombre, apellido y DNI son obligatorios.';
    } else {
        $existe = db()->prepare('SELECT id FROM pacientes WHERE dni = ? AND id <> ?');
        $existe->execute([$dni, $id]);
        if ($existe->fetch()) {
            $error = 'Ya existe otro paciente con ese DNI.';
        } else {
            db()->prepare(
                'UPDATE pacientes SET nombre=?, apellido=?, dni=?, fecha_nac=?, telefono=?, email=?, obra_social=?, nro_afiliado=? WHERE id=?'
            )->execute([$nombre, $apellido, $dni, $fechaNac ?: null, $telefono, $email, $obraSoc, $nroAfil, $id]);
            registrarAuditoria('editar', 'paciente', $id, $apellido . ', ' . $nombre);
            $ok = 'Datos del paciente actualizados.';
        }
    }
}

$buscar = trim($_GET['q'] ?? '');
$pag    = max(1, (int)($_GET['pag'] ?? 1));
$porPag = 20;
$offset = ($pag - 1) * $porPag;

$where  = '1=1';
$params = [];

if ($buscar) {
    $where = '(dni LIKE ? OR nombre LIKE ? OR apellido LIKE ?)';
    $like = "%$buscar%";
    $params = [$like, $like, $like];
}

// Orden
$columnas = [
    'apellido'    => 'p.apellido, p.nombre',
    'dni'         => 'p.dni',
    'telefono'    => 'p.telefono',
    'obra_social' => 'p.obra_social',
    'n_estudios'  => 'n_estudios',
];
$sort = $_GET['sort'] ?? 'apellido';
$dir  = (($_GET['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
if (!array_key_exists($sort, $columnas)) $sort = 'apellido';
$orderBy = $columnas[$sort] . ' ' . $dir;

$total = db()->prepare("SELECT COUNT(*) FROM pacientes WHERE $where");
$total->execute($params);
$total = (int)$total->fetchColumn();
$paginas = max(1, ceil($total / $porPag));

$stmt = db()->prepare(
    "SELECT p.*, (SELECT COUNT(*) FROM estudios e WHERE e.paciente_id = p.id) AS n_estudios
     FROM pacientes p
     WHERE $where
     ORDER BY $orderBy
     LIMIT $porPag OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Helper para armar el link de orden de cada columna
function ordenLink(string $col, string $label, string $sort, string $dir): string {
    $nuevoDir = ($sort === $col && $dir === 'ASC') ? 'desc' : 'asc';
    $params = array_merge($_GET, ['sort' => $col, 'dir' => $nuevoDir]);
    unset($params['pag']);
    $icon = '';
    if ($sort === $col) {
        $icon = ' <i class="bi bi-caret-' . ($dir === 'ASC' ? 'up' : 'down') . '-fill"></i>';
    }
    return '<a href="?' . e(http_build_query($params)) . '" class="text-decoration-none text-reset">' . e($label) . $icon . '</a>';
}
?>

<?php if ($error): ?>
  <div class="alert alert-danger py-2 small"><?= e($error) ?></div>
<?php endif; ?>
<?php if ($ok): ?>
  <div class="alert alert-success py-2 small"><?= e($ok) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-auto">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Buscar por nombre, apellido o DNI..."
               value="<?= e($buscar) ?>">
      </div>
      <div class="col-auto">
        <button class="btn btn-sm btn-outline-secondary">Buscar</button>
        <?php if ($buscar): ?>
          <a href="pacientes.php" class="btn btn-sm btn-link text-muted">Limpiar</a>
        <?php endif; ?>
      </div>
      <div class="col-auto ms-auto text-muted small d-flex align-items-center gap-2">
        <?= $total ?> resultado<?= $total!=1?'s':'' ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-sm btn-outline-success">
          <i class="bi bi-file-earmark-spreadsheet"></i> Exportar CSV
        </a>
      </div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 small align-middle">
      <thead class="table-light">
        <tr>
          <th><?= ordenLink('apellido', 'Apellido', $sort, $dir) ?></th>
          <th>Nombre</th>
          <th><?= ordenLink('dni', 'DNI', $sort, $dir) ?></th>
          <th><?= ordenLink('telefono', 'Teléfono', $sort, $dir) ?></th>
          <th><?= ordenLink('obra_social', 'Obra social', $sort, $dir) ?></th>
          <th><?= ordenLink('n_estudios', 'Estudios', $sort, $dir) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['apellido']) ?></td>
          <td><?= e($r['nombre']) ?></td>
          <td><?= e($r['dni']) ?></td>
          <td><?= e($r['telefono'] ?: '—') ?></td>
          <td><?= e($r['obra_social'] ?: '—') ?></td>
          <td><span class="badge bg-secondary"><?= $r['n_estudios'] ?></span></td>
          <td class="text-nowrap">
            <a href="<?= BASE_URL ?>/admin/historial_paciente.php?pac_id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="Historial" style="font-size:.75rem;"><i class="bi bi-clock-history"></i></a>
            <a href="estudios.php?q=<?= urlencode($r['dni']) ?>" class="btn btn-sm btn-outline-primary py-0">
              Ver estudios
            </a>
            <button class="btn btn-sm btn-outline-secondary py-0"
                    data-cmodal-open="modalEditar<?= $r['id'] ?>">
              <i class="bi bi-pencil"></i>
            </button>
          </td>
        </tr>

        <!-- Modal editar paciente -->
        <div class="cmodal-overlay" id="modalEditar<?= $r['id'] ?>">
          <div class="cmodal-box">
            <form method="post">
              <input type="hidden" name="accion" value="editar">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <div class="cmodal-header">
                <h5>Editar paciente</h5>
                <button type="button" class="btn-close" data-cmodal-close></button>
              </div>
              <div class="cmodal-body">
                <div class="row g-2">
                  <div class="col-6">
                    <label class="form-label small">Apellido *</label>
                    <input type="text" name="apellido" class="form-control form-control-sm" required
                           value="<?= e($r['apellido']) ?>">
                  </div>
                  <div class="col-6">
                    <label class="form-label small">Nombre *</label>
                    <input type="text" name="nombre" class="form-control form-control-sm" required
                           value="<?= e($r['nombre']) ?>">
                  </div>
                  <div class="col-6">
                    <label class="form-label small">DNI *</label>
                    <input type="text" name="dni" class="form-control form-control-sm" required
                           value="<?= e($r['dni']) ?>">
                  </div>
                  <div class="col-6">
                    <label class="form-label small">Fecha de nacimiento</label>
                    <input type="date" name="fecha_nac" class="form-control form-control-sm"
                           value="<?= e($r['fecha_nac'] ?? '') ?>">
                  </div>
                  <div class="col-6">
                    <label class="form-label small">Teléfono</label>
                    <input type="text" name="telefono" class="form-control form-control-sm"
                           value="<?= e($r['telefono'] ?? '') ?>">
                  </div>
                  <div class="col-6">
                    <label class="form-label small">Email</label>
                    <input type="email" name="email" class="form-control form-control-sm"
                           value="<?= e($r['email'] ?? '') ?>">
                  </div>
                  <div class="col-6">
                    <label class="form-label small">Obra social</label>
                    <input type="text" name="obra_social" class="form-control form-control-sm"
                           value="<?= e($r['obra_social'] ?? '') ?>">
                  </div>
                  <div class="col-6">
                    <label class="form-label small">Nº afiliado</label>
                    <input type="text" name="nro_afiliado" class="form-control form-control-sm"
                           value="<?= e($r['nro_afiliado'] ?? '') ?>">
                  </div>
                </div>
              </div>
              <div class="cmodal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-cmodal-close>Cancelar</button>
                <button class="btn btn-sm" style="background:var(--accent);color:#fff;">Guardar</button>
              </div>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Sin pacientes registrados.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($paginas > 1): ?>
  <div class="card-footer bg-white d-flex justify-content-center">
    <nav><ul class="pagination pagination-sm mb-0">
      <?php for ($i = 1; $i <= $paginas; $i++): ?>
        <li class="page-item <?= $i===$pag?'active':'' ?>">
          <a class="page-link" href="?<?= e(http_build_query(array_merge($_GET, ['pag' => $i]))) ?>"><?= $i ?></a>
        </li>
      <?php endfor; ?>
    </ul></nav>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_layout_end.php'; ?>
