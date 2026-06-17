<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
require_once __DIR__ . '/../config/helpers.php';

$buscarFiltro = trim($_GET['q'] ?? '');
$tipoFiltro   = $_GET['tipo'] ?? '';
$estadoFiltro = $_GET['estado'] ?? '';
$fechaDesde   = $_GET['desde'] ?? '';
$fechaHasta   = $_GET['hasta'] ?? '';

$whereFiltro = ['1=1'];
$paramsFiltro = [];

if ($buscarFiltro) {
    $whereFiltro[] = '(p.dni LIKE ? OR p.nombre LIKE ? OR p.apellido LIKE ? OR e.medico_der LIKE ?)';
    $likeFiltro = "%$buscarFiltro%";
    $paramsFiltro = array_merge($paramsFiltro, [$likeFiltro, $likeFiltro, $likeFiltro, $likeFiltro]);
}
if ($tipoFiltro && array_key_exists($tipoFiltro, TIPOS_ESTUDIO)) {
    $whereFiltro[] = 'e.tipo = ?';
    $paramsFiltro[] = $tipoFiltro;
}
if ($estadoFiltro && in_array($estadoFiltro, ['pendiente','informado','entregado'])) {
    $whereFiltro[] = 'e.estado = ?';
    $paramsFiltro[] = $estadoFiltro;
}
if ($fechaDesde && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) {
    $whereFiltro[] = 'e.fecha_estudio >= ?';
    $paramsFiltro[] = $fechaDesde;
}
if ($fechaHasta && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) {
    $whereFiltro[] = 'e.fecha_estudio <= ?';
    $paramsFiltro[] = $fechaHasta;
}

$whereStrFiltro = implode(' AND ', $whereFiltro);

