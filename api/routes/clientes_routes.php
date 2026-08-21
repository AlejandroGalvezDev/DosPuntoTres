<?php
/** routes/clientes_routes.php */

function serializeCliente($pdo, $c) {
    $stmt = $pdo->prepare('SELECT id, filepath FROM cliente_fotos WHERE cliente_id = :id ORDER BY id DESC');
    $stmt->execute([':id' => $c['id']]);
    $fotos = $stmt->fetchAll();
    $c['tiene_llave'] = (bool)$c['tiene_llave'];
    $c['fotos'] = array_map(function ($f) {
        return ['id' => (int)$f['id'], 'url' => '/uploads/' . $f['filepath']];
    }, $fotos);
    return $c;
}

/**
 * Valida que una URL de Maps sea segura.
 * Solo permite dominios conocidos de Google Maps (evita javascript:, data:, etc.)
 */
function validarMapsUrl($url) {
    if (empty($url)) return true; // campo opcional
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $patron = '#^https://(maps\.google\.(com|es)|goo\.gl|maps\.app\.goo\.gl|www\.google\.(com|es)/maps)#i';
    return (bool)preg_match($patron, $url);
}

// GET /clientes?buscar=texto
if ($path === '/clientes' && $method === 'GET') {
    requireAuth();
    $pdo = getDb();
    $buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
    if ($buscar !== '') {
        $like = '%' . $buscar . '%';
        $stmt = $pdo->prepare("SELECT * FROM clientes WHERE CAST(id AS TEXT) LIKE :l1 OR nombre_edificio LIKE :l2 OR direccion LIKE :l3 ORDER BY nombre_edificio ASC LIMIT 50");
        $stmt->execute([':l1' => $like, ':l2' => $like, ':l3' => $like]);
    } else {
        $stmt = $pdo->query('SELECT * FROM clientes ORDER BY id ASC LIMIT 200');
    }
    $rows = $stmt->fetchAll();
    jsonOk(array_map(function ($r) use ($pdo) { return serializeCliente($pdo, $r); }, $rows));
}

// GET /clientes/:id
if (preg_match('#^/clientes/(\d+)$#', $path, $m) && $method === 'GET') {
    requireAuth();
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $m[1]]);
    $row = $stmt->fetch();
    if (!$row) jsonError(404, 'Cliente no encontrado.');
    jsonOk(serializeCliente($pdo, $row));
}

