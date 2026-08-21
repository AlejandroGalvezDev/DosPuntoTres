<?php
/** routes/incidencias_routes.php */

function serializeIncidencia($pdo, $row) {
    $stmt = $pdo->prepare('SELECT id, fase, filepath FROM incidencia_fotos WHERE incidencia_id = :id ORDER BY id ASC');
    $stmt->execute([':id' => $row['id']]);
    $fotos = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT id, nombre_edificio, direccion, maps_url, dias_grabacion, tiene_llave, color_llave FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $row['cliente_id']]);
    $cliente = $stmt->fetch() ?: null;
    if ($cliente) $cliente['tiene_llave'] = (bool)$cliente['tiene_llave'];

    $tecnico = null;
    if (!empty($row['tecnico_resuelve_id'])) {
        $stmt = $pdo->prepare('SELECT id, nombre FROM usuarios WHERE id = :id');
        $stmt->execute([':id' => $row['tecnico_resuelve_id']]);
        $tecnico = $stmt->fetch() ?: null;
    }

    // "Administrador que crea incidencia" (BBDD.md): quién la abrió, siempre un admin.
    $creador = null;
    if (!empty($row['creado_por'])) {
        $stmt = $pdo->prepare('SELECT id, nombre FROM usuarios WHERE id = :id');
        $stmt->execute([':id' => $row['creado_por']]);
        $creador = $stmt->fetch() ?: null;
    }

    $row['cliente'] = $cliente;
    $row['tecnico_resuelve'] = $tecnico;
    $row['creado_por_usuario'] = $creador;
    $row['fotos'] = array_map(function ($f) {
        return ['id' => (int)$f['id'], 'fase' => $f['fase'], 'url' => '/uploads/' . $f['filepath']];
    }, $fotos);
    return $row;
}

$CATEGORIAS_AVERIA = ['disco_duro', 'grabador', 'camara', 'otros'];
$IMPORTANCIAS = ['alta', 'media', 'baja'];

