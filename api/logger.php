<?php
/**
 * logger.php
 */
require_once __DIR__ . '/db.php';

function registrarLog($userId, $aliasLog, $accion, $entidad, $entidadId, $detalle) {
    $pdo = getDb();
    $stmt = $pdo->prepare("
        INSERT INTO logs (user_id, alias_log, accion, entidad, entidad_id, detalle)
        VALUES (:user_id, :alias_log, :accion, :entidad, :entidad_id, :detalle)
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':alias_log' => $aliasLog,
        ':accion' => $accion,
        ':entidad' => $entidad,
        ':entidad_id' => $entidadId,
        ':detalle' => $detalle,
    ]);
}
