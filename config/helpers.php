<?php
// ============================================================
//  ImagenMed · Funciones de utilidad
// ============================================================
require_once __DIR__ . '/config.php';

/**
 * Genera un código de acceso único de 8 caracteres alfanuméricos.
 */
function generarCodigo(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $existe = db()->prepare('SELECT id FROM estudios WHERE codigo_acceso = ?');
        $existe->execute([$code]);
    } while ($existe->fetch());
    return $code;
}

/**
 * Sube una imagen al servidor y devuelve el nombre guardado en disco.
 * Retorna false si falla.
 */
function subirImagen(array $file): string|false {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, EXT_PERMITIDAS)) return false;
    if ($file['size'] <= 0 || $file['size'] > MAX_FILE_SIZE) return false;
    if (!is_uploaded_file($file['tmp_name'])) return false;

    if ($ext === 'dcm') {
        // Verificar firma DICOM ("DICM" en el byte 128)
        $fh = fopen($file['tmp_name'], 'rb');
        $valido = false;
        if ($fh) {
            fseek($fh, 128);
            $valido = fread($fh, 4) === 'DICM';
            fclose($fh);
        }
        if (!$valido) return false;
    } else {
        // Verificar que el contenido sea realmente una imagen
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) return false;
        $mimesPermitidos = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($info['mime'], $mimesPermitidos, true)) return false;
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    $nombre = bin2hex(random_bytes(16)) . '.' . $ext;
    $destino = UPLOAD_DIR . $nombre;

    if (move_uploaded_file($file['tmp_name'], $destino)) {
        return $nombre;
    }
    return false;
}

/**
 * Devuelve la URL pública de una imagen.
 */
function urlImagen(string $filename): string {
    return BASE_URL . '/uploads/' . rawurlencode($filename);
}

/**
 * Escapa HTML para output seguro.
 */
function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

/**
 * Formatea fecha de BD (Y-m-d) a d/m/Y.
 */
function fmtFecha(string $fecha): string {
    return date('d/m/Y', strtotime($fecha));
}

/**
 * Etiqueta legible del tipo de estudio.
 */
function labelTipo(string $tipo): string {
    return TIPOS_ESTUDIO[$tipo] ?? $tipo;
}

/**
 * Redirige y corta ejecución.
 */
function redir(string $url): never {
    header('Location: ' . $url);
    exit;
}

/**
 * Genera un link de wa.me para enviar un mensaje por WhatsApp a un teléfono.
 * Devuelve null si el teléfono está vacío.
 */
function waLink(?string $telefono, string $mensaje): ?string {
    $tel = preg_replace('/\D+/', '', $telefono ?? '');
    if ($tel === '') return null;
    return 'https://wa.me/' . $tel . '?text=' . rawurlencode($mensaje);
}

/**
 * Registra una acción en el log de auditoría.
 */
function registrarAuditoria(string $accion, string $entidad, ?int $entidadId = null, ?string $detalle = null): void {
    iniciarSesionSegura();
    $usuarioId = $_SESSION['usuario_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    db()->prepare(
        'INSERT INTO auditoria (usuario_id, accion, entidad, entidad_id, detalle, ip) VALUES (?,?,?,?,?,?)'
    )->execute([$usuarioId, $accion, $entidad, $entidadId, $detalle, $ip]);
}

/**
 * Devuelve respuesta JSON y corta ejecución (para endpoints AJAX).
 */
function jsonOut(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Envía un email. Usa SMTP (PHPMailer) si config/mail.php está habilitado
 * y configurado; si no, intenta con mail() como respaldo.
 * Devuelve true si se envió correctamente.
 */
function enviarEmail(string $destino, string $asunto, string $cuerpo): bool {
    $cfgPath = __DIR__ . '/mail.php';
    $cfg = is_file($cfgPath) ? require $cfgPath : [];

    if (!empty($cfg['enabled']) && !empty($cfg['username']) && !empty($cfg['password'])) {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = $cfg['host'];
                $mail->Port       = $cfg['port'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $cfg['username'];
                $mail->Password   = $cfg['password'];
                $mail->SMTPSecure = $cfg['encryption'] === 'ssl'
                    ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                    : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->CharSet = 'UTF-8';

                $mail->setFrom($cfg['from_email'] ?: $cfg['username'], $cfg['from_name'] ?? 'ImagenMed');
                $mail->addAddress($destino);

                $mail->Subject = $asunto;
                $mail->Body    = $cuerpo;
                $mail->AltBody = $cuerpo;

                $mail->send();
                return true;
            } catch (Throwable $e) {
                return false;
            }
        }
    }

    return @mail($destino, $asunto, $cuerpo);
}

/**
 * Verifica si el usuario de sesión tiene permiso para una acción.
 * Los permisos se leen de la tabla permisos_rol (configurable por admin).
 * El rol 'admin' siempre tiene todos los permisos.
 */
function puedeHacer(string $accion): bool {
    static $u = null;
    static $mapa = null;
    if ($u === null) {
        iniciarSesionSegura();
        $u = $_SESSION['usuario'] ?? ['rol' => ''];
    }
    $rol = $u['rol'] ?? 'usuario';
    if ($rol === 'admin') return true;
    if ($mapa === null) {
        try {
            $rows = db()->query('SELECT rol, accion FROM permisos_rol')->fetchAll();
            $mapa = [];
            foreach ($rows as $r) {
                $mapa[$r['rol']][$r['accion']] = true;
            }
        } catch (Throwable) {
            $mapa = [];
        }
    }
    return isset($mapa[$rol][$accion]);
}

/**
 * Lee un valor de la tabla configuracion. Devuelve $default si no existe.
 */
function getCfg(string $clave, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        try {
            $rows = db()->query('SELECT clave, valor FROM configuracion')->fetchAll();
            $cache = array_column($rows, 'valor', 'clave');
        } catch (Throwable) { $cache = []; }
    }
    return $cache[$clave] ?? $default;
}

/**
 * Purga estudios más viejos que dias_retencion_estudios días.
 * Corre máximo una vez por día (guardado en configuracion.last_purge).
 */
function purgarEstudiosViejos(): void {
    try {
        $db   = db();
        $dias = (int)getCfg('dias_retencion_estudios', '0');
        if ($dias <= 0) return;

        // Evitar correr más de una vez por día
        $lastPurge = getCfg('last_purge', '');
        if ($lastPurge && $lastPurge === date('Y-m-d')) return;

        // Estudios viejos
        $stmt = $db->prepare(
            'SELECT e.id FROM estudios e WHERE e.created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute([$dias]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // Recuperar archivos de imagen para borrarlos del disco
            $imgStmt = $db->prepare("SELECT filename FROM imagenes WHERE estudio_id IN ($placeholders)");
            $imgStmt->execute($ids);
            foreach ($imgStmt->fetchAll(PDO::FETCH_COLUMN) as $filename) {
                $path = UPLOAD_DIR . $filename;
                if (is_file($path)) @unlink($path);
            }

            // Borrar estudios (cascada elimina imagenes, informes, anotaciones, compartidos)
            $db->prepare("DELETE FROM estudios WHERE id IN ($placeholders)")->execute($ids);
            registrarAuditoria('purga_automatica', 'estudios', 0, count($ids) . ' estudios eliminados (retención ' . $dias . ' días)');
        }

        // Actualizar fecha de última purga
        $db->prepare('INSERT INTO configuracion (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?')
           ->execute(['last_purge', date('Y-m-d'), date('Y-m-d')]);

    } catch (Throwable) {
        // Silencioso — no interrumpir la carga de página por un error de purga
    }
}