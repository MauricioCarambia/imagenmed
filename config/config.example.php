<?php
// ============================================================
//  ImagenMed · Configuración global
//  Copiá este archivo a config.php y completá con tus datos
// ============================================================

define('DB_HOST',    'localhost');
define('DB_NAME',    'imagenmed');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

// URL base del sistema (sin barra final)
define('BASE_URL', 'http://localhost/imagenmed');

// Ruta absoluta al directorio de uploads
define('UPLOAD_DIR', __DIR__ . '/../uploads/');

// Tipos de estudio
define('TIPOS_ESTUDIO', [
    'RX'  => 'Radiografía',
    'ECO' => 'Ecografía',
    'TAC' => 'Tomografía',
    'MAM' => 'Mamografía',
    'RMN' => 'Resonancia Magnética',
    'OTR' => 'Otro',
]);

// Extensiones permitidas para upload
define('EXT_PERMITIDAS', ['jpg','jpeg','png','gif','dcm']);

// Tamaño máximo por archivo (bytes) — 20 MB
define('MAX_FILE_SIZE', 20 * 1024 * 1024);

// Días de vigencia del link público (0 = sin vencimiento)
define('DIAS_VIGENCIA', 0);

// Timezone
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Forzar HTTPS (activar en producción)
define('FORCE_HTTPS', false);

// ============================================================
//  Cabeceras de seguridad
// ============================================================
if (FORCE_HTTPS && empty($_SERVER['HTTPS']) && empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header(
    "Content-Security-Policy: default-src 'self'; " .
    "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; " .
    "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; " .
    "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com data:; " .
    "img-src 'self' data: blob:; " .
    "connect-src 'self' https://cdn.jsdelivr.net; " .
    "object-src 'none'; " .
    "base-uri 'self'; " .
    "frame-ancestors 'self';"
);
if (FORCE_HTTPS) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
