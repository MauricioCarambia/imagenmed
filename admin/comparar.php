<?php
$pageTitle = 'Comparar imágenes';
$activePage = 'comparar';
require_once __DIR__ . '/_layout.php';

// Pre-carga de paciente por pac_id
$preloadPacId = (int)($_GET['pac_id'] ?? 0);
$preloadQuery = '';
if ($preloadPacId) {
    $pq = db()->prepare('SELECT apellido, nombre FROM pacientes WHERE id=?');
    $pq->execute([$preloadPacId]);
    $pq = $pq->fetch();
    if ($pq) $preloadQuery = $pq['apellido'] . ' ' . $pq['nombre'];
}
?>

<style>
.visor-wrap { background: #0a0e1a; border-radius: 10px; position: relative;
              display: flex; align-items: center; justify-content: center;
              min-height: 320px; overflow: hidden; }
.visor-wrap img, .visor-wrap canvas { max-height: 65vh; max-width: 100%; transition: filter .15s, transform .15s; }
.visor-msg { color: #cbd5e1; font-size: .85rem; text-align: center; padding: 1rem; }
.cmp-toolbar { position: absolute; top: 8px; left: 50%; transform: translateX(-50%);
           display: flex; gap: 4px; background: rgba(0,0,0,.55);
           padding: 4px 8px; border-radius: 999px; z-index: 5; }
.cmp-toolbar-tools { position: absolute; bottom: 8px; left: 50%; transform: translateX(-50%);
           display: flex; gap: 4px; background: rgba(0,0,0,.55);
           padding: 4px 8px; border-radius: 999px; z-index: 5; flex-wrap: wrap;
           justify-content: center; max-width: 95%; }
.tbtn { width: 30px; height: 30px; border-radius: 50%; border: none; background: transparent;
        color: rgba(255,255,255,.75); font-size: 14px; cursor: pointer;
        display: flex; align-items: center; justify-content: center; transition: background .1s; }
.tbtn:hover { background: rgba(255,255,255,.18); color: #fff; }
.tbtn.active { background: #5b8def; color: #fff; }
.cmp-overlay { position: absolute; top: 0; left: 0; pointer-events: none; }
.cmp-badge { position: absolute; top: 8px; left: 8px; z-index: 5; cursor: pointer; }
.cmp-panel-title { font-size: .8rem; font-weight: 600; text-transform: uppercase;
                   letter-spacing: .06em; color: #888; margin-bottom: .5rem; }
[data-bs-theme="dark"] .cmp-panel-title { color: #94a3b8; }
</style>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-2">
      <div class="flex-grow-1">
        <label class="form-label small text-muted mb-1">Buscar paciente (DNI, nombre o apellido)</label>
        <div class="d-flex gap-2 flex-wrap">
          <input type="search" id="cmp-q" class="form-control" style="max-width:320px;" placeholder="Ej: 30123456 o Pérez">
          <button type="button" class="btn btn-sm" style="background:var(--accent);color:#fff;" onclick="cmpBuscar()">
            <i class="bi bi-search"></i> Buscar
          </button>
        </div>
        <div id="cmp-resultado" class="form-text mt-2"></div>
      </div>
      <!-- Sincronización -->
      <div class="form-check form-switch mb-1">
        <input class="form-check-input" type="checkbox" id="sync-toggle" onchange="cmpSetSync(this.checked)">
        <label class="form-check-label small fw-semibold" for="sync-toggle">
          <i class="bi bi-link-45deg"></i> Sync zoom/pan
        </label>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <?php foreach (['a' => 'A', 'b' => 'B'] as $sfx => $label): ?>
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="cmp-panel-title">Imagen <?= $label ?></div>
        <div class="row g-2 mb-2">
          <div class="col-12 col-sm-6">
            <select id="cmp-estudio-<?= $sfx ?>" class="form-select form-select-sm" onchange="cmpCargarImagenes('<?= $sfx ?>')">
              <option value="">Seleccioná un estudio...</option>
            </select>
          </div>
          <div class="col-12 col-sm-6">
            <select id="cmp-imagen-<?= $sfx ?>" class="form-select form-select-sm" onchange="cmpCargarImagen('<?= $sfx ?>')">
              <option value="">Imagen...</option>
            </select>
          </div>
        </div>

        <div class="visor-wrap" id="stage-<?= $sfx ?>">
          <div class="cmp-toolbar">
            <button class="tbtn" onclick="vp<?= strtoupper($sfx) ?>.zoom(1.2)" title="Zoom +"><i class="bi bi-zoom-in"></i></button>
            <button class="tbtn" onclick="vp<?= strtoupper($sfx) ?>.zoom(0.8)" title="Zoom -"><i class="bi bi-zoom-out"></i></button>
            <button class="tbtn" onclick="vp<?= strtoupper($sfx) ?>.rot()" title="Rotar"><i class="bi bi-arrow-clockwise"></i></button>
            <button class="tbtn" onclick="vp<?= strtoupper($sfx) ?>.inv()" title="Invertir"><i class="bi bi-sun"></i></button>
            <button class="tbtn" onclick="vp<?= strtoupper($sfx) ?>.reset()" title="Resetear"><i class="bi bi-arrow-counterclockwise"></i></button>
          </div>
          <img id="img-<?= $sfx ?>" class="d-none" alt="Imagen <?= $label ?>" draggable="false">
          <canvas id="canvas-<?= $sfx ?>" class="d-none"></canvas>
          <canvas id="overlay-<?= $sfx ?>" class="cmp-overlay"></canvas>
          <div id="msg-<?= $sfx ?>" class="visor-msg">Seleccioná un estudio y una imagen para comparar.</div>
          <span id="badge-<?= $sfx ?>" class="badge bg-secondary cmp-badge" onclick="vp<?= strtoupper($sfx) ?>.clearCalib()" title="Escala de medición. Click para borrar la calibración.">Sin calibrar</span>
          <div class="cmp-toolbar-tools">
            <button class="tbtn active" data-tool="pan" onclick="vp<?= strtoupper($sfx) ?>.tool('pan',this)" title="Mover"><i class="bi bi-arrows-move"></i></button>
            <button class="tbtn" data-tool="wl" onclick="vp<?= strtoupper($sfx) ?>.tool('wl',this)" title="Brillo/Contraste (arrastrar)"><i class="bi bi-sliders"></i></button>
            <button class="tbtn" data-tool="length" onclick="vp<?= strtoupper($sfx) ?>.tool('length',this)" title="Medir distancia"><i class="bi bi-rulers"></i></button>
            <button class="tbtn" data-tool="angle" onclick="vp<?= strtoupper($sfx) ?>.tool('angle',this)" title="Medir ángulo"><i class="bi bi-triangle"></i></button>
            <button class="tbtn" data-tool="calibrate" onclick="vp<?= strtoupper($sfx) ?>.tool('calibrate',this)" title="Calibrar escala"><i class="bi bi-arrow-left-right"></i></button>
            <button class="tbtn" data-tool="rect" onclick="vp<?= strtoupper($sfx) ?>.tool('rect',this)" title="Región (ROI)"><i class="bi bi-bounding-box"></i></button>
            <button class="tbtn" data-tool="ellipse" onclick="vp<?= strtoupper($sfx) ?>.tool('ellipse',this)" title="Elipse (ROI oval)"><i class="bi bi-circle"></i></button>
            <button class="tbtn" data-tool="text" onclick="vp<?= strtoupper($sfx) ?>.tool('text',this)" title="Anotación de texto"><i class="bi bi-fonts"></i></button>
            <button class="tbtn" onclick="vp<?= strtoupper($sfx) ?>.clearAnotaciones()" title="Borrar anotaciones"><i class="bi bi-eraser"></i></button>
            <button class="tbtn" onclick="vp<?= strtoupper($sfx) ?>.exportPng()" title="Descargar imagen con anotaciones"><i class="bi bi-image-fill"></i></button>
          </div>
        </div>

        <div class="d-flex flex-wrap gap-3 align-items-center mt-2">
          <div class="d-flex align-items-center gap-2">
            <label class="small text-muted mb-0" id="lb-br-name-<?= $sfx ?>">Brillo</label>
            <input type="range" min="20" max="200" value="100" id="sl-br-<?= $sfx ?>" oninput="vp<?= strtoupper($sfx) ?>.filtro()" style="width:90px;">
            <span id="lb-br-<?= $sfx ?>" class="small">100%</span>
          </div>
          <div class="d-flex align-items-center gap-2">
            <label class="small text-muted mb-0" id="lb-ct-name-<?= $sfx ?>">Contraste</label>
            <input type="range" min="20" max="300" value="100" id="sl-ct-<?= $sfx ?>" oninput="vp<?= strtoupper($sfx) ?>.filtro()" style="width:90px;">
            <span id="lb-ct-<?= $sfx ?>" class="small">100%</span>
          </div>
          <div class="d-flex align-items-center gap-2 d-none" id="frame-ctrl-<?= $sfx ?>">
            <label class="small text-muted mb-0">Corte</label>
            <input type="range" min="0" max="0" value="0" id="sl-frame-<?= $sfx ?>" oninput="vp<?= strtoupper($sfx) ?>.frame()" style="width:90px;">
            <span id="lb-frame-<?= $sfx ?>" class="small">1/1</span>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<script>if (typeof window.require === 'undefined') window.require = function () {};</script>
<script src="<?= BASE_URL ?>/assets/js/daikon.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/dicom-viewer.js"></script>
<script src="<?= BASE_URL ?>/assets/js/image-tools.js"></script>
<script src="<?= BASE_URL ?>/assets/js/visor-panel.js"></script>
<script>
var vpA = VisorPanel.create('-a');
var vpB = VisorPanel.create('-b');
var cmpEstudios = [];
var cmpSync = false;

// ── Sync zoom/pan ─────────────────────────────────────────────
function cmpSetSync(on) {
  cmpSync = on;
  if (on) {
    vpA.setPeer(vpB);
    vpB.setPeer(vpA);
  } else {
    vpA.setPeer(null);
    vpB.setPeer(null);
  }
}

function cmpBuscar() {
  var q = document.getElementById('cmp-q').value.trim();
  var info = document.getElementById('cmp-resultado');
  if (!q) return;
  info.textContent = 'Buscando...';
  fetch('<?= BASE_URL ?>/admin/comparar_buscar.php?q=' + encodeURIComponent(q))
    .then(function (r) { return r.json(); })
    .then(function (data) {
      cmpEstudios = data.estudios || [];
      ['a', 'b'].forEach(function (sfx) {
        var sel = document.getElementById('cmp-estudio-' + sfx);
        sel.innerHTML = '<option value="">Seleccioná un estudio...</option>';
        cmpEstudios.forEach(function (est, idx) {
          var opt = document.createElement('option');
          opt.value = idx;
          opt.textContent = est.paciente + ' · ' + est.tipo + ' · ' + est.fecha + (est.descripcion ? ' · ' + est.descripcion : '');
          sel.appendChild(opt);
        });
        document.getElementById('cmp-imagen-' + sfx).innerHTML = '<option value="">Imagen...</option>';
      });
      if (!cmpEstudios.length) {
        info.textContent = 'No se encontraron estudios con imágenes para esa búsqueda.';
      } else {
        info.textContent = cmpEstudios.length + ' estudio(s) encontrado(s). Seleccioná uno por panel.';
        // Auto-assign first two studies if coming from historial
        if (cmpEstudios.length >= 2) {
          var selA = document.getElementById('cmp-estudio-a');
          var selB = document.getElementById('cmp-estudio-b');
          selA.value = 0; cmpCargarImagenes('a');
          selB.value = 1; cmpCargarImagenes('b');
        } else if (cmpEstudios.length === 1) {
          var selA = document.getElementById('cmp-estudio-a');
          selA.value = 0; cmpCargarImagenes('a');
        }
      }
    })
    .catch(function () { info.textContent = 'Error al buscar.'; });
}

function cmpCargarImagenes(sfx) {
  var selEst = document.getElementById('cmp-estudio-' + sfx);
  var selImg = document.getElementById('cmp-imagen-' + sfx);
  selImg.innerHTML = '<option value="">Imagen...</option>';
  var idx = selEst.value;
  if (idx === '') return;
  var est = cmpEstudios[idx];
  est.imagenes.forEach(function (img, i) {
    var opt = document.createElement('option');
    opt.value = i;
    opt.textContent = img.nombre;
    selImg.appendChild(opt);
  });
  if (est.imagenes.length === 1) {
    selImg.value = 0;
    cmpCargarImagen(sfx);
  }
}

function cmpCargarImagen(sfx) {
  var selEst = document.getElementById('cmp-estudio-' + sfx);
  var selImg = document.getElementById('cmp-imagen-' + sfx);
  if (selEst.value === '' || selImg.value === '') return;
  var est = cmpEstudios[selEst.value];
  var img = est.imagenes[selImg.value];
  var vp = sfx === 'a' ? vpA : vpB;
  vp.cargar(img.url);
}

// Auto-buscar si viene con pac_id
<?php if ($preloadQuery): ?>
document.addEventListener('DOMContentLoaded', function () {
  var q = document.getElementById('cmp-q');
  q.value = <?= json_encode($preloadQuery) ?>;
  cmpBuscar();
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/_layout_end.php'; ?>
