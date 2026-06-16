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

$urlPublica = BASE_URL . '/ver/' . $est['codigo_acceso'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Etiqueta QR · <?= e($est['codigo_acceso']) ?></title>
<style>
@page { size: 80mm 50mm; margin: 0; }
* { box-sizing: border-box; margin: 0; }
body { font-family: Arial, sans-serif; padding: 4mm; width: 80mm; height: 50mm;
       display: flex; align-items: center; gap: 4mm; }
.qr-wrap { flex-shrink: 0; }
.info { flex: 1; overflow: hidden; }
.info h2 { font-size: 10pt; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.info p  { font-size: 7.5pt; margin-top: 1mm; color: #333; }
.info .tipo { font-size: 8.5pt; font-weight: bold; margin-top: 1mm; }
.info .cod  { font-size: 9pt; font-weight: bold; letter-spacing: .1em; margin-top: 2mm; color: #111; }
.info .url  { font-size: 6pt; color: #555; margin-top: 1mm; word-break: break-all; }
@media screen {
  body { border: 1px solid #ccc; background: #fff; margin: 20px auto; }
  .print-btn { position: fixed; top: 10px; right: 10px; padding: 8px 16px;
               background: #5b8def; color: #fff; border: none; border-radius: 6px;
               cursor: pointer; font-size: 14px; }
}
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()">🖨️ Imprimir</button>
<div class="qr-wrap" id="qr"></div>
<div class="info">
  <h2><?= e($est['apellido'] . ', ' . $est['nombre']) ?></h2>
  <p>DNI: <?= e($est['dni']) ?> &nbsp;·&nbsp; <?= fmtFecha($est['fecha_estudio']) ?></p>
  <div class="tipo"><?= e(labelTipo($est['tipo'])) ?> <?= e($est['descripcion'] ? '· '.$est['descripcion'] : '') ?></div>
  <div class="cod"><?= e($est['codigo_acceso']) ?></div>
  <div class="url"><?= e($urlPublica) ?></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
new QRCode(document.getElementById('qr'), {
  text: '<?= e($urlPublica) ?>', width: 100, height: 100
});
</script>
</body>
</html>