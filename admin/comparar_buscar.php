<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
require_once __DIR__ . '/../config/helpers.php';

$q = trim($_GET['q'] ?? '');
if ($q === '') jsonOut(['estudios' => []]);

$like = '%' . $q . '%';
// Un solo query con GROUP_CONCAT en vez de una query de imágenes por estudio (evita N+1)
$stmt = db()->prepare(
    'SELECT e.id, e.tipo, e.descripcion, e.fecha_estudio, e.codigo_acceso,
            p.nombre, p.apellido, p.dni,
            GROUP_CONCAT(i.id, ":", i.filename ORDER BY i.orden SEPARATOR "|") AS imgs_concat
     FROM estudios e
     JOIN pacientes p ON p.id = e.paciente_id
     LEFT JOIN imagenes i ON i.estudio_id = e.id
     WHERE p.dni LIKE ? OR p.nombre LIKE ? OR p.apellido LIKE ?
        OR CONCAT(p.apellido, " ", p.nombre) LIKE ?
     GROUP BY e.id
     ORDER BY e.fecha_estudio DESC, e.id DESC
     LIMIT 30'
);
$stmt->execute([$like, $like, $like, $like]);
$estudios = $stmt->fetchAll();

$out = [];
foreach ($estudios as $est) {
    if (!$est['imgs_concat']) continue;
    $imgs = [];
    foreach (explode('|', $est['imgs_concat']) as $par) {
        [$imgId, $filename] = explode(':', $par, 2);
        $imgs[] = [
            'id' => (int)$imgId,
            'url' => urlImagen($filename),
            'nombre' => $filename,
        ];
    }
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