// GET /incidencias/:tipo?estado=&q=
if (preg_match('#^/incidencias/(grabacion|averia)$#', $path, $m) && $method === 'GET') {
    requireAuth();
    $tipo = $m[1];
    $pdo = getDb();
    $estado = isset($_GET['estado']) ? $_GET['estado'] : null;
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';

    $sql = "SELECT i.*, c.nombre_edificio, c.direccion, c.maps_url, c.tiene_llave, c.color_llave
            FROM incidencias i JOIN clientes c ON c.id = i.cliente_id WHERE i.tipo = :tipo";
    $params = [':tipo' => $tipo];

    if ($estado === 'pendiente' || $estado === 'resuelta') {
        $sql .= ' AND i.estado = :estado';
        $params[':estado'] = $estado;
    }
    if ($q !== '') {
        $sql .= ' AND (c.nombre_edificio LIKE :q1 OR CAST(c.id AS TEXT) LIKE :q2 OR i.numero_atestado LIKE :q3 OR i.fecha_incidente LIKE :q4 OR i.fecha_resolucion LIKE :q5)';
        $like = '%' . $q . '%';
        $params[':q1'] = $like; $params[':q2'] = $like; $params[':q3'] = $like; $params[':q4'] = $like; $params[':q5'] = $like;
    }

    // Averías: "se ordena por resuelta/no resuelta e importancia" (BBDD.md).
    // La separación resuelta/no-resuelta ya viene dada por las pestañas (parámetro estado);
    // dentro de cada pestaña, las averías se ordenan por importancia y luego por fecha.
    if ($tipo === 'averia') {
        $ordenImportancia = "CASE i.importancia WHEN 'alta' THEN 0 WHEN 'media' THEN 1 WHEN 'baja' THEN 2 ELSE 3 END";
        $sql .= $estado === 'resuelta'
            ? " ORDER BY $ordenImportancia, i.fecha_resolucion DESC"
            : " ORDER BY $ordenImportancia, i.fecha_incidente ASC";
    } else {
        $sql .= $estado === 'resuelta' ? ' ORDER BY i.fecha_resolucion DESC' : ' ORDER BY i.fecha_incidente ASC';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    jsonOk(array_map(function ($r) use ($pdo) { return serializeIncidencia($pdo, $r); }, $rows));
}

// GET /incidencias/:tipo/:id
if (preg_match('#^/incidencias/(grabacion|averia)/(\d+)$#', $path, $m) && $method === 'GET') {
    requireAuth();
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT * FROM incidencias WHERE id = :id AND tipo = :tipo');
    $stmt->execute([':id' => $m[2], ':tipo' => $m[1]]);
    $row = $stmt->fetch();
    if (!$row) jsonError(404, 'Registro no encontrado.');
    jsonOk(serializeIncidencia($pdo, $row));
}

// POST /incidencias/:tipo  — SOLO ADMINISTRADORES (apertura de incidencia)
if (preg_match('#^/incidencias/(grabacion|averia)$#', $path, $m) && $method === 'POST') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $tipo = $m[1];
    $pdo = getDb();

    $clienteId = campo('cliente_id');
    $suceso = campo('suceso');
    $fechaIncidente = campo('fecha_incidente');
    if (!$clienteId || !$suceso || !$fechaIncidente) {
        jsonError(400, 'Cliente, suceso y fecha del incidente son obligatorios.');
    }
    $stmt = $pdo->prepare('SELECT id FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $clienteId]);
    if (!$stmt->fetch()) jsonError(404, "No existe ningún cliente con ID $clienteId. Da de alta el cliente primero.");

    $numeroAtestado = null;
    $categoriaAveria = null;
    $importancia = null;

    if ($tipo === 'grabacion') {
        $numeroAtestado = campo('numero_atestado');
        if (!$numeroAtestado || !trim($numeroAtestado)) {
            jsonError(400, 'El número de atestado es obligatorio al crear una Grabación.');
        }
    } else { // averia
        $categoriaAveria = campo('categoria_averia');
        $importancia = campo('importancia');
        global $CATEGORIAS_AVERIA, $IMPORTANCIAS;
        if (!$categoriaAveria || !in_array($categoriaAveria, $CATEGORIAS_AVERIA, true)) {
            jsonError(400, 'La categoría de la avería es obligatoria (disco duro, grabador, cámara u otros).');
        }
        if (!$importancia || !in_array($importancia, $IMPORTANCIAS, true)) {
            jsonError(400, 'La importancia de la avería es obligatoria (alta, media o baja).');
        }
    }

    $stmt = $pdo->prepare('
        INSERT INTO incidencias (tipo, cliente_id, suceso, fecha_incidente, numero_atestado, categoria_averia, importancia, creado_por)
        VALUES (:tipo, :cid, :suceso, :fecha, :atestado, :categoria, :importancia, :creador)
    ');
    $stmt->execute([
        ':tipo' => $tipo, ':cid' => $clienteId, ':suceso' => $suceso, ':fecha' => $fechaIncidente,
        ':atestado' => $numeroAtestado, ':categoria' => $categoriaAveria, ':importancia' => $importancia,
        ':creador' => $usuario['id'],
    ]);
    $nuevoId = $pdo->lastInsertId();

    $archivos = guardarFotosSubidas('fotos');
    $ins = $pdo->prepare("INSERT INTO incidencia_fotos (incidencia_id, fase, filepath) VALUES (:id, 'inicial', :fp)");
    foreach ($archivos as $fp) $ins->execute([':id' => $nuevoId, ':fp' => $fp]);

    $etiqueta = $tipo === 'grabacion' ? 'Grabación' : 'Avería';
    registrarLog($usuario['id'], $usuario['alias_log'], 'POST', 'incidencias', $nuevoId, "$etiqueta creada por administrador para cliente $clienteId: $suceso");

    $stmt = $pdo->prepare('SELECT * FROM incidencias WHERE id = :id');
    $stmt->execute([':id' => $nuevoId]);
    jsonOk(serializeIncidencia($pdo, $stmt->fetch()), 201);
}

// POST /incidencias/:tipo/:id/resolver — admin o técnico (resolución en campo)
if (preg_match('#^/incidencias/(grabacion|averia)/(\d+)/resolver$#', $path, $m) && $method === 'POST') {
    $usuario = requireAuth();
    $tipo = $m[1]; $id = $m[2];
    $pdo = getDb();

    $stmt = $pdo->prepare('SELECT * FROM incidencias WHERE id = :id AND tipo = :tipo');
    $stmt->execute([':id' => $id, ':tipo' => $tipo]);
    $row = $stmt->fetch();
    if (!$row) jsonError(404, 'Registro no encontrado.');
    if ($row['estado'] === 'resuelta') jsonError(409, 'Este registro ya está resuelto.');

    $resultado = campo('resultado');
    $descripcion = campo('descripcion_resolucion');
    $comentarioX = campo('comentario_x');

    if (!in_array($resultado, ['V', 'X'], true)) {
        jsonError(400, "El resultado debe ser 'V' (resuelto) o 'X' (no resuelto).");
    }
    if ($resultado === 'V' && (!$descripcion || !trim($descripcion))) {
        jsonError(400, 'La descripción detallada es obligatoria al marcar V.');
    }
    if ($resultado === 'X' && (!$comentarioX || !trim($comentarioX))) {
        jsonError(400, 'Debes justificar el motivo (comentario) al marcar X.');
    }

    $stmt = $pdo->prepare("
        UPDATE incidencias SET estado='resuelta', resultado=:res, descripcion_resolucion=:desc,
            comentario_x=:comx, tecnico_resuelve_id=:tec, fecha_resolucion=datetime('now')
        WHERE id = :id
    ");
    $stmt->execute([
        ':res' => $resultado,
        ':desc' => $resultado === 'V' ? $descripcion : null,
        ':comx' => $resultado === 'X' ? $comentarioX : null,
        ':tec' => $usuario['id'],
        ':id' => $id,
    ]);

    $archivos = guardarFotosSubidas('fotos');
    $ins = $pdo->prepare("INSERT INTO incidencia_fotos (incidencia_id, fase, filepath) VALUES (:id, 'resolucion', :fp)");
    foreach ($archivos as $fp) $ins->execute([':id' => $id, ':fp' => $fp]);

    $etiqueta = $tipo === 'grabacion' ? 'Grabación' : 'Avería';
    registrarLog($usuario['id'], $usuario['alias_log'], 'POST', 'incidencias', $id, "$etiqueta resuelta ($resultado) por {$usuario['alias_log']}");

    $stmt = $pdo->prepare('SELECT * FROM incidencias WHERE id = :id');
    $stmt->execute([':id' => $id]);
    jsonOk(serializeIncidencia($pdo, $stmt->fetch()));
}

// DELETE /incidencias/:tipo/:id (solo admin)
if (preg_match('#^/incidencias/(grabacion|averia)/(\d+)$#', $path, $m) && $method === 'DELETE') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT * FROM incidencias WHERE id = :id AND tipo = :tipo');
    $stmt->execute([':id' => $m[2], ':tipo' => $m[1]]);
    if (!$stmt->fetch()) jsonError(404, 'Registro no encontrado.');

    $pdo->prepare('DELETE FROM incidencias WHERE id = :id')->execute([':id' => $m[2]]);
    $etiqueta = $m[1] === 'grabacion' ? 'Grabación' : 'Avería';
    registrarLog($usuario['id'], $usuario['alias_log'], 'DELETE', 'incidencias', $m[2], "$etiqueta eliminada");
    jsonOk(['ok' => true]);
}
