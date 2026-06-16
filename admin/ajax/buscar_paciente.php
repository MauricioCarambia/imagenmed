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
    jsonOut(['ok' => true] + $p);
} else {
    jsonOut(['ok' => false]);
}