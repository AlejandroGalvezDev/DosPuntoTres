<?php
/** routes/logs_routes.php */

if ($path === '/logs' && $method === 'GET') {
    $usuario = requireAuth();
    requireRole($usuario, ['admin']);
    $pdo = getDb();

    $sql = 'SELECT * FROM logs WHERE 1=1';
    $params = [];
    if (!empty($_GET['entidad'])) { $sql .= ' AND entidad = :entidad'; $params[':entidad'] = $_GET['entidad']; }
    if (!empty($_GET['accion'])) { $sql .= ' AND accion = :accion'; $params[':accion'] = $_GET['accion']; }
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 1000) : 200;
    $sql .= ' ORDER BY timestamp DESC, id DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonOk($stmt->fetchAll());
}
