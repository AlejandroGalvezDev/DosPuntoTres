<?php
/** routes/buscador_routes.php */

if ($path === '/buscador' && $method === 'GET') {
    requireAuth();
    $pdo = getDb();
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if ($q === '') jsonOk([]);

    $like = '%' . $q . '%';
    $stmt = $pdo->prepare('
        SELECT id, nombre_edificio, direccion, maps_url, dias_grabacion, tiene_llave, color_llave
        FROM clientes WHERE nombre_edificio LIKE :l1 OR CAST(id AS TEXT) LIKE :l2
        ORDER BY nombre_edificio ASC LIMIT 30
    ');
    $stmt->execute([':l1' => $like, ':l2' => $like]);
    $rows = $stmt->fetchAll();
    $rows = array_map(function ($r) { $r['tiene_llave'] = (bool)$r['tiene_llave']; return $r; }, $rows);
    jsonOk($rows);
}
