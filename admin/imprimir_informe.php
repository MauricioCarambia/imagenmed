<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT e.*, p.nombre, p.apellido, p.dni, p.fecha_nac, p.obra_social, p.nro_afiliado, p.telefono, p.email
     FROM estudios e JOIN pacientes p ON p.id=e.paciente_id WHERE e.id=?'
);
$stmt->execute([$id]);
$est = $stmt->fetch();
if (!$est) die('Estudio no encontrado.');

$informe = db()->prepare('SELECT i.cuerpo, i.firmado_en, i.hash_contenido,
                                  u.nombre AS medico_nombre, u.firma_img AS medico_firma
                           FROM informes i LEFT JOIN usuarios u ON u.id=i.usuario_id
                           WHERE i.estudio_id=?');
$informe->execute([$id]);
$inf = $informe->fetch();

$cfg = db()->query('SELECT clave, valor FROM configuracion')->fetchAll(PDO::FETCH_KEY_PAIR);
$urlPublica = BASE_URL . '/ver/' . $est['codigo_acceso'];
$logoSrc = !empty($cfg['logo_filename']) ? BASE_URL.'/uploads/'.rawurlencode($cfg['logo_filename']) : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Informe · <?= e($est['apellido'].', '.$est['nombre']) ?></title>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap');
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', Arial, sans-serif; font-size: 10pt; color: #111; background: #f0f0f0; }

@media screen {
  .page { background: #fff; max-width: 210mm; min-height: 297mm; margin: 20px auto;
          padding: 18mm 20mm; box-shadow: 0 2px 16px rgba(0,0,0,.15); }
  .print-btn { position: fixed; top: 16px; right: 16px; padding: 8px 18px;
               background: #5b8def; color: #fff; border: none; border-radius: 6px;
               cursor: pointer; font-size: 13px; font-family: inherit; z-index: 100; }
  .print-btn:hover { background: #3f6fd1; }
}

@media print {
  body { background: #fff; }
  .page { padding: 14mm 18mm; }
  .print-btn { display: none !important; }
}

/* Cabecera */
.header { display: flex; justify-content: space-between; align-items: flex-start;
          border-bottom: 2px solid #16181d; padding-bottom: 8px; margin-bottom: 14px; }
.header-left { flex: 1; }
.header-logo { max-height: 56px; max-width: 160px; object-fit: contain; margin-bottom: 4px; }
.centro-nombre { font-size: 14pt; font-weight: 600; color: #16181d; }
.centro-sub { font-size: 9pt; color: #555; margin-top: 2px; }
.centro-contact { font-size: 8.5pt; color: #555; margin-top: 4px; }
.header-right { text-align: right; }
.header-right .fecha-label { font-size: 8pt; color: #777; }
.header-right .fecha-val { font-size: 10pt; font-weight: 600; }

/* Título del documento */
.doc-title { text-align: center; font-size: 13pt; font-weight: 600; letter-spacing: .04em;
             text-transform: uppercase; color: #16181d; margin: 10px 0 14px; }

/* Datos del paciente */
.seccion { margin-bottom: 14px; }
.seccion-titulo { font-size: 8pt; font-weight: 600; text-transform: uppercase;
                  letter-spacing: .08em; color: #5b8def; margin-bottom: 6px;
                  border-bottom: 1px solid #e3e3e6; padding-bottom: 3px; }
.datos-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 20px; }
.dato { font-size: 9.5pt; }
.dato-label { color: #666; font-size: 8.5pt; }

/* Estudio */
.estudio-tipo { display: inline-block; background: #e6f1fb; color: #0c447c;
                font-size: 9pt; font-weight: 600; border-radius: 4px;
                padding: 2px 8px; margin-bottom: 6px; }

/* Informe */
.informe-body { font-size: 10.5pt; line-height: 1.75; white-space: pre-wrap;
                min-height: 120px; margin-top: 6px; }
.informe-vacio { color: #aaa; font-style: italic; min-height: 80px; }

/* Firma */
.firma-area { margin-top: 30px; display: flex; justify-content: flex-end; }
.firma-box { text-align: center; width: 260px; }
.firma-img-wrap { height: 70px; display: flex; align-items: flex-end; justify-content: center; margin-bottom: 0; }
.firma-img-wrap img { max-height: 68px; max-width: 240px; object-fit: contain; }
.firma-linea { border-top: 1.5px solid #333; padding-top: 6px; margin-top: 4px; }
.firma-nombre { font-size: 9.5pt; font-weight: 600; }
.firma-sub { font-size: 8.5pt; color: #555; }
.firma-hash { font-size: 6pt; color: #aaa; margin-top: 6px; word-break: break-all; text-align: left; font-family: monospace; }

/* Footer */
.footer { margin-top: 24px; border-top: 1px solid #e3e3e6; padding-top: 8px;
          display: flex; justify-content: space-between; align-items: flex-end; }
.footer-left { font-size: 7.5pt; color: #777; max-width: 65%; }
.footer-qr { display: flex; flex-direction: column; align-items: center; gap: 3px; }
.footer-qr-label { font-size: 7pt; color: #777; text-align: center; }
.footer-cod { font-size: 7.5pt; font-weight: 600; letter-spacing: .08em; text-align: center; }
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()">🖨️ Imprimir / PDF</button>

<div class="page">

  <!-- Cabecera -->
  <div class="header">
    <div class="header-left">
      <?php if ($logoSrc): ?>
        <img src="<?= e($logoSrc) ?>" alt="Logo" class="header-logo">
      <?php endif; ?>
      <div class="centro-nombre"><?= e($cfg['nombre_centro'] ?? 'Centro de Diagnóstico') ?></div>
      <?php if (!empty($cfg['subtitulo_centro'])): ?>
        <div class="centro-sub"><?= e($cfg['subtitulo_centro']) ?></div>
      <?php endif; ?>
      <div class="centro-contact">
        <?php
        $contactParts = array_filter([
            $cfg['direccion'] ?? '',
            $cfg['ciudad']    ?? '',
            $cfg['telefono']  ? 'Tel: '.($cfg['telefono']) : '',
            $cfg['email_centro'] ?? '',
        ]);
        echo e(implode('  ·  ', $contactParts));
        ?>
      </div>
    </div>
    <div class="header-right">
      <div class="fecha-label">Fecha del estudio</div>
      <div class="fecha-val"><?= fmtFecha($est['fecha_estudio']) ?></div>
      <?php if (!empty($cfg['matricula'])): ?>
        <div class="centro-contact" style="margin-top:4px;"><?= e($cfg['matricula']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="doc-title">Informe de estudio por imágenes</div>

  <!-- Datos del paciente -->
  <div class="seccion">
    <div class="seccion-titulo">Datos del paciente</div>
    <div class="datos-grid">
      <div class="dato"><span class="dato-label">Apellido y nombre: </span><?= e($est['apellido'].', '.$est['nombre']) ?></div>
      <div class="dato"><span class="dato-label">DNI: </span><?= e($est['dni']) ?></div>
      <?php if ($est['fecha_nac']): ?><div class="dato"><span class="dato-label">Fecha de nac.: </span><?= fmtFecha($est['fecha_nac']) ?></div><?php endif; ?>
      <?php if ($est['obra_social']): ?><div class="dato"><span class="dato-label">Obra social: </span><?= e($est['obra_social']) ?></div><?php endif; ?>
      <?php if ($est['telefono']): ?><div class="dato"><span class="dato-label">Teléfono: </span><?= e($est['telefono']) ?></div><?php endif; ?>
    </div>
  </div>

  <!-- Datos del estudio -->
  <div class="seccion">
    <div class="seccion-titulo">Estudio</div>
    <div><span class="estudio-tipo"><?= e(labelTipo($est['tipo'])) ?></span></div>
    <div class="datos-grid" style="margin-top:4px;">
      <?php if ($est['descripcion']): ?><div class="dato"><span class="dato-label">Descripción: </span><?= e($est['descripcion']) ?></div><?php endif; ?>
      <?php if ($est['medico_der']): ?><div class="dato"><span class="dato-label">Médico derivante: </span><?= e($est['medico_der']) ?></div><?php endif; ?>
    </div>
  </div>

  <!-- Informe -->
  <div class="seccion">
    <div class="seccion-titulo">Informe</div>
    <?php if ($inf && $inf['cuerpo']): ?>
      <div class="informe-body"><?= e($inf['cuerpo']) ?></div>
    <?php else: ?>
      <div class="informe-vacio">Sin informe cargado.</div>
    <?php endif; ?>
  </div>

  <!-- Firma -->
  <div class="firma-area">
    <div class="firma-box">
      <?php if ($inf && !empty($inf['medico_firma'])): ?>
      <div class="firma-img-wrap">
        <img src="<?= e($inf['medico_firma']) ?>" alt="Firma">
      </div>
      <?php else: ?>
      <div style="height:70px;"></div>
      <?php endif; ?>
      <div class="firma-linea">
        <?php if ($inf && $inf['medico_nombre']): ?>
          <div class="firma-nombre"><?= e($inf['medico_nombre']) ?></div>
        <?php endif; ?>
        <div class="firma-sub">Médico informante</div>
        <?php if (!empty($cfg['matricula'])): ?>
          <div class="firma-sub"><?= e($cfg['matricula']) ?></div>
        <?php endif; ?>
        <?php if ($inf && $inf['firmado_en']): ?>
          <div class="firma-sub" style="font-size:7.5pt;color:#555;">
            Firmado digitalmente · <?= date('d/m/Y H:i', strtotime($inf['firmado_en'])) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($inf && !empty($inf['hash_contenido'])): ?>
  <div class="firma-hash">
    SHA-256: <?= e($inf['hash_contenido']) ?>
  </div>
  <?php endif; ?>

  <!-- Footer con QR -->
  <div class="footer">
    <div class="footer-left">
      <?php if (!empty($cfg['pie_informe'])): ?>
        <?= e($cfg['pie_informe']) ?>
      <?php else: ?>
        Documento generado por <?= e($cfg['nombre_centro'] ?? 'ImagenMed') ?>. Para verificar la autenticidad de este informe, escanee el código QR o ingrese el código de acceso en <?= e(BASE_URL . '/ver/') ?>.
      <?php endif; ?>
    </div>
    <div class="footer-qr">
      <div id="qr-footer"></div>
      <div class="footer-cod"><?= e($est['codigo_acceso']) ?></div>
      <div class="footer-qr-label">Acceder al estudio</div>
    </div>
  </div>

</div>

<script>
new QRCode(document.getElementById('qr-footer'), {
  text: '<?= e($urlPublica) ?>',
  width: 72, height: 72, colorDark: '#16181d', colorLight: '#ffffff'
});
</script>
</body>
</html>