// POST /clientes (solo administradores: alta de cliente maestro)
if ($path === '/clientes' && $method === 'POST') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();
    $body = bodyJson();
    $id     = isset($body['id'])              ? (int)$body['id']                    : null;
    $nombre = isset($body['nombre_edificio']) ? trim($body['nombre_edificio'])       : '';
    if (!$id || !$nombre) jsonError(400, 'El ID de cliente/llave y el nombre del edificio son obligatorios.');

    $colores = ['rojo', 'verde', 'azul', 'naranja', 'amarillo', 'negro', 'blanco'];
    $tieneLlave = !empty($body['tiene_llave']);
    $colorLlave = isset($body['color_llave']) ? $body['color_llave'] : null;
    if ($tieneLlave && $colorLlave && !in_array($colorLlave, $colores, true)) {
        jsonError(400, 'Color de llave inválido. Usa uno de: ' . implode(', ', $colores) . '.');
    }

    // Validar URL de Maps (VUL-15: evitar javascript: u otras URLs peligrosas)
    $mapsUrl = isset($body['maps_url']) ? trim($body['maps_url']) : null;
    if ($mapsUrl && !validarMapsUrl($mapsUrl)) {
        jsonError(400, 'La URL de Google Maps no es válida. Debe comenzar por https://maps.google.com, https://goo.gl o similar.');
    }

    // Validar días de grabación: debe ser un entero entre 1 y 60
    $diasGrabacion = isset($body['dias_grabacion']) ? $body['dias_grabacion'] : null;
    if ($diasGrabacion !== null) {
        $diasGrabacion = (int)$diasGrabacion;
        if ($diasGrabacion < 1 || $diasGrabacion > 60) {
            jsonError(400, 'Los días de grabación deben estar entre 1 y 60.');
        }
    }

    $stmt = $pdo->prepare('SELECT id FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->fetch()) jsonError(409, "Ya existe un cliente con ID/Llave $id.");

    $stmt = $pdo->prepare('
        INSERT INTO clientes (id, nombre_edificio, direccion, maps_url, dias_grabacion, tiene_llave, color_llave, persona_contacto, telefono_contacto)
        VALUES (:id, :nombre, :direccion, :maps, :dias, :tiene_llave, :color, :contacto, :telefono)
    ');
    $stmt->execute([
        ':id'         => $id,
        ':nombre'     => $nombre,
        ':direccion'  => isset($body['direccion']) ? trim($body['direccion']) : null,
        ':maps'       => $mapsUrl ?: null,
        ':dias'       => $diasGrabacion,
        ':tiene_llave'=> $tieneLlave ? 1 : 0,
        ':color'      => $tieneLlave ? $colorLlave : null,
        ':contacto'   => isset($body['persona_contacto'])  ? trim($body['persona_contacto'])  : null,
        ':telefono'   => isset($body['telefono_contacto']) ? trim($body['telefono_contacto']) : null,
    ]);

    registrarLog($usuario['id'], $usuario['alias_log'], 'POST', 'clientes', $id, "Cliente creado: $nombre");

    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    jsonOk(serializeCliente($pdo, $stmt->fetch()), 201);
}

// PUT /clientes/:id (solo administradores)
if (preg_match('#^/clientes/(\d+)$#', $path, $m) && $method === 'PUT') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();
    $id = $m[1];
    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError(404, 'Cliente no encontrado.');

    $body = bodyJson();

    // Validar URL de Maps si se está actualizando
    $mapsUrl = isset($body['maps_url']) ? trim($body['maps_url']) : $row['maps_url'];
    if ($mapsUrl && !validarMapsUrl($mapsUrl)) {
        jsonError(400, 'La URL de Google Maps no es válida. Debe comenzar por https://maps.google.com, https://goo.gl o similar.');
    }

    // Validar días de grabación si se está actualizando
    $diasGrabacion = isset($body['dias_grabacion']) ? (int)$body['dias_grabacion'] : $row['dias_grabacion'];
    if ($diasGrabacion !== null && ($diasGrabacion < 1 || $diasGrabacion > 60)) {
        jsonError(400, 'Los días de grabación deben estar entre 1 y 60.');
    }

    $tieneLlave = isset($body['tiene_llave']) ? (bool)$body['tiene_llave'] : (bool)$row['tiene_llave'];
    $stmt = $pdo->prepare("
        UPDATE clientes SET nombre_edificio = :nombre, direccion = :direccion, maps_url = :maps,
            dias_grabacion = :dias, tiene_llave = :tiene_llave, color_llave = :color,
            persona_contacto = :contacto, telefono_contacto = :telefono, updated_at = datetime('now')
        WHERE id = :id
    ");
    $stmt->execute([
        ':nombre'     => isset($body['nombre_edificio'])  ? trim($body['nombre_edificio'])   : $row['nombre_edificio'],
        ':direccion'  => isset($body['direccion'])         ? trim($body['direccion'])          : $row['direccion'],
        ':maps'       => $mapsUrl ?: null,
        ':dias'       => $diasGrabacion,
        ':tiene_llave'=> $tieneLlave ? 1 : 0,
        ':color'      => $tieneLlave ? (isset($body['color_llave']) ? $body['color_llave'] : $row['color_llave']) : null,
        ':contacto'   => isset($body['persona_contacto'])  ? trim($body['persona_contacto'])  : $row['persona_contacto'],
        ':telefono'   => isset($body['telefono_contacto']) ? trim($body['telefono_contacto']) : $row['telefono_contacto'],
        ':id'         => $id,
    ]);

    registrarLog($usuario['id'], $usuario['alias_log'], 'POST', 'clientes', $id, 'Cliente actualizado');

    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    jsonOk(serializeCliente($pdo, $stmt->fetch()));
}

// POST /clientes/:id/fotos (solo administradores)
if (preg_match('#^/clientes/(\d+)/fotos$#', $path, $m) && $method === 'POST') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();
    $id = $m[1];
    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if (!$stmt->fetch()) jsonError(404, 'Cliente no encontrado.');

    $archivos = guardarFotosSubidas('fotos');
    $ins = $pdo->prepare('INSERT INTO cliente_fotos (cliente_id, filepath) VALUES (:id, :fp)');
    foreach ($archivos as $fp) $ins->execute([':id' => $id, ':fp' => $fp]);

    registrarLog($usuario['id'], $usuario['alias_log'], 'POST', 'clientes', $id, count($archivos) . ' foto(s) añadida(s)');

    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    jsonOk(serializeCliente($pdo, $stmt->fetch()), 201);
}

