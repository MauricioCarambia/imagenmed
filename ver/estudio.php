<?php
// ver/index.php enruta acá, o se llama directamente via .htaccess
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

// Obtener código desde URL segment o GET
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$seg    = trim(str_replace(parse_url(BASE_URL, PHP_URL_PATH) . '/ver', '', $uri), '/');
$codigo = strtoupper(preg_replace('/[^A-Z0-9]/', '', $seg ?: ($_GET['c'] ?? '')));

if (!$codigo) redir(BASE_URL . '/ver/');

// Buscar estudio
$stmt = db()->prepare(
    'SELECT e.*, p.nombre, p.apellido, p.dni, p.fecha_nac, p.obra_social
     FROM estudios e
     JOIN pacientes p ON p.id = e.paciente_id
     WHERE e.codigo_acceso = ? AND e.activo = 1'
);
$stmt->execute([$codigo]);
$est = $stmt->fetch();

if (!$est) {
    $notFound = true;
} else {
    if ($est['vence_en'] && $est['vence_en'] < date('Y-m-d')) {
        $notFound = true; $vencido = true;
    }
}

if (empty($notFound)) {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
    db()->prepare('INSERT INTO accesos_log (estudio_id,ip,user_agent) VALUES (?,?,?)')
       ->execute([$est['id'], $ip, $ua]);

    $imgs = db()->prepare('SELECT * FROM imagenes WHERE estudio_id=? ORDER BY orden');
    $imgs->execute([$est['id']]);
    $imgs = $imgs->fetchAll();

    $infStmt = db()->prepare('SELECT cuerpo FROM informes WHERE estudio_id=?');
    $infStmt->execute([$est['id']]);
    $informe = $infStmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= empty($notFound) ? e($est['apellido'].', '.$est['nombre'].' · '.labelTipo($est['tipo'])) : 'Estudio no encontrado' ?> · ImagenMed</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<link rel="manifest" href="<?= BASE_URL ?>/ver/manifest.php">
<meta name="theme-color" content="#16181d">
<meta name="robots" content="noindex, nofollow">
<script>
(function () {
  var t = localStorage.getItem('theme') || 'light';
  document.documentElement.setAttribute('data-bs-theme', t);
})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root { --accent: #5b8def; --accent-dark: #3f6fd1; --brand: #16181d; --border-c: #e3e3e6; }
body { background: #f7f7f8; font-family: 'Inter', system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
.top-bar { background: var(--brand); color: #fff; padding: .7rem 1rem;
           display: flex; align-items: center; justify-content: space-between; }
.top-bar h1 { font-size: 1rem; font-weight: 500; margin: 0; letter-spacing: .01em; }
.top-bar a { color: rgba(255,255,255,.55); font-size: .8rem; text-decoration: none; }
.top-bar a:hover { color: #fff; }
.main { max-width: 1600px; margin: 0 auto; padding: 1.5rem 1.25rem 3rem; }
.visor-wrap { background: #0a0e1a; border-radius: 10px 10px 0 0; position: relative;
              display: flex; align-items: center; justify-content: center;
              min-height: 75vh; overflow: hidden; }
#pub-img, #pub-canvas { max-height: 88vh; max-width: 100%; transition: filter .15s, transform .15s; }
.visor-msg { color: #cbd5e1; font-size: .85rem; text-align: center; padding: 1rem; }
.dcm-thumb { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;
             color: #cbd5e1; font-size: .65rem; font-weight: 500; letter-spacing: .04em; }
.toolbar { position: absolute; top: 10px; left: 50%; transform: translateX(-50%);
           display: flex; gap: 4px; background: rgba(0,0,0,.55);
           padding: 5px 10px; border-radius: 999px; z-index: 5; }
.toolbar-tools { position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%);
           display: flex; gap: 4px; background: rgba(0,0,0,.55);
           padding: 5px 10px; border-radius: 999px; z-index: 5; flex-wrap: wrap;
           justify-content: center; max-width: 90%; }
.tbtn { width: 32px; height: 32px; border-radius: 50%; border: none; background: transparent;
        color: rgba(255,255,255,.75); font-size: 15px; cursor: pointer;
        display: flex; align-items: center; justify-content: center; transition: background .1s; }
.tbtn:hover { background: rgba(255,255,255,.18); color: #fff; }
.tbtn.active { background: var(--accent); color: #fff; }
#pub-overlay { position: absolute; top: 0; left: 0; pointer-events: none; }

/* Thumbnail strip */
.thumb-row { display: flex; gap: 8px; padding: .75rem 1rem; background: #fff;
             border: 1px solid var(--border-c); border-top: none; border-radius: 0 0 10px 10px;
             overflow-x: auto; align-items: flex-start; }
.thumb-item { display: flex; flex-direction: column; align-items: center; gap: 4px; flex-shrink: 0; }
.thumb { width: 72px; height: 72px; border-radius: 8px; overflow: hidden; cursor: pointer;
         border: 2px solid transparent; background: #0a0e1a;
         position: relative; transition: border-color .15s, box-shadow .15s; }
.thumb.sel { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(91,141,239,.25); }
.thumb img { width: 100%; height: 100%; object-fit: cover; pointer-events: none; }
.thumb-num { position: absolute; bottom: 2px; right: 4px; font-size: .58rem; font-weight: 700;
             color: rgba(255,255,255,.85); text-shadow: 0 1px 2px rgba(0,0,0,.8); pointer-events: none; }
.thumb-name { font-size: .6rem; color: #6b7280; text-align: center; max-width: 72px;
              overflow: hidden; text-overflow: ellipsis; white-space: nowrap; line-height: 1.2; }
.thumb-hint { font-size: .68rem; color: #9ca3af; white-space: nowrap; padding: 4px; align-self: center; }

.section-card { background: #fff; border: 1px solid var(--border-c); border-radius: 10px;
                padding: 1.1rem 1.25rem; margin-bottom: .9rem; }
.section-card h2 { font-size: .78rem; font-weight: 500; text-transform: uppercase;
                   letter-spacing: .06em; color: #8a8d98; margin-bottom: .6rem; }
.tipo-chip { display: inline-flex; align-items: center; gap: 5px; font-size: .78rem;
             font-weight: 500; padding: .3em .7em; border-radius: 4px; }
.chip-RX  { background:#e6f1fb;color:#0c447c; }
.chip-ECO { background:#e1f5ee;color:#085041; }
.chip-TAC { background:#faeeda;color:#633806; }
.chip-MAM { background:#fbeaf0;color:#72243e; }
.chip-RMN { background:#eeedfe;color:#3c3489; }
.chip-OTR { background:#f1efe8;color:#444441; }
.dl-btn { display: flex; align-items: center; justify-content: center; gap: 8px;
          background: var(--accent); color: #fff; border-radius: 8px; padding: .8rem;
          font-size: .9rem; font-weight: 500; text-decoration: none; }
.dl-btn:hover { background: var(--accent-dark); color: #fff; }
.theme-toggle { width: 32px; height: 32px; border-radius: 50%; border: 1px solid rgba(255,255,255,.2);
  background: rgba(255,255,255,.08); color: #fff; display: flex; align-items: center;
  justify-content: center; cursor: pointer; font-size: .95rem; }
.nav-counter { position: absolute; top: 10px; right: 10px; background: rgba(0,0,0,.55);
               color: rgba(255,255,255,.85); font-size: .72rem; font-weight: 600;
               padding: 3px 9px; border-radius: 999px; z-index: 5; pointer-events: none; }

/* Modo oscuro */
[data-bs-theme="dark"] body { background: #0f1115; }
[data-bs-theme="dark"] .section-card { background: #1a1c22; border-color: #2c2e35; }
[data-bs-theme="dark"] .section-card h2 { color: #8a8d98; }
[data-bs-theme="dark"] .thumb-row { background: #1a1c22; border-color: #2c2e35; }
[data-bs-theme="dark"] .thumb-name { color: #94a3b8; }
[data-bs-theme="dark"] .text-muted { color: #8a8d98 !important; }
[data-bs-theme="dark"] .border-top { border-color: #2c2e35 !important; }
[data-bs-theme="dark"] .px-3.py-2.bg-white { background: #1a1c22 !important; border-color: #2c2e35 !important; }
</style>
</head>
<body>
<div class="top-bar">
  <h1>ImagenMed</h1>
  <div class="d-flex align-items-center gap-3">
    <a href="<?= BASE_URL ?>/ver/">← Ingresar otro código</a>
    <button id="theme-toggle" class="theme-toggle" type="button" title="Cambiar tema">
      <i class="bi bi-moon-stars"></i>
    </button>
  </div>
</div>

<?php if (!empty($notFound)): ?>
<div class="main" style="max-width:460px;">
  <div class="section-card text-center py-5">
    <div style="font-size:2.5rem;margin-bottom:1rem;">🔍</div>
    <h2 style="font-size:1.1rem;color:var(--brand);text-transform:none;letter-spacing:0;">
      <?= !empty($vencido) ? 'El link de este estudio ha vencido' : 'Código no encontrado' ?>
    </h2>
    <p class="text-muted small mt-2">
      <?= !empty($vencido) ? 'Solicitá al centro una nueva versión del link.' : 'Verificá el código en tu comprobante de entrega.' ?>
    </p>
    <a href="<?= BASE_URL ?>/ver/" class="btn mt-3" style="background:var(--accent);color:#fff;">Reintentar</a>
  </div>
</div>

<?php else: ?>
<div class="main">

  <!-- Header del estudio -->
  <div class="section-card mb-3">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
      <div>
        <div class="fw-bold fs-5 mb-1"><?= e($est['apellido'] . ', ' . $est['nombre']) ?></div>
        <div class="text-muted small">
          DNI <?= e($est['dni']) ?>
          <?php if ($est['fecha_nac']): ?>· Nac. <?= fmtFecha($est['fecha_nac']) ?><?php endif; ?>
          <?php if ($est['obra_social']): ?>· <?= e($est['obra_social']) ?><?php endif; ?>
        </div>
        <div class="mt-2">
          <span class="tipo-chip chip-<?= e($est['tipo']) ?>"><?= e(labelTipo($est['tipo'])) ?></span>
          <?php if ($est['descripcion']): ?>
            <span class="text-muted small ms-2"><?= e($est['descripcion']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="text-muted small text-end">
        <div><?= fmtFecha($est['fecha_estudio']) ?></div>
        <?php if ($est['medico_der']): ?>
          <div>Dr. <?= e($est['medico_der']) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Visor -->
  <?php if ($imgs): ?>
  <div class="mb-3">
    <div class="visor-wrap" id="pub-stage">
      <!-- Toolbar superior -->
      <div class="toolbar">
        <button class="tbtn" onclick="tZoom(1.2)" title="Zoom +"><i class="bi bi-zoom-in"></i></button>
        <button class="tbtn" onclick="tZoom(0.8)" title="Zoom -"><i class="bi bi-zoom-out"></i></button>
        <button class="tbtn" onclick="tRot()" title="Rotar 90°"><i class="bi bi-arrow-clockwise"></i></button>
        <button class="tbtn" id="btn-inv" onclick="tInv()" title="Invertir colores"><i class="bi bi-sun"></i></button>
        <button class="tbtn" onclick="tReset()" title="Resetear vista"><i class="bi bi-arrow-counterclockwise"></i></button>
        <a class="tbtn" id="pub-dl" href="<?= e(urlImagen($imgs[0]['filename'])) ?>" download title="Descargar imagen actual"><i class="bi bi-download"></i></a>
      </div>

      <!-- Herramientas de medición (inferior) -->
      <div class="toolbar-tools">
        <button class="tbtn active" data-tool="pan" onclick="tTool('pan',this)" title="Mover"><i class="bi bi-arrows-move"></i></button>
        <button class="tbtn" data-tool="wl" onclick="tTool('wl',this)" title="Brillo/Contraste arrastrando"><i class="bi bi-sliders"></i></button>
        <button class="tbtn" data-tool="length" onclick="tTool('length',this)" title="Medir distancia"><i class="bi bi-rulers"></i></button>
        <button class="tbtn" data-tool="angle" onclick="tTool('angle',this)" title="Medir ángulo"><i class="bi bi-triangle"></i></button>
        <button class="tbtn" data-tool="calibrate" onclick="tTool('calibrate',this)" title="Calibrar escala"><i class="bi bi-arrow-left-right"></i></button>
        <button class="tbtn" data-tool="density" onclick="tTool('density',this)" title="Perfil de densidad"><i class="bi bi-activity"></i></button>
        <button class="tbtn" data-tool="rect" onclick="tTool('rect',this)" title="Región ROI"><i class="bi bi-bounding-box"></i></button>
        <button class="tbtn" data-tool="ellipse" onclick="tTool('ellipse',this)" title="Elipse ROI"><i class="bi bi-circle"></i></button>
        <button class="tbtn" data-tool="text" onclick="tTool('text',this)" title="Anotación de texto"><i class="bi bi-fonts"></i></button>
        <button class="tbtn" onclick="tClearAnotaciones()" title="Borrar anotaciones"><i class="bi bi-eraser"></i></button>
        <button class="tbtn" onclick="tExportPng()" title="Descargar con anotaciones"><i class="bi bi-image-fill"></i></button>
      </div>

      <!-- Contador de imagen (solo si hay más de una) -->
      <?php if (count($imgs) > 1): ?>
      <div class="nav-counter" id="nav-counter">1 / <?= count($imgs) ?></div>
      <?php endif; ?>

      <img id="pub-img" src="<?= e(urlImagen($imgs[0]['filename'])) ?>"
           alt="Imagen del estudio" draggable="false">
      <canvas id="pub-canvas" class="d-none"></canvas>
      <canvas id="pub-overlay"></canvas>
      <div id="pub-msg" class="visor-msg d-none"></div>
      <span id="pub-scale-badge" class="badge bg-secondary"
            style="position:absolute;top:10px;left:10px;z-index:5;cursor:pointer;"
            onclick="tClearCalib()" title="Calibración. Click para borrar.">Sin calibrar</span>
      <div id="tdicom-overlay" class="d-none"
           style="position:absolute;inset:0;pointer-events:none;z-index:4;font-size:11px;line-height:1.55;font-family:'Inter',system-ui,sans-serif;">
        <div id="tdoi-tl" style="position:absolute;top:10px;left:8px;color:rgba(255,255,255,.85);text-shadow:0 1px 3px rgba(0,0,0,.95);max-width:45%;"></div>
        <div id="tdoi-tr" style="position:absolute;top:10px;right:8px;color:rgba(255,255,255,.85);text-shadow:0 1px 3px rgba(0,0,0,.95);text-align:right;max-width:45%;"></div>
        <div id="tdoi-bl" style="position:absolute;bottom:10px;left:8px;color:rgba(255,255,255,.75);text-shadow:0 1px 3px rgba(0,0,0,.95);"></div>
        <div id="tdoi-br" style="position:absolute;bottom:10px;right:8px;color:rgba(255,255,255,.75);text-shadow:0 1px 3px rgba(0,0,0,.95);text-align:right;"></div>
      </div>
    </div>

    <!-- Controles de brillo/contraste -->
    <div class="px-3 py-2 border border-top-0 d-flex flex-wrap gap-3 align-items-center bg-white" id="visor-controls"
         style="border-color:var(--border-c)!important;">
      <div class="d-flex align-items-center gap-2">
        <label class="small text-muted mb-0" id="lb-br-name">Brillo</label>
        <input type="range" min="20" max="200" value="100" id="sl-br" oninput="tFiltro()" style="width:90px;">
        <span id="lb-br" class="small">100%</span>
      </div>
      <div class="d-flex align-items-center gap-2">
        <label class="small text-muted mb-0" id="lb-ct-name">Contraste</label>
        <input type="range" min="20" max="300" value="100" id="sl-ct" oninput="tFiltro()" style="width:90px;">
        <span id="lb-ct" class="small">100%</span>
      </div>
      <div class="d-flex align-items-center gap-2 d-none" id="frame-ctrl">
        <label class="small text-muted mb-0">Corte</label>
        <input type="range" min="0" max="0" value="0" id="sl-frame" oninput="tFrame()" style="width:90px;">
        <span id="lb-frame" class="small">1/1</span>
      </div>
    </div>

    <!-- Presets de ventana DICOM -->
    <div id="tpreset-bar" class="d-none px-3 py-2 border border-top-0 d-flex align-items-center gap-2 flex-wrap bg-white"
         style="border-color:var(--border-c)!important;">
      <span class="small text-muted" style="white-space:nowrap;">Ventana:</span>
      <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="tApplyPreset(null,null)">Auto</button>
      <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="tApplyPreset(40,80)">Cerebro</button>
      <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="tApplyPreset(50,350)">Mediastino</button>
      <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="tApplyPreset(60,400)">Abdomen</button>
      <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="tApplyPreset(-600,1500)">Pulmón</button>
      <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="tApplyPreset(700,2000)">Hueso</button>
      <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="tApplyPreset(300,600)">Angio</button>
    </div>

    <!-- Perfil de densidad -->
    <div id="tdensity-panel" class="d-none border border-top-0 px-3 py-2 bg-white"
         style="border-color:var(--border-c)!important;border-radius:0 0 10px 10px;">
      <div class="d-flex justify-content-between align-items-center mb-1">
        <span class="small fw-semibold">Perfil de densidad</span>
        <button type="button" class="btn-close" style="font-size:.6rem;"
                onclick="document.getElementById('tdensity-panel').classList.add('d-none')"></button>
      </div>
      <canvas id="tdensity-canvas" height="80"
              style="width:100%;border-radius:4px;background:#16181d;display:block;"></canvas>
      <div id="tdensity-info" class="text-muted mt-1" style="font-size:.75rem;"></div>
    </div>

    <!-- Thumbnails con nombre -->
    <?php if (count($imgs) > 1): ?>
    <div class="thumb-row" id="thumb-row">
      <span class="thumb-hint"><i class="bi bi-mouse2"></i> Rueda para navegar</span>
      <?php foreach ($imgs as $i => $img):
        $url = e(urlImagen($img['filename']));
        $isDcm = strtolower(pathinfo($img['filename'], PATHINFO_EXTENSION)) === 'dcm';
        $nombre = $img['nombre'] ?? '';
        $nombreCorto = mb_strlen($nombre) > 18 ? mb_substr($nombre, 0, 16) . '…' : $nombre;
      ?>
        <div class="thumb-item">
          <div class="thumb <?= $i===0?'sel':'' ?>"
               data-url="<?= $url ?>"
               onclick="tCargar('<?= $url ?>',this)"
               title="<?= e($nombre ?: 'Imagen '.($i+1)) ?>">
            <?php if ($isDcm): ?>
              <div class="dcm-thumb"><i class="bi bi-file-medical fs-5 d-block"></i>DCM</div>
            <?php else: ?>
              <img src="<?= $url ?>" alt="Imagen <?= $i+1 ?>">
            <?php endif; ?>
            <span class="thumb-num"><?= $i+1 ?></span>
          </div>
          <?php if ($nombre): ?>
            <div class="thumb-name" title="<?= e($nombre) ?>"><?= e($nombreCorto) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Informe -->
  <?php if ($informe): ?>
  <div class="section-card mb-3">
    <h2>Informe del radiólogo</h2>
    <p class="small mb-0" style="line-height:1.75;"><?= nl2br(e($informe)) ?></p>
    <a href="<?= BASE_URL ?>/ver/imprimir.php?c=<?= e($codigo) ?>" target="_blank"
       class="dl-btn mt-3" style="font-size:13px;">
      <i class="bi bi-printer"></i>
      Imprimir / descargar informe
    </a>
  </div>
  <?php endif; ?>

  <!-- Descarga -->
  <?php if ($imgs): ?>
  <a href="<?= BASE_URL ?>/ver/descargar.php?c=<?= e($codigo) ?>" class="dl-btn">
    <i class="bi bi-download"></i>
    Descargar imágenes
    <span class="ms-auto opacity-75 small"><?= count($imgs) ?> archivo<?= count($imgs)>1?'s':'' ?></span>
  </a>
  <?php endif; ?>

</div>
<?php endif; ?>

<?php if ($imgs): ?>
<script>if (typeof window.require === 'undefined') window.require = function () {};</script>
<script src="<?= BASE_URL ?>/assets/js/daikon.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/dicom-viewer.js"></script>
<script src="<?= BASE_URL ?>/assets/js/image-tools.js"></script>
<?php endif; ?>
<script>
var ts=1, tr=0, ti=false, dcmInfo=null, dcmFrame=0, panX=0, panY=0, currentTool='pan';
var itools=null;
var currentSrc='';
var tImgList = [];
var tImgIdx  = 0;

function tCalibBadge(mm){
  var b=document.getElementById('pub-scale-badge');
  if(!b) return;
  if (mm) {
    b.textContent='Escala: '+mm.toFixed(4)+' mm/px';
    b.classList.remove('bg-secondary'); b.classList.add('bg-success');
  } else {
    b.textContent='Sin calibrar';
    b.classList.remove('bg-success'); b.classList.add('bg-secondary');
  }
}

function tClearCalib(){
  if (itools && itools.getCalibration()) {
    if (confirm('¿Borrar la calibración de escala para esta imagen?')) itools.clearCalibration();
  } else {
    alert('Esta imagen todavía no tiene una escala calibrada. Usá la herramienta "Calibrar" (↔) para definirla.');
  }
}

function tFiltro(){
  var v1=document.getElementById('sl-br').value, v2=document.getElementById('sl-ct').value;
  if (dcmInfo) {
    document.getElementById('lb-br').textContent=v1;
    document.getElementById('lb-ct').textContent=v2;
  } else {
    document.getElementById('lb-br').textContent=v1+'%';
    document.getElementById('lb-ct').textContent=v2+'%';
  }
  applyT(v1,v2);
}

function applyT(v1,v2){
  var img=document.getElementById('pub-img');
  var canvas=document.getElementById('pub-canvas');
  if(!img)return;
  var transform='translate('+panX+'px,'+panY+'px) scale('+ts+') rotate('+tr+'deg)';
  if (dcmInfo) {
    DicomViewer.draw(canvas, dcmInfo, Number(v1), Math.max(1,Number(v2)), ti, dcmFrame);
    canvas.style.filter=''; canvas.style.transform=transform;
  } else {
    var f='brightness('+v1/100+') contrast('+v2/100+')';
    if(ti)f+=' invert(1)';
    img.style.filter=f; img.style.transform=transform;
  }
  if (itools) itools.sync();
  tUpdateDicomOverlayWL();
}

function tFiltroValores(){
  applyT(document.getElementById('sl-br').value, document.getElementById('sl-ct').value);
}

function tZoom(f){ ts=Math.max(.3,Math.min(5,ts*f)); tFiltroValores(); }
function tRot(){ tr=(tr+90)%360; tFiltroValores(); }
function tInv(){
  ti=!ti;
  document.getElementById('btn-inv').classList.toggle('active',ti);
  tFiltroValores();
}

function tTool(name, btn){
  currentTool=name;
  document.querySelectorAll('.toolbar-tools .tbtn').forEach(function(b){ b.classList.remove('active'); });
  if (btn) btn.classList.add('active');
  if (itools) itools.setTool(name);
}

function tClearAnotaciones(){ if (itools) itools.clear(); }

function tUpdateDicomOverlay(){
  var el=document.getElementById('tdicom-overlay');
  var bar=document.getElementById('tpreset-bar');
  if (!el) return;
  if (!dcmInfo) {
    el.classList.add('d-none');
    if (bar) { bar.classList.add('d-none'); bar.classList.remove('d-flex'); }
    return;
  }
  el.classList.remove('d-none');
  if (bar) { bar.classList.remove('d-none'); bar.classList.add('d-flex'); }
  var img=dcmInfo.image;
  var name=img.getPatientName?String(img.getPatientName()||'').replace(/\^/g,' ').trim():'';
  var pid=img.getPatientID?String(img.getPatientID()||'').trim():'';
  document.getElementById('tdoi-tl').innerHTML=(name?'<strong>'+name+'</strong><br>':'')+(pid?'ID: '+pid:'');
  var mod=img.getModality?(img.getModality()||''):'';
  var raw=img.getStudyDate?(img.getStudyDate()||''):'';
  var fmtD=raw.length===8?raw.substr(6,2)+'/'+raw.substr(4,2)+'/'+raw.substr(0,4):raw;
  var descTag=img.getTag?(img.getTag(8,4158)||img.getTag(8,4144)):null;
  var desc=descTag&&descTag.value?String(Array.isArray(descTag.value)?descTag.value[0]:descTag.value).trim():'';
  document.getElementById('tdoi-tr').innerHTML=(mod?'<strong>'+mod+'</strong>':'')+(fmtD?(mod?' · ':'')+fmtD:'')+(desc?'<br>'+desc:'');
  tUpdateDicomOverlayWL();
  var imgNum=img.getImageNumber?img.getImageNumber():null;
  var sliceLoc=img.getSliceLocation?img.getSliceLocation():null;
  document.getElementById('tdoi-br').innerHTML=(imgNum?'Im: '+imgNum:'')+(sliceLoc?(imgNum?'<br>':'')+'Loc: '+Number(sliceLoc).toFixed(1)+' mm':'');
}

function tUpdateDicomOverlayWL(){
  var el=document.getElementById('tdoi-bl');
  if (!el||!dcmInfo) return;
  el.textContent='WC: '+Math.round(Number(document.getElementById('sl-br').value))+' / WW: '+Math.round(Number(document.getElementById('sl-ct').value));
}

function tApplyPreset(wc,ww){
  if (!dcmInfo) return;
  if (wc===null) { wc=dcmInfo.defaultWC; ww=dcmInfo.defaultWW; }
  document.getElementById('sl-br').value=Math.round(wc);
  document.getElementById('sl-ct').value=Math.round(Math.max(1,ww));
  document.getElementById('lb-br').textContent=Math.round(wc);
  document.getElementById('lb-ct').textContent=Math.round(ww);
  applyT(wc,ww);
}

function tExportPng(){
  if (!itools) return;
  itools.exportPng('imagen-anotada-<?= e($est['codigo_acceso']) ?>-'+(dcmInfo?(dcmFrame+1):1)+'.png');
}

function tFrame(){
  dcmFrame=Number(document.getElementById('sl-frame').value);
  document.getElementById('lb-frame').textContent=(dcmFrame+1)+'/'+dcmInfo.numFrames;
  tFiltroValores();
}

function tReset(){
  ts=1; tr=0; ti=false; dcmFrame=0; panX=0; panY=0;
  document.getElementById('btn-inv').classList.remove('active');
  var frameCtrl=document.getElementById('frame-ctrl');
  if (dcmInfo) {
    document.getElementById('sl-br').min=dcmInfo.min;
    document.getElementById('sl-br').max=dcmInfo.max;
    document.getElementById('sl-br').value=Math.round(dcmInfo.defaultWC);
    document.getElementById('sl-ct').min=1;
    document.getElementById('sl-ct').max=Math.round((dcmInfo.max-dcmInfo.min)*2)||1;
    document.getElementById('sl-ct').value=Math.round(dcmInfo.defaultWW);
    document.getElementById('lb-br-name').textContent='Centro';
    document.getElementById('lb-ct-name').textContent='Ancho';
    document.getElementById('lb-br').textContent=Math.round(dcmInfo.defaultWC);
    document.getElementById('lb-ct').textContent=Math.round(dcmInfo.defaultWW);
    if (dcmInfo.numFrames>1) {
      frameCtrl.classList.remove('d-none');
      document.getElementById('sl-frame').max=dcmInfo.numFrames-1;
      document.getElementById('sl-frame').value=0;
      document.getElementById('lb-frame').textContent='1/'+dcmInfo.numFrames;
    } else { frameCtrl.classList.add('d-none'); }
  } else {
    document.getElementById('sl-br').min=20; document.getElementById('sl-br').max=200;
    document.getElementById('sl-br').value=100;
    document.getElementById('sl-ct').min=20; document.getElementById('sl-ct').max=300;
    document.getElementById('sl-ct').value=100;
    document.getElementById('lb-br-name').textContent='Brillo';
    document.getElementById('lb-ct-name').textContent='Contraste';
    document.getElementById('lb-br').textContent='100%';
    document.getElementById('lb-ct').textContent='100%';
    frameCtrl.classList.add('d-none');
  }
  tFiltroValores();
}

function tCargar(src, el){
  var _idx=tImgList.findIndex(function(x){ return x.url===src; });
  if (_idx>=0) tImgIdx=_idx;
  currentSrc=src;
  var img=document.getElementById('pub-img');
  var canvas=document.getElementById('pub-canvas');
  var msg=document.getElementById('pub-msg');
  var dl=document.getElementById('pub-dl');
  var counter=document.getElementById('nav-counter');
  if (dl) dl.href=src;
  if (counter) counter.textContent=(tImgIdx+1)+' / '+tImgList.length;
  if (el) {
    document.querySelectorAll('.thumb').forEach(function(t){ t.classList.remove('sel'); });
    el.classList.add('sel');
    el.scrollIntoView({behavior:'smooth',block:'nearest',inline:'center'});
  }
  msg.classList.add('d-none');
  img.style.transform=''; img.style.filter='';
  canvas.style.transform=''; canvas.style.filter='';
  if (typeof DicomViewer!=='undefined' && DicomViewer.isDicom(src)) {
    img.classList.add('d-none'); canvas.classList.add('d-none');
    msg.classList.remove('d-none'); msg.textContent='Cargando imagen DICOM...';
    DicomViewer.load(src).then(function(info){
      dcmInfo=info;
      msg.classList.add('d-none'); canvas.classList.remove('d-none');
      tReset(); tUpdateDicomOverlay();
    }).catch(function(){
      dcmInfo=null; tUpdateDicomOverlay();
      canvas.classList.add('d-none'); msg.classList.remove('d-none');
      msg.textContent='No se pudo previsualizar este archivo DICOM. Podés descargarlo con el botón superior.';
    });
  } else {
    dcmInfo=null; tUpdateDicomOverlay();
    canvas.classList.add('d-none'); img.classList.remove('d-none');
    img.onload=function(){ if (itools) itools.sync(); };
    img.src=src;
    tReset();
  }
}

// Herramientas de medición/anotación
(function(){
  var stage=document.getElementById('pub-stage');
  var overlay=document.getElementById('pub-overlay');
  if (!stage||!overlay||typeof ImageTools==='undefined') return;
  itools=ImageTools.create({
    overlay: overlay,
    getActive: function(){
      if (dcmInfo) return document.getElementById('pub-canvas');
      // Para imágenes normales devolvemos pub-img; sync() usa naturalWidth/offsetWidth
      return document.getElementById('pub-img');
    },
    getState: function(){ return { tr: tr }; },
    getPixelSpacing: function(){ return dcmInfo?ImageTools.pixelSpacingFromImage(dcmInfo.image):null; },
    getCalibKey: function(){ return currentSrc; },
    onCalibrationChange: tCalibBadge,
    onDensityLine: function(p0,p1){ tDensityProfile(p0,p1); },
    onWheel: function(dir){
      if (dcmInfo && dcmInfo.numFrames>1) {
        var slider=document.getElementById('sl-frame');
        slider.value=Math.min(Number(slider.max),Math.max(0,dcmFrame+dir));
        tFrame();
      } else if (tImgList.length>1) {
        tImgIdx=Math.min(tImgList.length-1,Math.max(0,tImgIdx+dir));
        var item=tImgList[tImgIdx];
        tCargar(item.url,item.el);
      } else {
        tZoom(dir>0?0.9:1.1);
      }
    }
  });
  itools.setTool('pan');

  var dragging=false,lastX=0,lastY=0;
  stage.addEventListener('pointerdown',function(e){
    if (currentTool!=='pan'&&currentTool!=='wl') return;
    if (e.target.closest('button,a')) return;
    dragging=true; lastX=e.clientX; lastY=e.clientY;
    stage.setPointerCapture(e.pointerId);
    stage.style.cursor=currentTool==='pan'?'grabbing':'ns-resize';
  });
  stage.addEventListener('pointermove',function(e){
    if (!dragging) return;
    var dx=e.clientX-lastX, dy=e.clientY-lastY;
    lastX=e.clientX; lastY=e.clientY;
    if (currentTool==='pan') { panX+=dx; panY+=dy; tFiltroValores(); }
    else if (currentTool==='wl') {
      var br=document.getElementById('sl-br'), ct=document.getElementById('sl-ct');
      if (dcmInfo) {
        var range=(dcmInfo.max-dcmInfo.min)||1;
        br.value=Math.round(Math.min(Number(br.max),Math.max(Number(br.min),Number(br.value)-dy*range/256)));
        ct.value=Math.round(Math.min(Number(ct.max),Math.max(Number(ct.min),Number(ct.value)+dx*range/128)));
      } else {
        br.value=Math.min(Number(br.max),Math.max(Number(br.min),Number(br.value)-dy));
        ct.value=Math.min(Number(ct.max),Math.max(Number(ct.min),Number(ct.value)+dx));
      }
      tFiltro();
    }
  });
  stage.addEventListener('pointerup',function(){ dragging=false; stage.style.cursor=''; });

  // Garantizar que sync() corra con dimensiones reales una vez cargada la página
  window.addEventListener('load', function(){ if (itools) itools.sync(); });

  // Rueda siempre navega entre imágenes (o cambia corte DICOM / zoom)
  stage.addEventListener('wheel',function(e){
    e.preventDefault();
    var dir=e.deltaY>0?1:-1;
    if (dcmInfo && dcmInfo.numFrames>1) {
      var slider=document.getElementById('sl-frame');
      slider.value=Math.min(Number(slider.max),Math.max(0,dcmFrame+dir));
      tFrame();
    } else if (tImgList.length>1) {
      tImgIdx=Math.min(tImgList.length-1,Math.max(0,tImgIdx+dir));
      var item=tImgList[tImgIdx];
      tCargar(item.url,item.el);
    } else {
      tZoom(dir>0?0.9:1.1);
    }
  },{passive:false});

  window.addEventListener('resize',function(){ if (itools) itools.sync(); });
})();

// Construir lista de thumbnails
document.querySelectorAll('.thumb').forEach(function(el){
  tImgList.push({ url: el.getAttribute('data-url'), el: el });
});

// Carga inicial
(function(){
  var img=document.getElementById('pub-img');
  if (img) tCargar(img.getAttribute('src'),document.querySelector('.thumb'));
})();

// Pinch-to-zoom (mobile)
var initDist=0;
document.addEventListener('touchstart',function(e){
  if(e.touches.length===2)
    initDist=Math.hypot(e.touches[0].clientX-e.touches[1].clientX,e.touches[0].clientY-e.touches[1].clientY);
});
document.addEventListener('touchmove',function(e){
  if(e.touches.length===2){
    e.preventDefault();
    var d=Math.hypot(e.touches[0].clientX-e.touches[1].clientX,e.touches[0].clientY-e.touches[1].clientY);
    if(initDist>0) tZoom(d/initDist);
    initDist=d;
  }
},{passive:false});

// Toggle tema
(function(){
  var btn=document.getElementById('theme-toggle');
  if (!btn) return;
  var icon=btn.querySelector('i');
  function actualizarIcono(){
    icon.className=document.documentElement.getAttribute('data-bs-theme')==='dark'?'bi bi-sun':'bi bi-moon-stars';
  }
  actualizarIcono();
  btn.addEventListener('click',function(){
    var nuevo=document.documentElement.getAttribute('data-bs-theme')==='dark'?'light':'dark';
    document.documentElement.setAttribute('data-bs-theme',nuevo);
    localStorage.setItem('theme',nuevo);
    actualizarIcono();
  });
})();

// Perfil de densidad
function tDensityProfile(p0,p1){
  var steps=Math.ceil(Math.hypot(p1.x-p0.x,p1.y-p0.y));
  if (steps<2) return;
  var srcCanvas;
  if (dcmInfo) {
    srcCanvas=document.getElementById('pub-canvas');
    if (!srcCanvas||srcCanvas.classList.contains('d-none')) return;
  } else {
    // Imagen normal: dibujar pub-img en canvas temporal para leer píxeles
    var img=document.getElementById('pub-img');
    if (!img||!img.naturalWidth) return;
    srcCanvas=document.createElement('canvas');
    srcCanvas.width=img.naturalWidth; srcCanvas.height=img.naturalHeight;
    var tctx=srcCanvas.getContext('2d');
    // aplicar brillo/contraste al dibujar
    var br=Number(document.getElementById('sl-br').value)/100;
    var ct=Number(document.getElementById('sl-ct').value)/100;
    var f='brightness('+br+') contrast('+ct+')';
    if (ti) f+=' invert(1)';
    tctx.filter=f;
    tctx.drawImage(img,0,0);
  }
  var ctx=srcCanvas.getContext('2d');
  var values=[], scW=srcCanvas.width, scH=srcCanvas.height;
  for (var i=0;i<=steps;i++){
    var t=i/steps;
    var px=Math.round(p0.x+(p1.x-p0.x)*t);
    var py=Math.round(p0.y+(p1.y-p0.y)*t);
    if (px<0||py<0||px>=scW||py>=scH) continue;
    var pixel=ctx.getImageData(px,py,1,1).data;
    var val=ti?255-pixel[0]:pixel[0];
    if (dcmInfo){
      var wc=Number(document.getElementById('sl-br').value);
      var ww=Math.max(1,Number(document.getElementById('sl-ct').value));
      val=Math.round((wc-ww/2)+(val/255)*ww);
    }
    values.push(val);
  }
  if (!values.length) return;
  var panel=document.getElementById('tdensity-panel');
  panel.classList.remove('d-none');
  var dc=document.getElementById('tdensity-canvas');
  dc.width=dc.offsetWidth||400;
  var dctx=dc.getContext('2d'), dw=dc.width, dh=dc.height;
  dctx.clearRect(0,0,dw,dh);
  dctx.fillStyle='#16181d'; dctx.fillRect(0,0,dw,dh);
  var minV=Math.min.apply(null,values), maxV=Math.max.apply(null,values);
  var rangeV=(maxV-minV)||1, pad=8;
  dctx.strokeStyle='rgba(255,255,255,.08)'; dctx.lineWidth=1;
  for (var g=0;g<=4;g++){
    var gy=pad+((4-g)/4)*(dh-pad*2);
    dctx.beginPath(); dctx.moveTo(pad,gy); dctx.lineTo(dw-pad,gy); dctx.stroke();
    dctx.fillStyle='rgba(255,255,255,.35)'; dctx.font='9px sans-serif';
    dctx.fillText(Math.round(minV+(g/4)*rangeV),2,gy+3);
  }
  dctx.beginPath(); dctx.strokeStyle='#4ade80'; dctx.lineWidth=1.5;
  values.forEach(function(v,i){
    var x=pad+(i/(values.length-1))*(dw-pad*2);
    var y=pad+(1-(v-minV)/rangeV)*(dh-pad*2);
    i===0?dctx.moveTo(x,y):dctx.lineTo(x,y);
  });
  dctx.stroke();
  var unit=dcmInfo?' HU':'';
  document.getElementById('tdensity-info').textContent=
    'Min: '+Math.round(minV)+unit+'  Máx: '+Math.round(maxV)+unit+
    '  Prom: '+Math.round(values.reduce(function(a,b){return a+b;},0)/values.length)+unit+
    '  '+values.length+' muestras';
}
</script>
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('<?= BASE_URL ?>/ver/sw.js', { scope: '<?= BASE_URL ?>/ver/' })
    .catch(function() {});
}
</script>
</body>
</html>
