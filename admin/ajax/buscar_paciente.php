<?php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/helpers.php';
requireLogin();

$dni = trim($_GET['dni'] ?? '');
if (!$dni) jsonOut(['ok' => false]);

$stmt = db()->prepare('SELECT * FROM pacientes WHERE dni = ? LIMIT 1');
$stmt->execute([$dni]);
$p = $stmt->fetch();

if ($p) {
    // Solo los campos que el formulario de nuevo estudio realmente autocompleta
    jsonOut([
        'ok'           => true,
        'nombre'       => $p['nombre'],
        'apellido'     => $p['apellido'],
        'fecha_nac'    => $p['fecha_nac'],
        'telefono'     => $p['telefono'],
        'email'        => $p['email'],
        'obra_social'  => $p['obra_social'],
        'nro_afiliado' => $p['nro_afiliado'],
    ]);
} else {
    jsonOut(['ok' => false]);
}