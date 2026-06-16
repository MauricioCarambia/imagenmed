<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
require_once __DIR__ . '/../config/helpers.php';

$q = trim($_GET['q'] ?? '');

if ($q === '') {
    redir(BASE_URL . '/admin/');
}

// ¿Es un código de acceso de estudio? (8 caracteres alfanuméricos)
$codigo = strtoupper($q);
if (preg_match('/^[A-Z0-9]{8}$/', $codigo)) {
    $stmt = db()->prepare('SELECT id FROM estudios WHERE codigo_acceso = ?');
    $stmt->execute([$codigo]);
    $estId = $stmt->fetchColumn();
    if ($estId) {
        redir(BASE_URL . '/admin/ver_estudio.php?id=' . $estId);
    }
}

// Buscar por nombre/apellido/DNI en pacientes
redir(BASE_URL . '/admin/pacientes.php?q=' . urlencode($q));
