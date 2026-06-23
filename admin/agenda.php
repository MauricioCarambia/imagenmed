<?php
$pageTitle  = 'Agenda de turnos';
$activePage = 'agenda';
require_once __DIR__ . '/_layout.php';

if (!puedeHacer('ver_estudios')) {
    echo '<div class="alert alert-warning">Acceso restringido.</div>';
    require_once __DIR__ . '/_layout_end.php';
    exit;
}

$ok    = '';
$error = '';

// ── POST handlers ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear' && puedeHacer('crear_estudio')) {
        $fecha      = $_POST['fecha']      ?? '';
        $hora       = $_POST['hora']       ?? '';
        $tipo       = $_POST['tipo']       ?? 'OTR';
        $pac_id     = (int)($_POST['paciente_id'] ?? 0);
        $nombre_pac = trim($_POST['nombre_pac'] ?? '');
        $desc       = trim($_POST['descripcion'] ?? '');
        $medico     = trim($_POST['medico_der']  ?? '');
        $notas      = trim($_POST['notas']       ?? '');

        if (!array_key_exists($tipo, TIPOS_ESTUDIO)) $tipo = 'OTR';

        if (!$fecha || !$hora) {
            $error = 'La fecha y hora son obligatorias.';
        } elseif (!$pac_id && $nombre_pac === '') {
            $error = 'Ingresá el paciente o un nombre.';
        } else {
            db()->prepare('INSERT INTO turnos (paciente_id,nombre_pac,tipo,descripcion,medico_der,fecha,hora,notas,created_by)
                           VALUES (?,?,?,?,?,?,?,?,?)')
               ->execute([$pac_id ?: null, $nombre_pac, $tipo, $desc, $medico, $fecha, $hora, $notas, $u['id']]);
            registrarAuditoria('crear', 'turno', (int)db()->lastInsertId());
            $ok = 'Turno creado correctamente.';
        }
    }

    if ($accion === 'estado') {
        $tid    = (int)($_POST['turno_id'] ?? 0);
        $estado = $_POST['estado'] ?? '';
        if ($tid && in_array($estado, ['pendiente','confirmado','cancelado','realizado'])) {
            db()->prepare('UPDATE turnos SET estado=? WHERE id=?')->execute([$estado, $tid]);
            registrarAuditoria('cambiar_estado', 'turno', $tid, $estado);
            $ok = 'Estado actualizado.';
        }
    }

    if ($accion === 'eliminar' && ($u['rol'] ?? '') === 'admin') {
        $tid = (int)($_POST['turno_id'] ?? 0);
        if ($tid) {
            db()->prepare('DELETE FROM turnos WHERE id=?')->execute([$tid]);
            registrarAuditoria('eliminar', 'turno', $tid);
            $ok = 'Turno eliminado.';
        }
    }
}

// ── Filtros ─────────────────────────────────────────────────
$filtroFecha  = $_GET['fecha']  ?? date('Y-m-d');
$filtroEstado = $_GET['estado'] ?? '';
$filtroSemana = isset($_GET['semana']);

if ($filtroSemana) {
    $lunes    = date('Y-m-d', strtotime('monday this week'));
    $domingo  = date('Y-m-d', strtotime('sunday this week'));
    $whereF   = 'fecha BETWEEN ? AND ?';
    $paramsF  = [$lunes, $domingo];
} elseif ($filtroFecha) {
    $whereF  = 'fecha = ?';
    $paramsF = [$filtroFecha];
} else {
    $whereF  = '1=1';
    $paramsF = [];
}

$whereE  = '';
$paramsE = [];
if ($filtroEstado && in_array($filtroEstado, ['pendiente','confirmado','cancelado','realizado'])) {
    $whereE  = ' AND t.estado = ?';
    $paramsE = [$filtroEstado];
}

$turnos = db()->prepare(
    "SELECT t.*, p.nombre AS pac_nombre, p.apellido AS pac_apellido, p.dni AS pac_dni,
            p.telefono AS pac_tel
     FROM turnos t
     LEFT JOIN pacientes p ON p.id = t.paciente_id
     WHERE $whereF $whereE
     ORDER BY t.fecha ASC, t.hora ASC"
);
$turnos->execute(array_merge($paramsF, $paramsE));
$turnos = $turnos->fetchAll();

// Pacientes para el selector del modal
$pacientes = db()->query('SELECT id, apellido, nombre, dni FROM pacientes ORDER BY apellido, nombre')->fetchAll();

$estadoColors = [
    'pendiente'   => 'bg-warning text-dark',
    'confirmado'  => 'bg-primary text-white',
    'cancelado'   => 'bg-danger text-white',
    'realizado'   => 'bg-success text-white',
];
$estadoLabels = [
    'pendiente'  => 'Pendiente',
    'confirmado' => 'Confirmado',
    'cancelado'  => 'Cancelado',
    'realizado'  => 'Realizado',
];

