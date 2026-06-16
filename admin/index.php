<?php
$pageTitle  = 'Inicio';
$activePage = 'inicio';
require_once __DIR__ . '/_layout.php';

$db = db();

$totalEstudios  = $db->query('SELECT COUNT(*) FROM estudios')->fetchColumn();
$totalPacientes = $db->query('SELECT COUNT(*) FROM pacientes')->fetchColumn();
$hoy = date('Y-m-d');
$estudiosHoy    = $db->prepare('SELECT COUNT(*) FROM estudios WHERE fecha_estudio = ?');
$estudiosHoy->execute([$hoy]);
$estudiosHoy = $estudiosHoy->fetchColumn();

$ultimos = $db->query(
    'SELECT e.*, p.nombre, p.apellido, p.dni
     FROM estudios e
     JOIN pacientes p ON p.id = e.paciente_id
     ORDER BY e.created_at DESC LIMIT 8'
)->fetchAll();

// Estudios por tipo
$porTipoRaw = $db->query('SELECT tipo, COUNT(*) AS n FROM estudios GROUP BY tipo')->fetchAll();
$porTipo = [];
foreach ($porTipoRaw as $r) {
    $porTipo[$r['tipo']] = (int)$r['n'];
}
$tipoColores = [
    'RX'  => '#1e40af',
    'ECO' => '#065f46',
    'TAC' => '#92400e',
    'MAM' => '#9d174d',
    'RMN' => '#5b21b6',
    'OTR' => '#475569',
];
$tipoLabels = [];
$tipoData   = [];
$tipoBg     = [];
foreach (TIPOS_ESTUDIO as $k => $lbl) {
    if (empty($porTipo[$k])) continue;
    $tipoLabels[] = $lbl;
    $tipoData[]   = $porTipo[$k];
    $tipoBg[]     = $tipoColores[$k];
}

// Estudios por mes (últimos 6 meses)
$porMesRaw = $db->query(
    "SELECT DATE_FORMAT(fecha_estudio, '%Y-%m') AS mes, COUNT(*) AS n
     FROM estudios
     WHERE fecha_estudio >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
     GROUP BY mes"
)->fetchAll();
$porMes = [];
foreach ($porMesRaw as $r) {
    $porMes[$r['mes']] = (int)$r['n'];
}
$meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
$mesLabels = [];
$mesData   = [];
for ($i = 5; $i >= 0; $i--) {
    $ts  = strtotime("-$i months");
    $key = date('Y-m', $ts);
    $mesLabels[] = $meses[(int)date('n', $ts) - 1] . ' ' . date('y', $ts);
    $mesData[]   = $porMes[$key] ?? 0;
}

$mesLabelsJson  = json_encode($mesLabels);
$mesDataJson    = json_encode($mesData);
$tipoLabelsJson = json_encode($tipoLabels);
$tipoDataJson   = json_encode($tipoData);
$tipoBgJson     = json_encode($tipoBg);

// Top médicos derivantes
$topMedicos = $db->query(
    "SELECT medico_der, COUNT(*) AS n FROM estudios
     WHERE medico_der IS NOT NULL AND medico_der <> ''
     GROUP BY medico_der ORDER BY n DESC LIMIT 5"
)->fetchAll();

// Top obras sociales
$topObras = $db->query(
    "SELECT obra_social, COUNT(*) AS n FROM pacientes
     WHERE obra_social IS NOT NULL AND obra_social <> ''
     GROUP BY obra_social ORDER BY n DESC LIMIT 5"
)->fetchAll();

// Estudios próximos a vencer (próximos 7 días)
$porVencer = $db->query(
    "SELECT e.id, e.codigo_acceso, e.vence_en, p.nombre, p.apellido
     FROM estudios e JOIN pacientes p ON p.id = e.paciente_id
     WHERE e.vence_en IS NOT NULL
       AND e.vence_en BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
     ORDER BY e.vence_en ASC LIMIT 5"
)->fetchAll();

