<?php
/** routes/instalaciones_routes.php — Módulo exclusivo de Administración */

function serializeInstalacion($pdo, $row) {
    $stmt = $pdo->prepare('SELECT id, fase, filepath FROM instalacion_fotos WHERE instalacion_id = :id ORDER BY id ASC');
    $stmt->execute([':id' => $row['id']]);
    $fotos = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $row['cliente_id']]);
    $cliente = $stmt->fetch() ?: null;
    if ($cliente) $cliente['tiene_llave'] = (bool)$cliente['tiene_llave'];

    $creador = null;
    if (!empty($row['creado_por'])) {
        $stmt = $pdo->prepare('SELECT id, nombre FROM usuarios WHERE id = :id');
        $stmt->execute([':id' => $row['creado_por']]);
        $creador = $stmt->fetch() ?: null;
    }

    $row['cliente'] = $cliente;
    $row['creado_por_usuario'] = $creador;
    $row['fotos'] = array_map(function ($f) {
        return ['id' => (int)$f['id'], 'fase' => $f['fase'], 'url' => '/uploads/' . $f['filepath']];
    }, $fotos);
    return $row;
}

// GET /instalaciones?estado=&q=  (solo admin)
if ($path === '/instalaciones' && $method === 'GET') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();
    $estado = isset($_GET['estado']) ? $_GET['estado'] : null;
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';

    $sql = 'SELECT i.* FROM instalaciones i JOIN clientes c ON c.id = i.cliente_id WHERE 1=1';
    $params = [];
    if ($estado === 'pendiente' || $estado === 'terminada') { $sql .= ' AND i.estado = :e'; $params[':e'] = $estado; }
    if ($q !== '') {
        $sql .= ' AND (c.nombre_edificio LIKE :q1 OR c.direccion LIKE :q2 OR CAST(c.id AS TEXT) LIKE :q3)';
        $like = '%' . $q . '%';
        $params[':q1'] = $like; $params[':q2'] = $like; $params[':q3'] = $like;
    }
    $sql .= ' ORDER BY c.id ASC'; // "se ordena por número [de cliente]"
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    jsonOk(array_map(function ($r) use ($pdo) { return serializeInstalacion($pdo, $r); }, $rows));
}

// GET /instalaciones/:id (solo admin)
if (preg_match('#^/instalaciones/(\d+)$#', $path, $m) && $method === 'GET') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT * FROM instalaciones WHERE id = :id');
    $stmt->execute([':id' => $m[1]]);
    $row = $stmt->fetch();
    if (!$row) jsonError(404, 'Instalación no encontrada.');
    jsonOk(serializeInstalacion($pdo, $row));
}

// POST /instalaciones (solo admin) — requiere un cliente maestro ya existente;
// las fotos adjuntas aquí son las de "presupuesto".
if ($path === '/instalaciones' && $method === 'POST') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();

    $clienteId = campo('cliente_id');
    if (!$clienteId) jsonError(400, 'Selecciona (o da de alta primero) el cliente de esta instalación.');
    $stmt = $pdo->prepare('SELECT id FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $clienteId]);
    if (!$stmt->fetch()) jsonError(404, "No existe ningún cliente con ID $clienteId.");

    $stmt = $pdo->prepare('
        INSERT INTO instalaciones (cliente_id, tecnico_nombre, mas_informacion, fecha_instalacion, creado_por)
        VALUES (:cid, :tecnico, :info, :fecha, :creador)
    ');
    $stmt->execute([
        ':cid' => $clienteId,
        ':tecnico' => campo('tecnico_nombre'),
        ':info' => campo('mas_informacion'),
        ':fecha' => campo('fecha_instalacion') ?: null,
        ':creador' => $usuario['id'],
    ]);
    $nuevoId = $pdo->lastInsertId();

    $archivos = guardarFotosSubidas('fotos');
    $ins = $pdo->prepare("INSERT INTO instalacion_fotos (instalacion_id, fase, filepath) VALUES (:id, 'presupuesto', :fp)");
    foreach ($archivos as $fp) $ins->execute([':id' => $nuevoId, ':fp' => $fp]);

    registrarLog($usuario['id'], $usuario['alias_log'], 'POST', 'instalaciones', $nuevoId, "Instalación creada para cliente $clienteId");

    $stmt = $pdo->prepare('SELECT * FROM instalaciones WHERE id = :id');
    $stmt->execute([':id' => $nuevoId]);
    jsonOk(serializeInstalacion($pdo, $stmt->fetch()), 201);
}

// POST /instalaciones/:id/finalizar (solo admin) — fotos de "finalizada" obligatorias
if (preg_match('#^/instalaciones/(\d+)/finalizar$#', $path, $m) && $method === 'POST') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();
    $id = $m[1];
    $stmt = $pdo->prepare('SELECT * FROM instalaciones WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError(404, 'Instalación no encontrada.');

    $archivos = guardarFotosSubidas('fotos');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM instalacion_fotos WHERE instalacion_id = :id AND fase = 'finalizada'");
    $stmt->execute([':id' => $id]);
    $fotosExistentes = (int)$stmt->fetchColumn();

    if (count($archivos) === 0 && $fotosExistentes === 0) {
        jsonError(400, 'Debes adjuntar al menos una fotografía de la instalación finalizada.');
    }

    $ins = $pdo->prepare("INSERT INTO instalacion_fotos (instalacion_id, fase, filepath) VALUES (:id, 'finalizada', :fp)");
    foreach ($archivos as $fp) $ins->execute([':id' => $id, ':fp' => $fp]);

    $stmt = $pdo->prepare("
        UPDATE instalaciones SET estado='terminada',
            mas_informacion = COALESCE(:info, mas_informacion),
            tecnico_nombre = COALESCE(:tecnico, tecnico_nombre)
        WHERE id = :id
    ");
    $stmt->execute([':info' => campo('mas_informacion'), ':tecnico' => campo('tecnico_nombre'), ':id' => $id]);

    registrarLog($usuario['id'], $usuario['alias_log'], 'POST', 'instalaciones', $id, 'Instalación finalizada');

    $stmt = $pdo->prepare('SELECT * FROM instalaciones WHERE id = :id');
    $stmt->execute([':id' => $id]);
    jsonOk(serializeInstalacion($pdo, $stmt->fetch()));
}

// DELETE /instalaciones/:id (solo admin)
if (preg_match('#^/instalaciones/(\d+)$#', $path, $m) && $method === 'DELETE') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT * FROM instalaciones WHERE id = :id');
    $stmt->execute([':id' => $m[1]]);
    if (!$stmt->fetch()) jsonError(404, 'Instalación no encontrada.');
    $pdo->prepare('DELETE FROM instalaciones WHERE id = :id')->execute([':id' => $m[1]]);
    registrarLog($usuario['id'], $usuario['alias_log'], 'DELETE', 'instalaciones', $m[1], 'Instalación eliminada');
    jsonOk(['ok' => true]);
}
