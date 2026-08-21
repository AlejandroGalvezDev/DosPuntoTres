<?php
/**
 * config.php
 * Configuración central de la aplicación.
 *
 * ⚠️  ANTES DE PONER EN PRODUCCIÓN:
 *   1. Cambia SECRET_KEY por una cadena larga y aleatoria propia.
 *      Generador: https://randomkeygen.com/ (sección "CodeIgniter Encryption Keys")
 *   2. Borra api/seed.php del servidor en cuanto hayas cargado los datos.
 */

// ─── SEGURIDAD: clave de firma de tokens ────────────────────────────────────
// ¡CAMBIA ESTO! Si esta clave es conocida, los tokens de sesión pueden forjarse.
define('SECRET_KEY', 'CAMBIA-ESTA-CLAVE-POR-UNA-ALEATORIA-Y-LARGA-1234567890');

// ─── RUTAS ───────────────────────────────────────────────────────────────────
define('BASE_DIR',   __DIR__);
define('DATA_DIR',   dirname(__DIR__) . '/data');
define('UPLOADS_DIR', dirname(__DIR__) . '/uploads');
define('DB_PATH',    DATA_DIR . '/dospuntotres.sqlite');

// ─── SESIÓN ──────────────────────────────────────────────────────────────────
// Duración del token de sesión. 8 horas (reducido de 12 para menor ventana de ataque).
define('TOKEN_TTL', 8 * 3600);

// ─── UPLOADS ─────────────────────────────────────────────────────────────────
// Tamaño máximo por foto: 5 MB (reducido de 8 MB para limitar DoS por disco).
define('MAX_FOTO_BYTES', 5 * 1024 * 1024);
// Espacio libre mínimo que debe quedar en disco antes de aceptar una foto.
define('MIN_DISK_FREE_BYTES', 100 * 1024 * 1024); // 100 MB

// ─── RATE LIMITING (login) ───────────────────────────────────────────────────
// Máximo de intentos de login fallidos por IP antes de bloquear.
define('RATE_LIMIT_INTENTOS', 5);
// Ventana de tiempo en segundos para contar los intentos fallidos (15 min).
define('RATE_LIMIT_VENTANA', 15 * 60);
// Tiempo de bloqueo en segundos tras superar el límite (30 min).
define('RATE_LIMIT_BLOQUEO', 30 * 60);

// ─── CORS ────────────────────────────────────────────────────────────────────
// Origen exacto del frontend. Si el dominio cambia, actualizar aquí.
// Usar 'null' desactiva CORS completamente (recomendado cuando API y frontend
// están en el mismo origen, como es el caso de /dospuntotres/).
define('CORS_ORIGEN_PERMITIDO', 'https://www.webnoticiero.es');

// ─── PHP: ocultar información del servidor ───────────────────────────────────
ini_set('expose_php', '0');
header_remove('X-Powered-By');

// ─── ERRORES ─────────────────────────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', '0');   // nunca mostrar errores PHP en pantalla en producción
ini_set('log_errors', '1');       // sí guardarlos en el log del servidor

date_default_timezone_set('Europe/Madrid');

// ─── CREAR CARPETAS SI NO EXISTEN ────────────────────────────────────────────
if (!is_dir(DATA_DIR))   mkdir(DATA_DIR,    0750, true);
if (!is_dir(UPLOADS_DIR)) mkdir(UPLOADS_DIR, 0750, true);
