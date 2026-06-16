<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

$token = preg_replace('/[^a-f0-9]/', '', $_GET['t'] ?? '');
if (!$token) redir(BASE_URL . '/ver/');

$stmt = db()->prepare(
    'SELECT c.vence_en, c.descripcion,
            e.id AS estudio_id, e.tipo, e.descripcion AS est_descripcion,
            e.medico_der, e.fecha_estudio, e.codigo_acceso,
            p.nombre, p.apellido, p.dni, p.fecha_nac, p.obra_social
     FROM compartidos c
     JOIN estudios e ON e.id = c.estudio_id
     JOIN pacientes p ON p.id = e.paciente_id
     WHERE c.token = ? AND c.vence_en > NOW()'
);
$stmt->execute([$token]);
$row = $stmt->fetch();

$notFound = !$row;
if (!$notFound) {
    $imgs = db()->prepare('SELECT * FROM imagenes WHERE estudio_id=? ORDER BY orden');
    $imgs->execute([$row['estudio_id']]);
    $imgs = $imgs->fetchAll();

    $infStmt = db()->prepare(
        'SELECT i.cuerpo, i.firmado_en FROM informes i WHERE i.estudio_id=?'
    );
    $infStmt->execute([$row['estudio_id']]);
    $inf = $infStmt->fetch();
    // Solo mostrar informe si está firmado
    $informe = ($inf && $inf['firmado_en']) ? $inf['cuerpo'] : null;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $notFound ? 'Estudio no disponible' : e($row['apellido'].', '.$row['nombre'].' · '.labelTipo($row['tipo'])) ?> · ImagenMed</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<meta name="robots" content="noindex, nofollow">
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
.main { max-width: 1600px; margin: 0 auto; padding: 1.5rem 1.25rem 3rem; }
.visor-wrap { background: #0a0e1a; border-radius: 10px; position: relative;
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
.thumb-row { display: flex; gap: 6px; padding: .6rem 1rem; background: #fff;
             border-top: 1px solid var(--border-c); overflow-x: auto; }
.thumb { width: 54px; height: 54px; border-radius: 6px; flex-shrink: 0; overflow: hidden;
         cursor: pointer; border: 2px solid transparent; background: #0a0e1a; }
.thumb.sel { border-color: var(--accent); }
.thumb img { width: 100%; height: 100%; object-fit: cover; }
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
</style>
</head>
<body>
<div class="top-bar">
  <h1>ImagenMed</h1>
</div>

<!-- Banner de acceso compartido -->
<?php if (!$notFound): ?>
<div style="background:#fff3cd;color:#856404;padding:8px 16px;font-size:13px;text-align:center;">
  Estudio compartido &middot; Solo lectura &middot; Acceso válido hasta <?= fmtFecha($row['vence_en']) ?>
  <?php if ($row['descripcion']): ?>&middot; <?= e($row['descripcion']) ?><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($notFound): ?>
<div class="main" style="max-width:460px;">
  <div class="section-card text-center py-5">
    <div style="font-size:2.5rem;margin-bottom:1rem;">🔒</div>
    <h2 style="font-size:1.1rem;color:var(--brand);text-transform:none;letter-spacing:0;">
      Link no disponible o vencido
    </h2>
    <p class="text-muted small mt-2">
      Este link de acceso compartido no es válido o ha expirado. Solicitá al médico un nuevo link.
    </p>
  </div>
</div>

<?php else: ?>
<div class="main">

  <!-- Header del estudio -->
  <div class="section-card mb-3">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
      <div>
        <div class="fw-bold fs-5 mb-1"><?= e($row['apellido'] . ', ' . $row['nombre']) ?></div>
        <div class="text-muted small">
          DNI <?= e($row['dni']) ?>
          <?php if ($row['fecha_nac']): ?>· Nac. <?= fmtFecha($row['fecha_nac']) ?><?php endif; ?>
          <?php if ($row['obra_social']): ?>· <?= e($row['obra_social']) ?><?php endif; ?>
        </div>
        <div class="mt-2">
          <span class="tipo-chip chip-<?= e($row['tipo']) ?>"><?= e(labelTipo($row['tipo'])) ?></span>
          <?php if ($row['est_descripcion']): ?>
            <span class="text-muted small ms-2"><?= e($row['est_descripcion']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="text-muted small text-end">
        <div><?= fmtFecha($row['fecha_estudio']) ?></div>
        <?php if ($row['medico_der']): ?>
          <div>Dr. <?= e($row['medico_der']) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Visor de imágenes -->
  <?php if ($imgs): ?>
  <div class="section-card p-0 overflow-hidden mb-3">
    <div class="visor-wrap" id="pub-stage">
      <div class="toolbar">
        <button class="tbtn" onclick="tZoom(1.2)" title="Zoom +"><i class="bi bi-zoom-in"></i></button>
        <button class="tbtn" onclick="tZoom(0.8)" title="Zoom -"><i class="bi bi-zoom-out"></i></button>
        <button class="tbtn" onclick="tRot()" title="Rotar"><i class="bi bi-arrow-clockwise"></i></button>
        <button class="tbtn" onclick="tInv()" title="Invertir"><i class="bi bi-sun"></i></button>
        <button class="tbtn" onclick="tReset()" title="Resetear"><i class="bi bi-arrow-counterclockwise"></i></button>
      </div>
      <img id="pub-img" src="<?= e(urlImagen($imgs[0]['filename'])) ?>"
           alt="Imagen del estudio" draggable="false">
      <canvas id="pub-canvas" class="d-none"></canvas>
      <canvas id="pub-overlay"></canvas>
      <div id="pub-msg" class="visor-msg d-none"></div>
      <span id="pub-scale-badge" class="badge bg-secondary" style="position:absolute;top:10px;left:10px;z-index:5;">Sin calibrar</span>
      <div id="tdicom-overlay" class="d-none" style="position:absolute;inset:0;pointer-events:none;z-index:4;font-size:11px;line-height:1.55;font-family:'Inter',system-ui,sans-serif;">
        <div id="tdoi-tl" style="position:absolute;top:34px;left:8px;color:rgba(255,255,255,.85);text-shadow:0 1px 3px rgba(0,0,0,.95);max-width:45%;"></div>
        <div id="tdoi-tr" style="position:absolute;top:34px;right:8px;color:rgba(255,255,255,.85);text-shadow:0 1px 3px rgba(0,0,0,.95);text-align:right;max-width:45%;"></div>
        <div id="tdoi-bl" style="position:absolute;bottom:52px;left:8px;color:rgba(255,255,255,.75);text-shadow:0 1px 3px rgba(0,0,0,.95);"></div>
        <div id="tdoi-br" style="position:absolute;bottom:52px;right:8px;color:rgba(255,255,255,.75);text-shadow:0 1px 3px rgba(0,0,0,.95);text-align:right;"></div>
      </div>
      <div class="toolbar-tools">
        <button class="tbtn active" data-tool="pan" onclick="tTool('pan',this)" title="Mover"><i class="bi bi-arrows-move"></i></button>
        <button class="tbtn" data-tool="wl" onclick="tTool('wl',this)" title="Brillo/Contraste"><i class="bi bi-sliders"></i></button>
        <button class="tbtn" data-tool="length" onclick="tTool('length',this)" title="Medir distancia"><i class="bi bi-rulers"></i></button>
        <button class="tbtn" data-tool="angle" onclick="tTool('angle',this)" title="Medir ángulo"><i class="bi bi-triangle"></i></button>
        <button class="tbtn" data-tool="calibrate" onclick="tTool('calibrate',this)" title="Calibrar escala"><i class="bi bi-arrow-left-right"></i></button>
        <button class="tbtn" data-tool="rect" onclick="tTool('rect',this)" title="Región (ROI)"><i class="bi bi-bounding-box"></i></button>
        <button class="tbtn" data-tool="ellipse" onclick="tTool('ellipse',this)" title="Elipse (ROI oval)"><i class="bi bi-circle"></i></button>
        <button class="tbtn" onclick="tClearAnotaciones()" title="Borrar anotaciones"><i class="bi bi-eraser"></i></button>
        <button class="tbtn" onclick="tExportPng()" title="Descargar imagen con anotaciones"><i class="bi bi-image-fill"></i></button>
      </div>
    </div>
    <div class="px-3 py-2 border-top d-flex flex-wrap gap-3 align-items-center bg-white">
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
    <div id="tpreset-bar" class="d-none px-3 py-2 border-top d-flex align-items-center gap-2 flex-wrap bg-white">
      <span class="small text-muted" style="white-space:nowrap;">Ventana:</span>
      <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="tApplyPreset(null,null)">Auto</button>
      <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="tApplyPreset(40,80)">Cerebro</button>
      <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="tApplyPreset(50,350)">Mediastino</button>
      <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="tApplyPreset(60,400)">Abdomen</button>
      <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="tApplyPreset(-600,1500)">Pulmón</button>
      <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="tApplyPreset(700,2000)">Hueso</button>
      <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="tApplyPreset(300,600)">Angio</button>
    </div>
    <?php if (count($imgs) > 1): ?>
    <div class="thumb-row">
      <?php foreach ($imgs as $i => $img): ?>
        <div class="thumb <?= $i===0?'sel':'' ?>"
             onclick="tCargar('<?= e(urlImagen($img['filename'])) ?>',this)"
             title="Imagen <?= $i+1 ?>">
          <?php if (strtolower(pathinfo($img['filename'], PATHINFO_EXTENSION)) === 'dcm'): ?>
            <div class="dcm-thumb"><i class="bi bi-file-medical fs-5 d-block"></i>DCM</div>
          <?php else: ?>
            <img src="<?= e(urlImagen($img['filename'])) ?>" alt="Imagen <?= $i+1 ?>">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Informe (solo si está firmado) -->
  <?php if ($informe): ?>
  <div class="section-card mb-3">
    <h2>Informe del radiólogo</h2>
    <p class="small mb-0" style="line-height:1.75;"><?= nl2br(e($informe)) ?></p>
  </div>
  <?php endif; ?>

</div>
<?php endif; ?>

<?php if (!$notFound && $imgs): ?>
<script>if (typeof window.require === 'undefined') window.require = function () {};</script>
<script src="<?= BASE_URL ?>/assets/js/daikon.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/dicom-viewer.js"></script>
<script src="<?= BASE_URL ?>/assets/js/image-tools.js"></script>
<script>
var ts=1,tr=0,ti=false,dcmInfo=null,dcmFrame=0,panX=0,panY=0,currentTool='pan';
var itools=null, currentSrc='';
var tImgList=[], tImgIdx=0;

function tCalibBadge(mm){
  var b=document.getElementById('pub-scale-badge');
  if(!b)return;
  if(mm){ b.textContent='Escala: '+mm.toFixed(4)+' mm/px'; b.classList.remove('bg-secondary'); b.classList.add('bg-success'); }
  else { b.textContent='Sin calibrar'; b.classList.remove('bg-success'); b.classList.add('bg-secondary'); }
}
function tFiltro(){
  var v1=document.getElementById('sl-br').value, v2=document.getElementById('sl-ct').value;
  if(dcmInfo){ document.getElementById('lb-br').textContent=v1; document.getElementById('lb-ct').textContent=v2; }
  else{ document.getElementById('lb-br').textContent=v1+'%'; document.getElementById('lb-ct').textContent=v2+'%'; }
  applyT(v1,v2);
}
function applyT(v1,v2){
  var img=document.getElementById('pub-img'), canvas=document.getElementById('pub-canvas');
  if(!img)return;
  var transform='translate('+panX+'px,'+panY+'px) scale('+ts+') rotate('+tr+'deg)';
  if(dcmInfo){ DicomViewer.draw(canvas,dcmInfo,Number(v1),Math.max(1,Number(v2)),ti,dcmFrame); canvas.style.filter=''; canvas.style.transform=transform; }
  else{ var f='brightness('+v1/100+') contrast('+v2/100+')'; if(ti)f+=' invert(1)'; img.style.filter=f; img.style.transform=transform; }
  if(itools)itools.sync();
  tUpdateDicomOverlayWL();
}
function tZoom(f){ts=Math.max(.3,Math.min(5,ts*f));tFiltroValores();}
function tRot(){tr=(tr+90)%360;tFiltroValores();}
function tInv(){ti=!ti;tFiltroValores();}
function tFiltroValores(){ applyT(document.getElementById('sl-br').value,document.getElementById('sl-ct').value); }
function tTool(name,btn){
  currentTool=name;
  document.querySelectorAll('.toolbar-tools .tbtn').forEach(b=>b.classList.remove('active'));
  if(btn)btn.classList.add('active');
  if(itools)itools.setTool(name);
}
function tClearAnotaciones(){ if(itools)itools.clear(); }
function tExportPng(){ if(!itools)return; itools.exportPng('imagen-compartida-'+(dcmInfo?(dcmFrame+1):1)+'.png'); }
function tUpdateDicomOverlay(){
  var el=document.getElementById('tdicom-overlay'), bar=document.getElementById('tpreset-bar');
  if(!el)return;
  if(!dcmInfo){ el.classList.add('d-none'); if(bar){bar.classList.add('d-none');bar.classList.remove('d-flex');} return; }
  el.classList.remove('d-none');
  if(bar){bar.classList.remove('d-none');bar.classList.add('d-flex');}
  var img=dcmInfo.image;
  var name=img.getPatientName?String(img.getPatientName()||'').replace(/\^/g,' ').trim():'';
  var pid=img.getPatientID?String(img.getPatientID()||'').trim():'';
  document.getElementById('tdoi-tl').innerHTML=(name?'<strong>'+name+'</strong><br>':'')+(pid?'ID: '+pid:'');
  var mod=img.getModality?(img.getModality()||''):'';
  var raw=img.getStudyDate?(img.getStudyDate()||''):'';
  var fmtD=raw.length===8?raw.substr(6,2)+'/'+raw.substr(4,2)+'/'+raw.substr(0,4):raw;
  document.getElementById('tdoi-tr').innerHTML=(mod?'<strong>'+mod+'</strong>':'')+(fmtD?(mod?' · ':'')+fmtD:'');
  tUpdateDicomOverlayWL();
}
function tUpdateDicomOverlayWL(){
  var el=document.getElementById('tdoi-bl');
  if(!el||!dcmInfo)return;
  el.textContent='WC: '+Math.round(document.getElementById('sl-br').value)+' / WW: '+Math.round(document.getElementById('sl-ct').value);
}
function tApplyPreset(wc,ww){
  if(!dcmInfo)return;
  if(wc===null){wc=dcmInfo.defaultWC;ww=dcmInfo.defaultWW;}
  document.getElementById('sl-br').value=Math.round(wc);
  document.getElementById('sl-ct').value=Math.round(Math.max(1,ww));
  document.getElementById('lb-br').textContent=Math.round(wc);
  document.getElementById('lb-ct').textContent=Math.round(ww);
  applyT(wc,ww);
}
function tFrame(){
  dcmFrame=Number(document.getElementById('sl-frame').value);
  document.getElementById('lb-frame').textContent=(dcmFrame+1)+'/'+dcmInfo.numFrames;
  tFiltroValores();
}
function tReset(){
  ts=1;tr=0;ti=false;dcmFrame=0;panX=0;panY=0;
  var frameCtrl=document.getElementById('frame-ctrl');
  if(dcmInfo){
    document.getElementById('sl-br').min=dcmInfo.min; document.getElementById('sl-br').max=dcmInfo.max;
    document.getElementById('sl-br').value=Math.round(dcmInfo.defaultWC);
    document.getElementById('sl-ct').min=1; document.getElementById('sl-ct').max=Math.round((dcmInfo.max-dcmInfo.min)*2)||1;
    document.getElementById('sl-ct').value=Math.round(dcmInfo.defaultWW);
    document.getElementById('lb-br-name').textContent='Centro'; document.getElementById('lb-ct-name').textContent='Ancho';
    document.getElementById('lb-br').textContent=Math.round(dcmInfo.defaultWC); document.getElementById('lb-ct').textContent=Math.round(dcmInfo.defaultWW);
    if(dcmInfo.numFrames>1){ frameCtrl.classList.remove('d-none'); document.getElementById('sl-frame').max=dcmInfo.numFrames-1; document.getElementById('sl-frame').value=0; document.getElementById('lb-frame').textContent='1/'+dcmInfo.numFrames; }
    else frameCtrl.classList.add('d-none');
  } else {
    document.getElementById('sl-br').min=20; document.getElementById('sl-br').max=200; document.getElementById('sl-br').value=100;
    document.getElementById('sl-ct').min=20; document.getElementById('sl-ct').max=300; document.getElementById('sl-ct').value=100;
    document.getElementById('lb-br-name').textContent='Brillo'; document.getElementById('lb-ct-name').textContent='Contraste';
    document.getElementById('lb-br').textContent='100%'; document.getElementById('lb-ct').textContent='100%';
    frameCtrl.classList.add('d-none');
  }
  tFiltroValores();
}
function tCargar(src,el){
  var _idx=tImgList.findIndex(function(x){return x.url===src;});
  if(_idx>=0)tImgIdx=_idx;
  currentSrc=src;
  var img=document.getElementById('pub-img'), canvas=document.getElementById('pub-canvas'), msg=document.getElementById('pub-msg');
  if(el){ document.querySelectorAll('.thumb').forEach(t=>t.classList.remove('sel')); el.classList.add('sel'); }
  msg.classList.add('d-none');
  img.style.transform=''; img.style.filter='';
  canvas.style.transform=''; canvas.style.filter='';
  if(typeof DicomViewer!=='undefined'&&DicomViewer.isDicom(src)){
    img.classList.add('d-none'); canvas.classList.add('d-none');
    msg.classList.remove('d-none'); msg.textContent='Cargando imagen DICOM...';
    DicomViewer.load(src).then(function(info){ dcmInfo=info; msg.classList.add('d-none'); canvas.classList.remove('d-none'); tReset(); tUpdateDicomOverlay(); })
    .catch(function(){ dcmInfo=null; tUpdateDicomOverlay(); canvas.classList.add('d-none'); msg.classList.remove('d-none'); msg.textContent='No se pudo previsualizar este archivo DICOM.'; });
  } else {
    dcmInfo=null; tUpdateDicomOverlay();
    canvas.classList.add('d-none'); img.classList.remove('d-none');
    img.onload=function(){ if(itools)itools.sync(); };
    img.src=src; tReset();
  }
}
(function(){
  var stage=document.getElementById('pub-stage'), overlay=document.getElementById('pub-overlay');
  if(!stage||!overlay||typeof ImageTools==='undefined')return;
  itools=ImageTools.create({
    overlay:overlay,
    getActive:function(){ return dcmInfo?document.getElementById('pub-canvas'):document.getElementById('pub-img'); },
    getState:function(){ return {tr:tr}; },
    getPixelSpacing:function(){ return dcmInfo?ImageTools.pixelSpacingFromImage(dcmInfo.image):null; },
    getCalibKey:function(){ return currentSrc; },
    onCalibrationChange:tCalibBadge,
    onWheel:function(dir){
      if(dcmInfo&&dcmInfo.numFrames>1){ var sl=document.getElementById('sl-frame'); sl.value=Math.min(Number(sl.max),Math.max(0,dcmFrame+dir)); tFrame(); }
      else if(tImgList.length>1){ tImgIdx=Math.min(tImgList.length-1,Math.max(0,tImgIdx+dir)); var item=tImgList[tImgIdx]; tCargar(item.url,item.el); }
      else tZoom(dir>0?0.9:1.1);
    }
    // onChange omitido: compartidos son solo lectura (no guardan anotaciones)
  });
  itools.setTool('pan');
  var dragging=false,lastX=0,lastY=0;
  stage.addEventListener('pointerdown',function(e){ if(currentTool!=='pan'&&currentTool!=='wl')return; if(e.target.closest('button,a'))return; dragging=true;lastX=e.clientX;lastY=e.clientY;stage.setPointerCapture(e.pointerId);stage.style.cursor=currentTool==='pan'?'grabbing':'ns-resize'; });
  stage.addEventListener('pointermove',function(e){
    if(!dragging)return;
    var dx=e.clientX-lastX,dy=e.clientY-lastY;lastX=e.clientX;lastY=e.clientY;
    if(currentTool==='pan'){ panX+=dx;panY+=dy;tFiltroValores(); }
    else if(currentTool==='wl'){
      var br=document.getElementById('sl-br'),ct=document.getElementById('sl-ct');
      if(dcmInfo){ var range=(dcmInfo.max-dcmInfo.min)||1; br.value=Math.round(Math.min(Number(br.max),Math.max(Number(br.min),Number(br.value)-dy*range/256))); ct.value=Math.round(Math.min(Number(ct.max),Math.max(Number(ct.min),Number(ct.value)+dx*range/128))); }
      else{ br.value=Math.min(Number(br.max),Math.max(Number(br.min),Number(br.value)-dy)); ct.value=Math.min(Number(ct.max),Math.max(Number(ct.min),Number(ct.value)+dx)); }
      tFiltro();
    }
  });
  stage.addEventListener('pointerup',function(){ dragging=false;stage.style.cursor=''; });
  window.addEventListener('resize',function(){ if(itools)itools.sync(); });
})();
document.querySelectorAll('.thumb').forEach(function(el){
  var onclick=el.getAttribute('onclick')||'';
  var match=onclick.match(/tCargar\('([^']+)'/);
  if(match)tImgList.push({url:match[1],el:el});
});
(function(){ var img=document.getElementById('pub-img'); if(img)tCargar(img.getAttribute('src'),null); })();
</script>
<?php endif; ?>
</body>
</html>