// DELETE /clientes/:id (solo admin)
if (preg_match('#^/clientes/(\d+)$#', $path, $m) && $method === 'DELETE') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();
    $id = $m[1];
    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError(404, 'Cliente no encontrado.');

    $pdo->prepare('DELETE FROM clientes WHERE id = :id')->execute([':id' => $id]);
    registrarLog($usuario['id'], $usuario['alias_log'], 'DELETE', 'clientes', $id, "Cliente eliminado: {$row['nombre_edificio']}");
    jsonOk(['ok' => true]);
}


// GET /clientes?buscar=texto
if ($path === '/clientes' && $method === 'GET') {
    requireAuth();
    $pdo = getDb();
    $buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
    if ($buscar !== '') {
        $like = '%' . $buscar . '%';
        $stmt = $pdo->prepare("SELECT * FROM clientes WHERE CAST(id AS TEXT) LIKE :l1 OR nombre_edificio LIKE :l2 OR direccion LIKE :l3 ORDER BY nombre_edificio ASC LIMIT 50");
        $stmt->execute([':l1' => $like, ':l2' => $like, ':l3' => $like]);
    } else {
        $stmt = $pdo->query('SELECT * FROM clientes ORDER BY id ASC LIMIT 200');
    }
    $rows = $stmt->fetchAll();
    jsonOk(array_map(function ($r) use ($pdo) { return serializeCliente($pdo, $r); }, $rows));
}

// GET /clientes/:id
if (preg_match('#^/clientes/(\d+)$#', $path, $m) && $method === 'GET') {
    requireAuth();
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $m[1]]);
    $row = $stmt->fetch();
    if (!$row) jsonError(404, 'Cliente no encontrado.');
    jsonOk(serializeCliente($pdo, $row));
}

