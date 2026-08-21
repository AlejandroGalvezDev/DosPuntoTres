<?php
/** routes/auth_routes.php */

// POST /auth/login
if ($path === '/auth/login' && $method === 'POST') {
    $body     = bodyJson();
    $email    = isset($body['email'])    ? trim(strtolower($body['email'])) : '';
    $password = isset($body['password']) ? $body['password']                : '';

    if (!$email || !$password) {
        jsonError(400, 'Email/Usuario y contraseña son obligatorios.');
    }

    // ── Rate limiting por IP ────────────────────────────────────────────────
    $pdo = getDb();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_intentos (
            id  INTEGER PRIMARY KEY AUTOINCREMENT,
            ip  TEXT NOT NULL,
            ts  INTEGER NOT NULL
        );
    ");

    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
    $now = time();

    // Limpiar intentos caducados
    $pdo->prepare('DELETE FROM login_intentos WHERE ts < :limite')
        ->execute([':limite' => $now - RATE_LIMIT_VENTANA]);

    // Contar intentos recientes de esta IP
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_intentos WHERE ip = :ip AND ts > :desde');
    $stmt->execute([':ip' => $ip, ':desde' => $now - RATE_LIMIT_VENTANA]);
    $intentos = (int)$stmt->fetchColumn();

    if ($intentos >= RATE_LIMIT_INTENTOS) {
        registrarLog(null, 'sistema', 'LOGIN_BLOQUEADO', 'auth', null,
            "IP $ip bloqueada tras $intentos intentos fallidos");
        http_response_code(429);
        header('Retry-After: ' . RATE_LIMIT_BLOQUEO);
        echo json_encode([
            'error' => 'Demasiados intentos fallidos. Espera ' . (RATE_LIMIT_BLOQUEO / 60) . ' minutos antes de volver a intentarlo.',
        ]);
        exit;
    }
    // ───────────────────────────────────────────────────────────────────────

    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = :email AND activo = 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        // Registrar el intento fallido
        $pdo->prepare('INSERT INTO login_intentos (ip, ts) VALUES (:ip, :ts)')
            ->execute([':ip' => $ip, ':ts' => $now]);

        registrarLog(null, 'anonimo', 'LOGIN_FALLIDO', 'auth', null,
            "Intento fallido desde IP $ip");

        // Delay aleatorio para dificultar enumeracion de usuarios por tiempo
        usleep(random_int(100000, 300000));

        // Mismo mensaje tanto si el email no existe como si la contraseña es incorrecta
        jsonError(401, 'Credenciales incorrectas.');
    }

    // Login correcto: limpiar historial de fallos de esta IP
    $pdo->prepare('DELETE FROM login_intentos WHERE ip = :ip')->execute([':ip' => $ip]);

    $payload = [
        'id'        => (int)$user['id'],
        'email'     => $user['email'],
        'nombre'    => $user['nombre'],
        'alias_log' => $user['alias_log'],
        'rol'       => $user['rol'],
    ];
    $token = crearToken($payload);

    registrarLog($user['id'], $user['alias_log'], 'LOGIN', 'auth', $user['id'],
        "Inicio de sesion desde IP $ip");

    jsonOk(['token' => $token, 'user' => $payload]);
}

// GET /auth/me
if ($path === '/auth/me' && $method === 'GET') {
    $usuario = requireAuth();
    jsonOk(['user' => $usuario]);
}

// POST /auth/forgot-password (simulado: no hay servidor de correo configurado)
if ($path === '/auth/forgot-password' && $method === 'POST') {
    $body = bodyJson();
    if (empty($body['email'])) jsonError(400, 'Indica un email.');
    // Mismo mensaje independientemente de si el email existe (evita enumeracion)
    jsonOk(['message' => 'Si el email existe en el sistema, recibirás un enlace de recuperación en breve.']);
}
