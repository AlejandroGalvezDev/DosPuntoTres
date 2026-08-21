<?php
/** routes/dashboard_routes.php */

if ($path === '/dashboard/contadores' && $method === 'GET') {
    requireAuth();
    $pdo = getDb();
    $grabaciones = $pdo->query("SELECT COUNT(*) FROM incidencias WHERE tipo='grabacion' AND estado='pendiente'")->fetchColumn();
    $averias = $pdo->query("SELECT COUNT(*) FROM incidencias WHERE tipo='averia' AND estado='pendiente'")->fetchColumn();
    $revisiones = $pdo->query("SELECT COUNT(*) FROM revisiones WHERE estado='pendiente'")->fetchColumn();
    $instalaciones = $pdo->query("SELECT COUNT(*) FROM instalaciones WHERE estado='pendiente'")->fetchColumn();

    jsonOk([
        'grabaciones' => (int)$grabaciones,
        'averias' => (int)$averias,
        'revisiones' => (int)$revisiones,
        'instalaciones' => (int)$instalaciones,
    ]);
}
