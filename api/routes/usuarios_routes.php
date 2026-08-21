<?php
/** routes/usuarios_routes.php */

function serializeUsuario($u) {
    unset($u['password_hash']);
    $u['activo'] = (bool)$u['activo'];
    return $u;
}

if ($path === '/usuarios' && $method === 'GET') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();
    $rows = $pdo->query('SELECT * FROM usuarios ORDER BY id ASC')->fetchAll();
    jsonOk(array_map('serializeUsuario', $rows));
}

if ($path === '/usuarios' && $method === 'POST') {
    $usuarioActual = requireAuth();
    requireRole($usuarioActual, ['admin']);
    $pdo = getDb();
    $body = bodyJson();

    $email = isset($body['email']) ? trim(strtolower($body['email'])) : '';
    $password = isset($body['password']) ? $body['password'] : '';
    $nombre = isset($body['nombre']) ? $body['nombre'] : '';
    $rol = isset($body['rol']) ? $body['rol'] : '';
    $aliasLog = !empty($body['alias_log']) ? $body['alias_log'] : $nombre;

    if (!$email || !$password || !$nombre || !$rol) jsonError(400, 'Email, contraseña, nombre y rol son obligatorios.');
    if (!in_array($rol, ['admin', 'tecnico'], true)) jsonError(400, "El rol debe ser 'admin' o 'tecnico'.");

    $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email');
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) jsonError(409, 'Ya existe un usuario con ese email.');

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO usuarios (email, password_hash, nombre, alias_log, rol) VALUES (:email, :hash, :nombre, :alias, :rol)');
    $stmt->execute([':email' => $email, ':hash' => $hash, ':nombre' => $nombre, ':alias' => $aliasLog, ':rol' => $rol]);
    $nuevoId = $pdo->lastInsertId();

    registrarLog($usuarioActual['id'], $usuarioActual['alias_log'], 'POST', 'usuarios', $nuevoId, "Usuario creado: $email ($rol)");

    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $nuevoId]);
    jsonOk(serializeUsuario($stmt->fetch()), 201);
}

if (preg_match('#^/usuarios/(\d+)$#', $path, $m) && $method === 'DELETE') {
    $usuarioActual = requireAuth();
    requireRole($usuarioActual, ['admin']);
    if ((int)$m[1] === (int)$usuarioActual['id']) jsonError(400, 'No puedes eliminar tu propio usuario.');

    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $m[1]]);
    $row = $stmt->fetch();
    if (!$row) jsonError(404, 'Usuario no encontrado.');

    $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute([':id' => $m[1]]);
    registrarLog($usuarioActual['id'], $usuarioActual['alias_log'], 'DELETE', 'usuarios', $m[1], "Usuario eliminado: {$row['email']}");
    jsonOk(['ok' => true]);
}
