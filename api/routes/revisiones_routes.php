<?php
/** routes/revisiones_routes.php — Solo admin programa; técnico y admin marcan como realizada */

function serializeRevision($pdo, $row) {
    $stmt = $pdo->prepare('SELECT id, nombre_edificio, direccion, maps_url FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $row['cliente_id']]);
    $row['cliente'] = $stmt->fetch() ?: null;

    $row['tecnico'] = null;
    if (!empty($row['tecnico_id'])) {
        $stmt = $pdo->prepare('SELECT id, nombre FROM usuarios WHERE id = :id');
        $stmt->execute([':id' => $row['tecnico_id']]);
        $row['tecnico'] = $stmt->fetch() ?: null;
    }

    $row['creado_por_usuario'] = null;
    if (!empty($row['creado_por'])) {
        $stmt = $pdo->prepare('SELECT id, nombre FROM usuarios WHERE id = :id');
        $stmt->execute([':id' => $row['creado_por']]);
        $row['creado_por_usuario'] = $stmt->fetch() ?: null;
    }
    return $row;
}

// GET /revisiones?estado= -> cualquier autenticado
if ($path === '/revisiones' && $method === 'GET') {
    requireAuth();
    $pdo = getDb();
    $estado = isset($_GET['estado']) ? $_GET['estado'] : null;
    if ($estado === 'pendiente' || $estado === 'realizada') {
        $stmt = $pdo->prepare('SELECT * FROM revisiones WHERE estado = :e ORDER BY fecha_programada ASC');
        $stmt->execute([':e' => $estado]);
    } else {
        $stmt = $pdo->query('SELECT * FROM revisiones ORDER BY fecha_programada ASC');
    }
    $rows = $stmt->fetchAll();
    jsonOk(array_map(function ($r) use ($pdo) { return serializeRevision($pdo, $r); }, $rows));
}

// POST /revisiones -> SOLO ADMIN (programar una revisión, igual que Grabaciones/Averías/Instalaciones)
if ($path === '/revisiones' && $method === 'POST') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();
    $clienteId = campo('cliente_id');
    $fecha = campo('fecha_programada');
    $notas = campo('notas');
    if (!$clienteId || !$fecha) jsonError(400, 'Cliente y fecha programada son obligatorios.');

    $stmt = $pdo->prepare('SELECT id FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $clienteId]);
    if (!$stmt->fetch()) jsonError(404, "No existe ningún cliente con ID $clienteId.");

    $stmt = $pdo->prepare('INSERT INTO revisiones (cliente_id, notas, fecha_programada, creado_por) VALUES (:cid, :notas, :fecha, :creador)');
    $stmt->execute([':cid' => $clienteId, ':notas' => $notas, ':fecha' => $fecha, ':creador' => $usuario['id']]);
    $nuevoId = $pdo->lastInsertId();

    registrarLog($usuario['id'], $usuario['alias_log'], 'POST', 'revisiones', $nuevoId, "Revisión programada por administrador para cliente $clienteId");

    $stmt = $pdo->prepare('SELECT * FROM revisiones WHERE id = :id');
    $stmt->execute([':id' => $nuevoId]);
    jsonOk(serializeRevision($pdo, $stmt->fetch()), 201);
}

// POST /revisiones/:id/realizar -> admin o técnico (resolución en campo, igual que V/X)
if (preg_match('#^/revisiones/(\d+)/realizar$#', $path, $m) && $method === 'POST') {
    $usuario = requireAuth();
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT * FROM revisiones WHERE id = :id');
    $stmt->execute([':id' => $m[1]]);
    $row = $stmt->fetch();
    if (!$row) jsonError(404, 'Revisión no encontrada.');
    if ($row['estado'] === 'realizada') jsonError(409, 'Esta revisión ya está marcada como realizada.');

    $notas = campo('notas');
    $stmt = $pdo->prepare("UPDATE revisiones SET estado='realizada', tecnico_id=:tec, fecha_realizada=datetime('now'), notas=COALESCE(:notas, notas) WHERE id=:id");
    $stmt->execute([':tec' => $usuario['id'], ':notas' => $notas, ':id' => $m[1]]);

    registrarLog($usuario['id'], $usuario['alias_log'], 'POST', 'revisiones', $m[1], "Revisión marcada como realizada por {$usuario['alias_log']}");

    $stmt = $pdo->prepare('SELECT * FROM revisiones WHERE id = :id');
    $stmt->execute([':id' => $m[1]]);
    jsonOk(serializeRevision($pdo, $stmt->fetch()));
}

// DELETE /revisiones/:id -> solo admin
if (preg_match('#^/revisiones/(\d+)$#', $path, $m) && $method === 'DELETE') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT * FROM revisiones WHERE id = :id');
    $stmt->execute([':id' => $m[1]]);
    if (!$stmt->fetch()) jsonError(404, 'Revisión no encontrada.');
    $pdo->prepare('DELETE FROM revisiones WHERE id = :id')->execute([':id' => $m[1]]);
    registrarLog($usuario['id'], $usuario['alias_log'], 'DELETE', 'revisiones', $m[1], 'Revisión eliminada');
    jsonOk(['ok' => true]);
}
