<?php
/**
 * auth.php
 * Autenticación mediante un token firmado (HMAC-SHA256), similar a un JWT
 * pero sin depender de Composer/librerías externas (para máxima compatibilidad
 * con hosting compartido donde no siempre hay acceso a línea de comandos).
 *
 * Formato del token: base64url(payload_json) . "." . hmac_sha256(payload, SECRET_KEY)
 */
require_once __DIR__ . '/config.php';

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

function crearToken(array $payload) {
    $payload['exp'] = time() + TOKEN_TTL;
    $json = json_encode($payload);
    $encoded = base64url_encode($json);
    $firma = hash_hmac('sha256', $encoded, SECRET_KEY);
    return $encoded . '.' . $firma;
}

/** Devuelve el payload (array) si el token es válido, o null si no lo es / ha caducado. */
function verificarToken($token) {
    if (!$token || strpos($token, '.') === false) return null;
    list($encoded, $firma) = explode('.', $token, 2);
    $firmaEsperada = hash_hmac('sha256', $encoded, SECRET_KEY);
    if (!hash_equals($firmaEsperada, $firma)) return null;

    $json = base64url_decode($encoded);
    $payload = json_decode($json, true);
    if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) return null;

    return $payload;
}

function obtenerTokenDeCabecera() {
    $headers = null;
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    }
    $auth = null;
    if ($headers) {
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'authorization') { $auth = $v; break; }
        }
    }
    if (!$auth && isset($_SERVER['HTTP_AUTHORIZATION'])) $auth = $_SERVER['HTTP_AUTHORIZATION'];
    if (!$auth && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];

    if ($auth && stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }
    return null;
}

/** Corta la ejecución con un error JSON */
function jsonError($status, $mensaje) {
    http_response_code($status);
    echo json_encode(['error' => $mensaje]);
    exit;
}

function jsonOk($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

/** Devuelve el usuario autenticado (array) o corta con 401 */
function requireAuth() {
    $token = obtenerTokenDeCabecera();
    if (!$token) jsonError(401, 'No autenticado. Falta el token.');
    $payload = verificarToken($token);
    if (!$payload) jsonError(401, 'Token inválido o caducado.');
    return $payload;
}

/** Comprueba que el usuario autenticado tenga uno de los roles indicados */
function requireRole($usuario, array $rolesPermitidos) {
    if (!in_array($usuario['rol'], $rolesPermitidos, true)) {
        jsonError(403, 'No tienes permisos para realizar esta acción.');
    }
}