// Navegación de fecha
$fechaAnterior = date('Y-m-d', strtotime($filtroFecha . ' -1 day'));
$fechaSiguiente = date('Y-m-d', strtotime($filtroFecha . ' +1 day'));
?>

<?php if ($ok): ?>
  <div class="alert alert-success py-2 small"><?= e($ok) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger py-2 small"><?= e($error) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">

  <!-- Navegación de fecha -->
  <div class="d-flex align-items-center gap-2">
    <a href="?fecha=<?= $fechaAnterior ?>" class="btn btn-sm btn-outline-secondary py-0">‹</a>
    <form method="get" class="d-flex align-items-center gap-1">
      <input type="date" name="fecha" class="form-control form-control-sm" value="<?= e($filtroFecha) ?>"
             onchange="this.form.submit()" style="width:140px;">
    </form>
    <a href="?fecha=<?= $fechaSiguiente ?>" class="btn btn-sm btn-outline-secondary py-0">›</a>
    <a href="?fecha=<?= date('Y-m-d') ?>" class="btn btn-sm btn-outline-secondary py-0">Hoy</a>
    <a href="?semana=1" class="btn btn-sm btn-outline-secondary py-0">Esta semana</a>
  </div>

  <!-- Filtro estado + nuevo turno -->
  <div class="d-flex gap-2 align-items-center">
    <form method="get" class="d-flex gap-1">
      <input type="hidden" name="fecha" value="<?= e($filtroFecha) ?>">
      <select name="estado" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
        <option value="">Todos</option>
        <?php foreach ($estadoLabels as $val => $lbl): ?>
          <option value="<?= $val ?>" <?= $filtroEstado===$val?'selected':'' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if (puedeHacer('crear_estudio')): ?>
    <button class="btn btn-sm" style="background:var(--accent);color:#fff;" data-cmodal-open="modalNuevoTurno">
      <i class="bi bi-calendar-plus"></i> Nuevo turno
    </button>
    <?php endif; ?>
  </div>
</div>

