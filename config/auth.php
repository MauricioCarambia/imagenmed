<?php
// ============================================================
//  ImagenMed · Helpers de autenticación
// ============================================================
require_once __DIR__ . '/db.php';

function iniciarSesionSegura(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function usuarioLogueado(): bool {
    iniciarSesionSegura();
    return !empty($_SESSION['usuario_id']);
}

function requireLogin(): void {
    if (!usuarioLogueado()) {
        header('Location: ' . BASE_URL . '/admin/login.php');
        exit;
    }
}

function sesionUsuario(): array {
    iniciarSesionSegura();
    return $_SESSION['usuario'] ?? [];
}

const LOGIN_MAX_INTENTOS = 5;
const LOGIN_BLOQUEO_MIN  = 15;

function loginIdentificador(string $email): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return strtolower($email) . '|' . $ip;
}

/**
 * Devuelve los minutos restantes de bloqueo, o 0 si no está bloqueado.
 */
function loginBloqueado(string $email): int {
    $stmt = db()->prepare('SELECT bloqueado_hasta FROM login_intentos WHERE identificador = ?');
    $stmt->execute([loginIdentificador($email)]);
    $hasta = $stmt->fetchColumn();
    if ($hasta && strtotime($hasta) > time()) {
        return (int)ceil((strtotime($hasta) - time()) / 60);
    }
    return 0;
}

function loginRegistrarFallo(string $email): void {
    $id = loginIdentificador($email);
    $stmt = db()->prepare('SELECT intentos FROM login_intentos WHERE identificador = ?');
    $stmt->execute([$id]);
    $intentos = (int)$stmt->fetchColumn() + 1;

    $bloqueadoHasta = null;
    if ($intentos >= LOGIN_MAX_INTENTOS) {
        $bloqueadoHasta = date('Y-m-d H:i:s', strtotime('+' . LOGIN_BLOQUEO_MIN . ' minutes'));
    }

    db()->prepare(
        'INSERT INTO login_intentos (identificador, intentos, bloqueado_hasta) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE intentos = ?, bloqueado_hasta = ?'
    )->execute([$id, $intentos, $bloqueadoHasta, $intentos, $bloqueadoHasta]);
}

function loginRegistrarExito(string $email): void {
    db()->prepare('DELETE FROM login_intentos WHERE identificador = ?')->execute([loginIdentificador($email)]);
}

function login(string $email, string $pass): bool {
    $stmt = db()->prepare('SELECT id, nombre, rol, password FROM usuarios WHERE email = ? AND activo = 1');
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if ($u && password_verify($pass, $u['password'])) {
        iniciarSesionSegura();
        session_regenerate_id(true);
        $_SESSION['usuario_id'] = $u['id'];
        $_SESSION['usuario']    = ['id' => $u['id'], 'nombre' => $u['nombre'], 'rol' => $u['rol']];
        return true;
    }
    return false;
}

function logout(): void {
    iniciarSesionSegura();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: ' . BASE_URL . '/admin/login.php');
    exit;
}