// POST /clientes (solo administradores: alta de cliente maestro)
if ($path === '/clientes' && $method === 'POST') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();
    $body = bodyJson();
    $id = isset($body['id']) ? (int)$body['id'] : null;
    $nombre = isset($body['nombre_edificio']) ? trim($body['nombre_edificio']) : '';
    if (!$id || !$nombre) jsonError(400, 'El ID de cliente/llave y el nombre del edificio son obligatorios.');

    $colores = ['rojo', 'verde', 'azul', 'naranja', 'amarillo', 'negro', 'blanco'];
    $tieneLlave = !empty($body['tiene_llave']);
    $colorLlave = isset($body['color_llave']) ? $body['color_llave'] : null;
    if ($tieneLlave && $colorLlave && !in_array($colorLlave, $colores, true)) {
        jsonError(400, 'Color de llave inválido. Usa uno de: ' . implode(', ', $colores) . '.');
    }

    $stmt = $pdo->prepare('SELECT id FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->fetch()) jsonError(409, "Ya existe un cliente con ID/Llave $id.");

    $stmt = $pdo->prepare('
        INSERT INTO clientes (id, nombre_edificio, direccion, maps_url, dias_grabacion, tiene_llave, color_llave, persona_contacto, telefono_contacto)
        VALUES (:id, :nombre, :direccion, :maps, :dias, :tiene_llave, :color, :contacto, :telefono)
    ');
    $stmt->execute([
        ':id' => $id, ':nombre' => $nombre,
        ':direccion' => isset($body['direccion']) ? $body['direccion'] : null,
        ':maps' => isset($body['maps_url']) ? $body['maps_url'] : null,
        ':dias' => isset($body['dias_grabacion']) ? $body['dias_grabacion'] : null,
        ':tiene_llave' => $tieneLlave ? 1 : 0,
        ':color' => $tieneLlave ? $colorLlave : null,
        ':contacto' => isset($body['persona_contacto']) ? $body['persona_contacto'] : null,
        ':telefono' => isset($body['telefono_contacto']) ? $body['telefono_contacto'] : null,
    ]);

    registrarLog($usuario['id'], $usuario['alias_log'], 'POST', 'clientes', $id, "Cliente creado: $nombre");

    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    jsonOk(serializeCliente($pdo, $stmt->fetch()), 201);
}

// PUT /clientes/:id (solo administradores)
if (preg_match('#^/clientes/(\d+)$#', $path, $m) && $method === 'PUT') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();
    $id = $m[1];
    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError(404, 'Cliente no encontrado.');

    $body = bodyJson();
    $tieneLlave = isset($body['tiene_llave']) ? (bool)$body['tiene_llave'] : (bool)$row['tiene_llave'];
    $stmt = $pdo->prepare("
        UPDATE clientes SET nombre_edificio = :nombre, direccion = :direccion, maps_url = :maps,
            dias_grabacion = :dias, tiene_llave = :tiene_llave, color_llave = :color,
            persona_contacto = :contacto, telefono_contacto = :telefono, updated_at = datetime('now')
        WHERE id = :id
    ");
    $stmt->execute([
        ':nombre' => isset($body['nombre_edificio']) ? $body['nombre_edificio'] : $row['nombre_edificio'],
        ':direccion' => isset($body['direccion']) ? $body['direccion'] : $row['direccion'],
        ':maps' => isset($body['maps_url']) ? $body['maps_url'] : $row['maps_url'],
        ':dias' => isset($body['dias_grabacion']) ? $body['dias_grabacion'] : $row['dias_grabacion'],
        ':tiene_llave' => $tieneLlave ? 1 : 0,
        ':color' => $tieneLlave ? (isset($body['color_llave']) ? $body['color_llave'] : $row['color_llave']) : null,
        ':contacto' => isset($body['persona_contacto']) ? $body['persona_contacto'] : $row['persona_contacto'],
        ':telefono' => isset($body['telefono_contacto']) ? $body['telefono_contacto'] : $row['telefono_contacto'],
        ':id' => $id,
    ]);

    registrarLog($usuario['id'], $usuario['alias_log'], 'POST', 'clientes', $id, 'Cliente actualizado');

    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    jsonOk(serializeCliente($pdo, $stmt->fetch()));
}

// POST /clientes/:id/fotos (solo administradores)
if (preg_match('#^/clientes/(\d+)/fotos$#', $path, $m) && $method === 'POST') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();
    $id = $m[1];
    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if (!$stmt->fetch()) jsonError(404, 'Cliente no encontrado.');

    $archivos = guardarFotosSubidas('fotos');
    $ins = $pdo->prepare('INSERT INTO cliente_fotos (cliente_id, filepath) VALUES (:id, :fp)');
    foreach ($archivos as $fp) $ins->execute([':id' => $id, ':fp' => $fp]);

    registrarLog($usuario['id'], $usuario['alias_log'], 'POST', 'clientes', $id, count($archivos) . ' foto(s) añadida(s)');

    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    jsonOk(serializeCliente($pdo, $stmt->fetch()), 201);
}

// DELETE /clientes/:id (solo admin)
if (preg_match('#^/clientes/(\d+)$#', $path, $m) && $method === 'DELETE') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();
    $id = $m[1];
    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError(404, 'Cliente no encontrado.');

    $pdo->prepare('DELETE FROM clientes WHERE id = :id')->execute([':id' => $id]);
    registrarLog($usuario['id'], $usuario['alias_log'], 'DELETE', 'clientes', $id, "Cliente eliminado: {$row['nombre_edificio']}");
    jsonOk(['ok' => true]);
}
