<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
require_once __DIR__ . '/../config/helpers.php';

$q = trim($_GET['q'] ?? '');
if ($q === '') jsonOut(['estudios' => []]);

$like = '%' . $q . '%';
$stmt = db()->prepare(
    'SELECT e.id, e.tipo, e.descripcion, e.fecha_estudio, e.codigo_acceso,
            p.nombre, p.apellido, p.dni
     FROM estudios e
     JOIN pacientes p ON p.id = e.paciente_id
     WHERE p.dni LIKE ? OR p.nombre LIKE ? OR p.apellido LIKE ?
        OR CONCAT(p.apellido, " ", p.nombre) LIKE ?
     ORDER BY e.fecha_estudio DESC, e.id DESC
     LIMIT 30'
);
$stmt->execute([$like, $like, $like, $like]);
$estudios = $stmt->fetchAll();

$out = [];
foreach ($estudios as $est) {
    $imgsStmt = db()->prepare('SELECT id, filename FROM imagenes WHERE estudio_id = ? ORDER BY orden');
    $imgsStmt->execute([$est['id']]);
    $imgs = [];
    foreach ($imgsStmt->fetchAll() as $img) {
        $imgs[] = [
            'id' => (int)$img['id'],
            'url' => urlImagen($img['filename']),
            'nombre' => $img['filename'],
        ];
    }
    if (!$imgs) continue;
    $out[] = [
        'id' => (int)$est['id'],
        'paciente' => $est['apellido'] . ', ' . $est['nombre'],
        'dni' => $est['dni'],
        'tipo' => labelTipo($est['tipo']),
        'descripcion' => $est['descripcion'],
        'fecha' => fmtFecha($est['fecha_estudio']),
        'imagenes' => $imgs,
    ];
}

jsonOut(['estudios' => $out]);