// Exportar a CSV (antes de imprimir cualquier HTML)
if (($_GET['export'] ?? '') === 'csv') {
    $stmtCsv = db()->prepare(
        "SELECT e.*, p.nombre, p.apellido, p.dni,
                (SELECT COUNT(*) FROM imagenes WHERE estudio_id=e.id) AS n_imgs
         FROM estudios e
         JOIN pacientes p ON p.id = e.paciente_id
         WHERE $whereStrFiltro
         ORDER BY e.created_at DESC"
    );
    $stmtCsv->execute($paramsFiltro);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="estudios.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM para Excel
    fputcsv($out, ['Apellido', 'Nombre', 'DNI', 'Tipo', 'Descripción', 'Médico derivante', 'Fecha', 'Imágenes', 'Código de acceso', 'Estado']);
    while ($r = $stmtCsv->fetch()) {
        fputcsv($out, [
            $r['apellido'], $r['nombre'], $r['dni'], labelTipo($r['tipo']),
            $r['descripcion'], $r['medico_der'], $r['fecha_estudio'], $r['n_imgs'], $r['codigo_acceso'],
            $r['estado'] ?? 'pendiente',
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle  = 'Estudios';
$activePage = 'estudios';
require_once __DIR__ . '/_layout.php';

$buscar = $buscarFiltro;
$tipo   = $tipoFiltro;
$pag    = max(1, (int)($_GET['pag'] ?? 1));
$porPag = 20;
$offset = ($pag - 1) * $porPag;
$where  = $whereFiltro;
$params = $paramsFiltro;
$whereStr = $whereStrFiltro;

// Orden
$columnasE = [
    'paciente' => 'p.apellido, p.nombre',
    'dni'      => 'p.dni',
    'tipo'     => 'e.tipo',
    'fecha'    => 'e.fecha_estudio',
    'n_imgs'   => 'n_imgs',
];
$sort = $_GET['sort'] ?? 'fecha';
$dir  = (($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
if (!array_key_exists($sort, $columnasE)) $sort = 'fecha';
$orderBy = $columnasE[$sort] . ' ' . $dir;

$total = db()->prepare("SELECT COUNT(*) FROM estudios e JOIN pacientes p ON p.id=e.paciente_id WHERE $whereStr");
$total->execute($params);
$total = (int)$total->fetchColumn();
$paginas = max(1, ceil($total / $porPag));

$stmt = db()->prepare(
    "SELECT e.*, p.nombre, p.apellido, p.dni,
            (SELECT COUNT(*) FROM imagenes WHERE estudio_id=e.id) AS n_imgs
     FROM estudios e
     JOIN pacientes p ON p.id = e.paciente_id
     WHERE $whereStr
     ORDER BY $orderBy
     LIMIT $porPag OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

function ordenLinkEstudios(string $col, string $label, string $sort, string $dir): string {
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

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-sm-auto">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Paciente, DNI o médico..."
               value="<?= e($buscar) ?>">
      </div>
      <div class="col-sm-auto">
        <select name="tipo" class="form-select form-select-sm">
          <option value="">Todos los tipos</option>
          <?php foreach (TIPOS_ESTUDIO as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= $tipo===$k?'selected':'' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-auto">
        <select name="estado" class="form-select form-select-sm">
          <option value="">Todos los estados</option>
          <option value="pendiente" <?= $estadoFiltro==='pendiente'?'selected':'' ?>>Pendiente</option>
          <option value="informado" <?= $estadoFiltro==='informado'?'selected':'' ?>>Informado</option>
          <option value="entregado" <?= $estadoFiltro==='entregado'?'selected':'' ?>>Entregado</option>
        </select>
      </div>
      <div class="col-sm-auto d-flex align-items-center gap-1">
        <input type="date" name="desde" class="form-control form-control-sm" value="<?= e($fechaDesde) ?>" title="Desde">
        <span class="text-muted small">—</span>
        <input type="date" name="hasta" class="form-control form-control-sm" value="<?= e($fechaHasta) ?>" title="Hasta">
      </div>
      <div class="col-sm-auto">
        <button class="btn btn-sm btn-outline-secondary">Filtrar</button>
        <?php if ($buscar || $tipo || $estadoFiltro || $fechaDesde || $fechaHasta): ?>
          <a href="estudios.php" class="btn btn-sm btn-link text-muted">Limpiar</a>
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
          <th><?= ordenLinkEstudios('paciente', 'Paciente', $sort, $dir) ?></th>
          <th><?= ordenLinkEstudios('dni', 'DNI', $sort, $dir) ?></th>
          <th><?= ordenLinkEstudios('tipo', 'Tipo', $sort, $dir) ?></th>
          <th>Descripción</th>
          <th><?= ordenLinkEstudios('fecha', 'Fecha', $sort, $dir) ?></th>
          <th><?= ordenLinkEstudios('n_imgs', 'Imgs', $sort, $dir) ?></th>
          <th>Estado</th>
          <th>Código</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['apellido'] . ', ' . $r['nombre']) ?></td>
          <td><?= e($r['dni']) ?></td>
          <td><span class="badge-tipo badge-<?= e($r['tipo']) ?>"><?= e($r['tipo']) ?></span></td>
          <td><?= e($r['descripcion']) ?></td>
          <td><?= fmtFecha($r['fecha_estudio']) ?></td>
          <td><?= $r['n_imgs'] ?></td>
          <?php
          $eColors = ['pendiente'=>'bg-warning text-dark','informado'=>'bg-primary','entregado'=>'bg-success'];
          $eLabels = ['pendiente'=>'Pendiente','informado'=>'Informado','entregado'=>'Entregado'];
          $eActual = $r['estado'] ?? 'pendiente';
          ?>
          <td><span class="badge <?= $eColors[$eActual] ?>"><?= $eLabels[$eActual] ?></span></td>
          <td><code><?= e($r['codigo_acceso']) ?></code></td>
          <td class="text-nowrap">
            <a href="ver_estudio.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary py-0">Ver</a>
            <a href="imprimir_qr.php?id=<?= $r['id'] ?>" target="_blank"
               class="btn btn-sm btn-outline-secondary py-0"><i class="bi bi-printer"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">Sin resultados.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($paginas > 1): ?>
  <div class="card-footer bg-white d-flex justify-content-between align-items-center">
    <span class="text-muted small">Página <?= $pag ?> de <?= $paginas ?></span>
    <nav><ul class="pagination pagination-sm mb-0">
      <li class="page-item <?= $pag===1?'disabled':'' ?>">
        <a class="page-link" href="?<?= e(http_build_query(array_merge($_GET, ['pag' => $pag-1]))) ?>">‹</a>
      </li>
      <?php
      $rango = [];
      for ($i = 1; $i <= $paginas; $i++) {
          if ($i===1 || $i===$paginas || abs($i-$pag)<=2) $rango[] = $i;
      }
      $prev = null;
      foreach ($rango as $i):
          if ($prev !== null && $i - $prev > 1): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php endif; ?>
          <li class="page-item <?= $i===$pag?'active':'' ?>">
            <a class="page-link" href="?<?= e(http_build_query(array_merge($_GET, ['pag' => $i]))) ?>"><?= $i ?></a>
          </li>
      <?php $prev = $i; endforeach; ?>
      <li class="page-item <?= $pag===$paginas?'disabled':'' ?>">
        <a class="page-link" href="?<?= e(http_build_query(array_merge($_GET, ['pag' => $pag+1]))) ?>">›</a>
      </li>
    </ul></nav>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_layout_end.php'; ?>