// Conteo por estado
$porEstado = $db->query("SELECT estado, COUNT(*) as n FROM estudios GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR);

// Últimos accesos al portal público
$ultimosAccesos = $db->query(
    "SELECT a.accessed_at, a.ip, e.codigo_acceso, p.nombre, p.apellido
     FROM accesos_log a
     JOIN estudios e ON e.id = a.estudio_id
     JOIN pacientes p ON p.id = e.paciente_id
     ORDER BY a.accessed_at DESC LIMIT 5"
)->fetchAll();
?>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small mb-1">Estudios totales</div>
        <div class="fs-3 fw-bold text-primary"><?= $totalEstudios ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small mb-1">Estudios hoy</div>
        <div class="fs-3 fw-bold" style="color:var(--accent);"><?= $estudiosHoy ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small mb-1">Pacientes registrados</div>
        <div class="fs-3 fw-bold text-success"><?= $totalPacientes ?></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-2 mb-3">
  <?php foreach (['pendiente'=>['Pendientes','warning'],'informado'=>['Informados','primary'],'entregado'=>['Entregados','success']] as $est=>[$lbl,$col]): ?>
  <div class="col-4">
    <a href="<?= BASE_URL ?>/admin/estudios.php?estado=<?= $est ?>" class="text-decoration-none">
      <div class="card border-0 shadow-sm p-2 text-center">
        <div class="fw-bold fs-5 text-<?= $col ?>"><?= $porEstado[$est] ?? 0 ?></div>
        <div class="text-muted" style="font-size:.75rem;"><?= $lbl ?></div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold small">Estudios por mes</div>
      <div class="card-body">
        <div style="position:relative;height:240px;">
          <canvas id="chartMeses"></canvas>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold small">Estudios por tipo</div>
      <div class="card-body">
        <?php if ($tipoData): ?>
          <div style="position:relative;height:240px;">
            <canvas id="chartTipos"></canvas>
          </div>
        <?php else: ?>
          <div class="text-center text-muted small py-4">Sin datos todavía.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold small">Médicos derivantes (top 5)</div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($topMedicos as $m): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <?= e($m['medico_der']) ?>
            <span class="badge bg-secondary rounded-pill"><?= $m['n'] ?></span>
          </li>
        <?php endforeach; ?>
        <?php if (empty($topMedicos)): ?>
          <li class="list-group-item text-muted text-center py-3">Sin datos todavía.</li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold small">Obras sociales (top 5)</div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($topObras as $o): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <?= e($o['obra_social']) ?>
            <span class="badge bg-secondary rounded-pill"><?= $o['n'] ?></span>
          </li>
        <?php endforeach; ?>
        <?php if (empty($topObras)): ?>
          <li class="list-group-item text-muted text-center py-3">Sin datos todavía.</li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold small">Links próximos a vencer (7 días)</div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($porVencer as $v): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <a href="<?= BASE_URL ?>/admin/ver_estudio.php?id=<?= $v['id'] ?>" class="text-decoration-none">
              <?= e($v['apellido'] . ', ' . $v['nombre']) ?>
            </a>
            <span class="badge bg-warning text-dark"><?= fmtFecha($v['vence_en']) ?></span>
          </li>
        <?php endforeach; ?>
        <?php if (empty($porVencer)): ?>
          <li class="list-group-item text-muted text-center py-3">Nada por vencer.</li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white fw-semibold small">Últimos accesos al portal público</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 small">
      <thead class="table-light">
        <tr>
          <th>Paciente</th><th>Código</th><th>IP</th><th>Fecha</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($ultimosAccesos as $a): ?>
        <tr>
          <td><?= e($a['apellido'] . ', ' . $a['nombre']) ?></td>
          <td><code><?= e($a['codigo_acceso']) ?></code></td>
          <td><?= e($a['ip']) ?></td>
          <td><?= fmtFecha(substr($a['accessed_at'], 0, 10)) ?> <?= substr($a['accessed_at'], 11, 5) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($ultimosAccesos)): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">Sin accesos registrados aún.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <span class="fw-semibold small">Últimos estudios</span>
    <a href="<?= BASE_URL ?>/admin/estudios.php" class="btn btn-sm btn-outline-secondary">Ver todos</a>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 small">
      <thead class="table-light">
        <tr>
          <th>Paciente</th><th>DNI</th><th>Tipo</th><th>Fecha</th><th>Código</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($ultimos as $e): ?>
        <tr>
          <td><?= e($e['apellido'] . ', ' . $e['nombre']) ?></td>
          <td><?= e($e['dni']) ?></td>
          <td><span class="badge-tipo badge-<?= e($e['tipo']) ?>"><?= e($e['tipo']) ?></span></td>
          <td><?= fmtFecha($e['fecha_estudio']) ?></td>
          <td><code><?= e($e['codigo_acceso']) ?></code></td>
          <td>
            <a href="<?= BASE_URL ?>/admin/ver_estudio.php?id=<?= $e['id'] ?>"
               class="btn btn-sm btn-outline-primary py-0">Ver</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($ultimos)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Sin estudios cargados aún.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('chartMeses'), {
  type: 'bar',
  data: {
    labels: {$mesLabelsJson},
    datasets: [{
      label: 'Estudios',
      data: {$mesDataJson},
      backgroundColor: '#5b8def'
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
  }
});

JS;
if ($tipoData) {
    $extraJs .= <<<JS
new Chart(document.getElementById('chartTipos'), {
  type: 'doughnut',
  data: {
    labels: {$tipoLabelsJson},
    datasets: [{
      data: {$tipoDataJson},
      backgroundColor: {$tipoBgJson}
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } }
  }
});
JS;
}
$extraJs .= '</script>';
require_once __DIR__ . '/_layout_end.php';
?>