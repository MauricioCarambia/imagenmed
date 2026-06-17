<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT e.*, p.nombre, p.apellido, p.dni FROM estudios e
     JOIN pacientes p ON p.id=e.paciente_id WHERE e.id=?'
);
$stmt->execute([$id]);
$est = $stmt->fetch();
if (!$est) die('No encontrado.');

$ancho  = in_array($_GET['ancho'] ?? '', ['58','80']) ? $_GET['ancho'] : '80';
$auto   = ($_GET['auto'] ?? '') === '1';
$urlPublica = BASE_URL . '/ver/' . $est['codigo_acceso'];
$cfg = db()->query('SELECT clave, valor FROM configuracion')->fetchAll(PDO::FETCH_KEY_PAIR);
$qrSize = $ancho === '58' ? 130 : 180;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Etiqueta QR · <?= e($est['codigo_acceso']) ?></title>
<style>
<?php if ($ancho === '58'): ?>
@page { size: 58mm auto; margin: 0; }
body  { width: 58mm; }
<?php else: ?>
@page { size: 80mm auto; margin: 0; }
body  { width: 80mm; }
<?php endif; ?>

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: Arial, sans-serif;
  background: #fff;
  padding: 3mm 4mm 4mm;
}

/* Centro */
.centro {
  font-size: <?= $ancho==='58' ? '7.5' : '8' ?>pt;
  font-weight: bold;
  text-align: center;
  border-bottom: 1px dashed #999;
  padding-bottom: 2mm;
  margin-bottom: 2mm;
}

/* QR centrado */
.qr-wrap {
  display: flex;
  justify-content: center;
  margin: 2mm 0;
}

/* Datos del paciente */
.paciente {
  font-size: <?= $ancho==='58' ? '9' : '10' ?>pt;
  font-weight: bold;
  text-align: center;
  margin-bottom: 1mm;
}
.dato {
  font-size: <?= $ancho==='58' ? '7' : '7.5' ?>pt;
  text-align: center;
  color: #333;
  margin-bottom: 0.5mm;
}
.tipo {
  font-size: <?= $ancho==='58' ? '7.5' : '8.5' ?>pt;
  font-weight: bold;
  text-align: center;
  margin: 1.5mm 0 0.5mm;
}
.separador {
  border: none;
  border-top: 1px dashed #aaa;
  margin: 2mm 0;
}
.codigo {
  font-size: <?= $ancho==='58' ? '11' : '13' ?>pt;
  font-weight: bold;
  letter-spacing: .12em;
  text-align: center;
  margin: 1mm 0;
}
.url {
  font-size: <?= $ancho==='58' ? '5.5' : '6' ?>pt;
  color: #666;
  text-align: center;
  word-break: break-all;
  margin-top: 0.5mm;
}
.instruccion {
  font-size: <?= $ancho==='58' ? '6' : '6.5' ?>pt;
  color: #888;
  text-align: center;
  margin-top: 1.5mm;
}

/* Pantalla: preview + botones */
@media screen {
  html { background: #f0f0f0; }
  body {
    margin: 20px auto;
    box-shadow: 0 2px 12px rgba(0,0,0,.2);
    border: 1px solid #ddd;
  }
  .controles {
    position: fixed;
    top: 12px; right: 12px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    z-index: 100;
  }
  .btn {
    padding: 7px 14px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
  }
  .btn-print  { background: #5b8def; color: #fff; }
  .btn-58     { background: #fff; border: 1px solid #ccc; color: #333; font-size: 11px; }
  .btn-80     { background: #fff; border: 1px solid #ccc; color: #333; font-size: 11px; }
  .btn-active { background: #16181d; color: #fff; border-color: #16181d; }
}
@media print {
  .controles { display: none !important; }
}
</style>
</head>
<body>

<div class="controles">
  <button class="btn btn-print" onclick="window.print()">🖨️ Imprimir</button>
  <a class="btn btn-58 <?= $ancho==='58'?'btn-active':'' ?>"
     href="?id=<?= $id ?>&ancho=58">58 mm</a>
  <a class="btn btn-80 <?= $ancho==='80'?'btn-active':'' ?>"
     href="?id=<?= $id ?>&ancho=80">80 mm</a>
</div>

<?php if (!empty($cfg['nombre_centro'])): ?>
<div class="centro"><?= e($cfg['nombre_centro']) ?></div>
<?php endif; ?>

<div class="qr-wrap">
  <div id="qr"></div>
</div>

<div class="paciente"><?= e($est['apellido'] . ', ' . $est['nombre']) ?></div>
<div class="dato">DNI: <?= e($est['dni']) ?></div>
<div class="dato"><?= fmtFecha($est['fecha_estudio']) ?></div>
<div class="tipo"><?= e(labelTipo($est['tipo'])) ?><?= $est['descripcion'] ? ' · ' . e($est['descripcion']) : '' ?></div>

<hr class="separador">

<div class="codigo"><?= e($est['codigo_acceso']) ?></div>
<div class="url"><?= e($urlPublica) ?></div>
<div class="instruccion">Escaneá el QR o ingresá el código en:<br><?= e(BASE_URL . '/ver/') ?></div>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
new QRCode(document.getElementById('qr'), {
  text: '<?= e($urlPublica) ?>',
  width: <?= $qrSize ?>, height: <?= $qrSize ?>,
  colorDark: '#000000', colorLight: '#ffffff',
  correctLevel: QRCode.CorrectLevel.M
});
<?php if ($auto): ?>
window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 400); });
<?php endif; ?>
</script>
</body>
</html>
