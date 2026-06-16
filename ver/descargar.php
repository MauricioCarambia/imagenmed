<?php
// Descarga de imágenes en ZIP
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

$codigo = strtoupper(preg_replace('/[^A-Z0-9]/', '', $_GET['c'] ?? ''));
if (!$codigo) redir(BASE_URL . '/ver/');

$stmt = db()->prepare(
    'SELECT e.id, e.tipo, e.fecha_estudio, p.apellido, p.nombre
     FROM estudios e JOIN pacientes p ON p.id=e.paciente_id
     WHERE e.codigo_acceso=? AND e.activo=1'
);
$stmt->execute([$codigo]);
$est = $stmt->fetch();
if (!$est) { http_response_code(404); die('No encontrado.'); }

$imgs = db()->prepare('SELECT filename, original FROM imagenes WHERE estudio_id=? ORDER BY orden');
$imgs->execute([$est['id']]);
$imgs = $imgs->fetchAll();
if (!$imgs) { die('Sin imágenes.'); }

// Si sólo hay una imagen, descargar directamente
if (count($imgs) === 1) {
    $path = UPLOAD_DIR . $imgs[0]['filename'];
    if (!file_exists($path)) { http_response_code(404); die('Archivo no encontrado.'); }
    $ext = pathinfo($imgs[0]['original'], PATHINFO_EXTENSION);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="estudio_' . $codigo . '.' . $ext . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// Múltiples → ZIP
if (!class_exists('ZipArchive')) { die('ZipArchive no disponible en este servidor.'); }

$zipName = 'estudio_' . $codigo . '_' . date('Ymd') . '.zip';
$tmpZip  = sys_get_temp_dir() . '/' . $zipName;

$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die('No se pudo crear el archivo ZIP.');
}

foreach ($imgs as $i => $img) {
    $path = UPLOAD_DIR . $img['filename'];
    if (file_exists($path)) {
        $ext      = pathinfo($img['original'], PATHINFO_EXTENSION);
        $nombre   = sprintf('%02d_%s.%s', $i + 1, pathinfo($img['original'], PATHINFO_FILENAME), $ext);
        $zip->addFile($path, $nombre);
    }
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpZip));
readfile($tmpZip);
unlink($tmpZip);
exit;