<!-- Tabla de turnos -->
<div class="card border-0 shadow-sm">
  <?php if ($filtroSemana): ?>
  <div class="card-header bg-white small fw-semibold">
    Semana del <?= date('d/m', strtotime($lunes)) ?> al <?= date('d/m/Y', strtotime($domingo)) ?>
    · <?= count($turnos) ?> turno<?= count($turnos)!=1?'s':'' ?>
  </div>
  <?php else: ?>
  <div class="card-header bg-white small fw-semibold">
    <?= date('l d/m/Y', strtotime($filtroFecha)) ?>
    · <?= count($turnos) ?> turno<?= count($turnos)!=1?'s':'' ?>
  </div>
  <?php endif; ?>

  <?php if (empty($turnos)): ?>
    <div class="card-body text-center text-muted py-5">
      <i class="bi bi-calendar2-x fs-2 d-block mb-2"></i>
      Sin turnos para este día.
    </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover mb-0 small align-middle">
      <thead class="table-light">
        <tr>
          <th style="width:70px;">Hora</th>
          <th>Paciente</th>
          <th>Estudio</th>
          <th>Médico derivante</th>
          <th>Estado</th>
          <th>Notas</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($turnos as $t): ?>
        <?php
        $nombre = $t['paciente_id']
            ? e($t['pac_apellido'] . ', ' . $t['pac_nombre'])
            : e($t['nombre_pac']) . ' <span class="text-muted">(sin ficha)</span>';
        ?>
        <tr class="<?= $t['estado']==='cancelado' ? 'opacity-50' : '' ?>">
          <td class="fw-semibold"><?= substr($t['hora'],0,5) ?></td>
          <td>
            <?= $nombre ?>
            <?php if ($t['pac_dni']): ?>
              <div class="text-muted" style="font-size:.75rem;">DNI <?= e($t['pac_dni']) ?>
                <?= $t['pac_tel'] ? ' · '.e($t['pac_tel']) : '' ?>
              </div>
            <?php endif; ?>
            <?php if ($filtroSemana): ?>
              <div class="text-muted" style="font-size:.72rem;"><?= date('d/m', strtotime($t['fecha'])) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge-tipo badge-<?= e($t['tipo']) ?>"><?= e(labelTipo($t['tipo'])) ?></span>
            <?php if ($t['descripcion']): ?>
              <div class="text-muted" style="font-size:.75rem;"><?= e($t['descripcion']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= e($t['medico_der']) ?></td>
          <td>
            <form method="post" class="d-inline">
              <?php csrfField(); ?>
              <input type="hidden" name="accion" value="estado">
              <input type="hidden" name="turno_id" value="<?= $t['id'] ?>">
              <input type="hidden" name="fecha" value="<?= e($filtroFecha) ?>">
              <select name="estado" class="form-select form-select-sm py-0"
                      onchange="this.form.submit()" style="font-size:.75rem;width:auto;">
                <?php foreach ($estadoLabels as $val => $lbl): ?>
                  <option value="<?= $val ?>" <?= $t['estado']===$val?'selected':'' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
          <td class="text-muted"><?= e($t['notas']) ?></td>
          <td class="text-nowrap">
            <?php if ($t['estudio_id']): ?>
              <a href="ver_estudio.php?id=<?= $t['estudio_id'] ?>"
                 class="btn btn-sm btn-outline-primary py-0" title="Ver estudio">
                <i class="bi bi-eye"></i>
              </a>
            <?php elseif ($t['estado'] !== 'cancelado' && puedeHacer('crear_estudio')): ?>
              <?php
              $qs = http_build_query([
                'paciente_id'  => $t['paciente_id'] ?? '',
                'nombre_pac'   => $t['nombre_pac']  ?? '',
                'tipo'         => $t['tipo'],
                'descripcion'  => $t['descripcion'] ?? '',
                'medico_der'   => $t['medico_der']  ?? '',
                'fecha_estudio'=> $t['fecha'],
                'turno_id'     => $t['id'],
              ]);
              ?>
              <a href="nuevo_estudio.php?<?= $qs ?>"
                 class="btn btn-sm btn-outline-success py-0" title="Crear estudio para este turno">
                <i class="bi bi-plus-circle"></i> Estudio
              </a>
            <?php endif; ?>
            <?php if (($u['rol'] ?? '') === 'admin'): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar este turno?')">
              <?php csrfField(); ?>
              <input type="hidden" name="accion" value="eliminar">
              <input type="hidden" name="turno_id" value="<?= $t['id'] ?>">
              <input type="hidden" name="fecha" value="<?= e($filtroFecha) ?>">
              <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
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

<!-- Modal nuevo turno -->
<div class="cmodal-overlay" id="modalNuevoTurno">
  <div class="cmodal-box" style="max-width:520px;">
    <form method="post">
      <?php csrfField(); ?>
      <input type="hidden" name="accion" value="crear">
      <input type="hidden" name="fecha_nav" value="<?= e($filtroFecha) ?>">
      <div class="cmodal-header">
        <h5>Nuevo turno</h5>
        <button type="button" class="btn-close" data-cmodal-close></button>
      </div>
      <div class="cmodal-body">
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label small fw-semibold">Fecha <span class="text-danger">*</span></label>
            <input type="date" name="fecha" class="form-control form-control-sm"
                   value="<?= e($filtroFecha) ?>" required>
          </div>
          <div class="col-6">
            <label class="form-label small fw-semibold">Hora <span class="text-danger">*</span></label>
            <input type="time" name="hora" class="form-control form-control-sm" required>
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold">Paciente</label>
            <select name="paciente_id" id="nt-pac" class="form-select form-select-sm"
                    onchange="document.getElementById('nt-nombre').style.display=this.value?'none':'block';">
              <option value="">— Sin ficha / ingresar nombre —</option>
              <?php foreach ($pacientes as $p): ?>
                <option value="<?= $p['id'] ?>"><?= e($p['apellido'].', '.$p['nombre'].' · DNI '.$p['dni']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12" id="nt-nombre">
            <label class="form-label small">Nombre (si no tiene ficha)</label>
            <input type="text" name="nombre_pac" class="form-control form-control-sm" placeholder="Apellido, Nombre">
          </div>
          <div class="col-6">
            <label class="form-label small fw-semibold">Tipo de estudio</label>
            <select name="tipo" class="form-select form-select-sm">
              <?php foreach (TIPOS_ESTUDIO as $k => $lbl): ?>
                <option value="<?= $k ?>"><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label small">Médico derivante</label>
            <input type="text" name="medico_der" class="form-control form-control-sm">
          </div>
          <div class="col-12">
            <label class="form-label small">Descripción</label>
            <input type="text" name="descripcion" class="form-control form-control-sm">
          </div>
          <div class="col-12">
            <label class="form-label small">Notas internas</label>
            <textarea name="notas" class="form-control form-control-sm" rows="2"></textarea>
          </div>
        </div>
      </div>
      <div class="cmodal-footer">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-cmodal-close>Cancelar</button>
        <button class="btn btn-sm" style="background:var(--accent);color:#fff;">Guardar turno</button>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/_layout_end.php'; ?>
