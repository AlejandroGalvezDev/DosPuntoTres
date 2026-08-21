<?php
/**
 * index.php — Front controller de la API.
 * Todas las peticiones a /dospuntotres/api/* llegan aquí (ver .htaccess).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/upload.php';

// ─── CABECERAS DE SEGURIDAD ──────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ─── CORS RESTRINGIDO ────────────────────────────────────────────────────────
// Como frontend y API viven en el mismo origen (/dospuntotres/), el navegador
// no hace peticiones CORS reales en uso normal. Solo habilitamos el origen
// exacto por si algún cliente futuro lo necesita, nunca un wildcard.
$origenSolicitado = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origenSolicitado === CORS_ORIGEN_PERMITIDO) {
    header("Access-Control-Allow-Origin: $origenSolicitado");
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ─── RESOLVER RUTA ───────────────────────────────────────────────────────────
$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pos  = strpos($uri, '/api');
$path = $pos !== false ? substr($uri, $pos + 4) : $uri;
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

// ─── HELPERS DE CUERPO ───────────────────────────────────────────────────────
function bodyJson() {
    static $data = null;
    if ($data !== null) return $data;
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
    return $data;
}
function campo($clave, $default = null) {
    if (isset($_POST[$clave])) return $_POST[$clave];
    $json = bodyJson();
    return isset($json[$clave]) ? $json[$clave] : $default;
}

// ─── ENRUTADO ────────────────────────────────────────────────────────────────
try {
    if ($path === '/health') {
        // El endpoint de salud no revela información interna
        jsonOk(['ok' => true]);
    }

    require __DIR__ . '/routes/auth_routes.php';
    require __DIR__ . '/routes/dashboard_routes.php';
    require __DIR__ . '/routes/clientes_routes.php';
    require __DIR__ . '/routes/incidencias_routes.php';
    require __DIR__ . '/routes/revisiones_routes.php';
    require __DIR__ . '/routes/instalaciones_routes.php';
    require __DIR__ . '/routes/buscador_routes.php';
    require __DIR__ . '/routes/logs_routes.php';
    require __DIR__ . '/routes/usuarios_routes.php';

    jsonError(404, 'Ruta no encontrada.');

} catch (Throwable $e) {
    // Registrar el detalle completo en el log del servidor (invisible para el cliente)
    $refId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    error_log("[DPT-$refId] " . get_class($e) . ': ' . $e->getMessage()
        . ' en ' . $e->getFile() . ':' . $e->getLine());

    // Al cliente: solo un código de referencia, nunca la traza interna
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor. Referencia: DPT-' . $refId,
    ]);
    exit;
}
