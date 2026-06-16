<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$nombre = getCfg('nombre_centro', 'ImagenMed');
echo json_encode([
    'name'             => $nombre,
    'short_name'       => 'ImagenMed',
    'description'      => $nombre . ' · Portal de estudios por imágenes',
    'start_url'        => BASE_URL . '/ver/',
    'scope'            => BASE_URL . '/ver/',
    'display'          => 'standalone',
    'orientation'      => 'any',
    'background_color' => '#16181d',
    'theme_color'      => '#16181d',
    'lang'             => 'es',
    'icons'            => [
        [
            'src'     => BASE_URL . '/assets/img/favicon.svg',
            'sizes'   => 'any',
            'type'    => 'image/svg+xml',
            'purpose' => 'any maskable',
        ],
    ],
    'screenshots' => [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
