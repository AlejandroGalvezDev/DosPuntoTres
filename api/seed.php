<?php
/**
 * seed.php — Rellena la base de datos con datos de ejemplo.
 *
 * USO: visita en el navegador:
 *   https://tu-dominio.es/dospuntotres/api/seed.php?clave=SIEMBRA2026
 *
 * Cambia la clave de abajo antes de subirlo, y BORRA este archivo del
 * servidor en cuanto hayas confirmado que todo funciona.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

// ⚠️ Cambia esta clave por una tuya antes de subir el archivo.
define('CLAVE_SIEMBRA', 'SIEMBRA2026-CAMBIA-ESTO');

if (!isset($_GET['clave']) || $_GET['clave'] !== CLAVE_SIEMBRA) {
    http_response_code(403);
    echo "Acceso denegado. Añade ?clave=TU_CLAVE a la URL (ver cabecera de este archivo).";
    exit;
}

$pdo = getDb();

$yaHay = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
if ($yaHay > 0) {
    echo "La base de datos ya tiene usuarios. No se vuelve a sembrar.\n";
    echo "Si quieres reiniciar todo desde cero, borra el archivo data/dospuntotres.sqlite\n";
    echo "desde el Administrador de Archivos y vuelve a visitar esta URL.\n";
    exit;
}

echo "Sembrando datos de ejemplo...\n";

$hashAdmin = password_hash('admin123', PASSWORD_DEFAULT);
$hashTecnico = password_hash('tecnico123', PASSWORD_DEFAULT);

$insUsuario = $pdo->prepare('INSERT INTO usuarios (email, password_hash, nombre, alias_log, rol) VALUES (:email, :hash, :nombre, :alias, :rol)');
$insUsuario->execute([':email' => 'admin@dospuntotres.es', ':hash' => $hashAdmin, ':nombre' => 'Administración', ':alias' => 'Admin', ':rol' => 'admin']);
$admin1 = $pdo->lastInsertId();

$insUsuario->execute([':email' => 'j.sanchez@dospuntotres.es', ':hash' => $hashTecnico, ':nombre' => 'J. Sánchez', ':alias' => 'J. Sánchez', ':rol' => 'tecnico']);
$tec1 = $pdo->lastInsertId();
$insUsuario->execute([':email' => 'a.garcia@dospuntotres.es', ':hash' => $hashTecnico, ':nombre' => 'A. García', ':alias' => 'A. García', ':rol' => 'tecnico']);
$tec2 = $pdo->lastInsertId();
$insUsuario->execute([':email' => 'm.sanchez@dospuntotres.es', ':hash' => $hashTecnico, ':nombre' => 'M. Sánchez', ':alias' => 'M. Sánchez', ':rol' => 'tecnico']);
$tec3 = $pdo->lastInsertId();
$insUsuario->execute([':email' => 'j.lopez@dospuntotres.es', ':hash' => $hashTecnico, ':nombre' => 'J. López', ':alias' => 'J. López', ':rol' => 'tecnico']);

$insCliente = $pdo->prepare('
    INSERT INTO clientes (id, nombre_edificio, direccion, maps_url, dias_grabacion, tiene_llave, color_llave, persona_contacto, telefono_contacto)
    VALUES (:id, :nombre, :dir, :maps, :dias, :llave, :color, :contacto, :telefono)
');
$clientes = [
    [1, 'Robles', 'Calle Robles 8, Madrid', 'https://maps.google.com/?q=Calle+Robles+8+Madrid', 15, 1, 'rojo', 'Portero - Manuel', '600111222'],
    [327, 'Alteza', 'Av. Alteza 42, Madrid', 'https://maps.google.com/?q=Av+Alteza+42+Madrid', 15, 1, 'verde', 'Administradora - Rosa', '600333444'],
    [690, 'Bruselas', 'Calle Bruselas 3, Madrid', 'https://maps.google.com/?q=Calle+Bruselas+3+Madrid', 15, 1, 'azul', 'Conserje - Iván', '600555666'],
    [2, 'Central', 'Plaza Central 1, Madrid', 'https://maps.google.com/?q=Plaza+Central+1+Madrid', 20, 1, 'amarillo', 'Recepción', '600777888'],
    [3, 'Norte', 'Calle Norte 55, Madrid', 'https://maps.google.com/?q=Calle+Norte+55+Madrid', 30, 0, null, null, null],
    [4, 'Altda', 'Calle Altda 10, Madrid', 'https://maps.google.com/?q=Calle+Altda+10+Madrid', 25, 1, 'negro', 'Presidente comunidad', '600999000'],
    [5, 'Diverunal 16', 'Calle Diverunal 16, Madrid', 'https://maps.google.com/?q=Calle+Diverunal+16+Madrid', 20, 0, null, 'Portero - Jaime', '611111111'],
    [6, 'Baronias 13', 'Calle Baronias 13, Madrid', 'https://maps.google.com/?q=Calle+Baronias+13+Madrid', 20, 0, null, 'Contacto Jaime', '622222222'],
    [7, 'Bierudas 1000', 'Rua Lrido Bierudas 1000, Madrid', 'https://maps.google.com/?q=Rua+Lrido+Bierudas+1000+Madrid', 20, 1, 'naranja', 'Recepción', '633333333'],
];
foreach ($clientes as $c) {
    $insCliente->execute([':id' => $c[0], ':nombre' => $c[1], ':dir' => $c[2], ':maps' => $c[3], ':dias' => $c[4], ':llave' => $c[5], ':color' => $c[6], ':contacto' => $c[7], ':telefono' => $c[8]]);
}

// --- Grabaciones (creadas por el administrador, con nº de atestado desde el alta) ---
$insGrab = $pdo->prepare("INSERT INTO incidencias (tipo, cliente_id, suceso, fecha_incidente, numero_atestado, creado_por) VALUES ('grabacion', :cid, :suceso, :fecha, :atestado, :creador)");
$insGrab->execute([':cid' => 1, ':suceso' => 'Robo en garaje', ':fecha' => '2026-05-15 22:30', ':atestado' => '24/RBG/9988', ':creador' => $admin1]);
$insGrab->execute([':cid' => 327, ':suceso' => 'Basura en la entrada', ':fecha' => '2026-05-15 09:00', ':atestado' => '24/ALT/5544', ':creador' => $admin1]);

// --- Averías (creadas por el administrador, con categoría e importancia) ---
$insAve = $pdo->prepare("INSERT INTO incidencias (tipo, cliente_id, suceso, fecha_incidente, categoria_averia, importancia, creado_por) VALUES ('averia', :cid, :suceso, :fecha, :cat, :imp, :creador)");
$insAve->execute([':cid' => 690, ':suceso' => 'Roce en garaje, cámara desalineada', ':fecha' => '2026-05-17 08:00', ':cat' => 'camara', ':imp' => 'media', ':creador' => $admin1]);
$insAve->execute([':cid' => 4, ':suceso' => 'El grabador no enciende', ':fecha' => '2026-05-18 08:00', ':cat' => 'grabador', ':imp' => 'alta', ':creador' => $admin1]);
$insAve->execute([':cid' => 2, ':suceso' => 'Ruido extraño en disco duro', ':fecha' => '2026-05-19 08:00', ':cat' => 'disco_duro', ':imp' => 'baja', ':creador' => $admin1]);

// --- Grabaciones resueltas (histórico) ---
$resGrab = $pdo->prepare("
    INSERT INTO incidencias (tipo, cliente_id, suceso, fecha_incidente, numero_atestado, estado, resultado, descripcion_resolucion, comentario_x, tecnico_resuelve_id, fecha_resolucion, creado_por)
    VALUES ('grabacion', :cid, :suceso, :fecha, :atestado, 'resuelta', :res, :desc, :comx, :tec, :fres, :creador)
");
$resGrab->execute([':cid' => 1, ':suceso' => 'Robo garaje anterior', ':fecha' => '2026-04-15 22:30', ':atestado' => '24/RBG/1122', ':res' => 'V', ':desc' => 'Forzó el cierre de la puerta y sustrajo una bicicleta de montaña.', ':comx' => null, ':tec' => $tec2, ':fres' => '2026-04-22 10:15', ':creador' => $admin1]);
$resGrab->execute([':cid' => 327, ':suceso' => 'Sustracción en portal', ':fecha' => '2026-04-16 12:00', ':atestado' => '24/ALT/3344', ':res' => 'X', ':desc' => null, ':comx' => 'No hay grabaciones disponibles: la cámara estaba apagada por avería previa.', ':tec' => $tec3, ':fres' => '2026-04-22 14:30', ':creador' => $admin1]);

// --- Averías resueltas (histórico) ---
$resAve = $pdo->prepare("
    INSERT INTO incidencias (tipo, cliente_id, suceso, fecha_incidente, categoria_averia, importancia, estado, resultado, descripcion_resolucion, tecnico_resuelve_id, fecha_resolucion, creado_por)
    VALUES ('averia', :cid, :suceso, :fecha, :cat, :imp, 'resuelta', 'V', :desc, :tec, :fres, :creador)
");
$resAve->execute([':cid' => 4, ':suceso' => 'Fallo de disco duro', ':fecha' => '2026-04-10 09:00', ':cat' => 'disco_duro', ':imp' => 'media', ':desc' => 'El alimentador del videograbador estaba roto, se sustituye.', ':tec' => $tec1, ':fres' => '2026-04-11 11:00', ':creador' => $admin1]);
$resAve->execute([':cid' => 2, ':suceso' => 'Falla cámara del zaguán', ':fecha' => '2026-04-12 09:00', ':cat' => 'camara', ':imp' => 'baja', ':desc' => 'Cámara desconectada por corte de corriente, se restablece.', ':tec' => $tec2, ':fres' => '2026-04-12 16:00', ':creador' => $admin1]);

// --- Revisiones (sin cambios de esquema) ---
$insRev = $pdo->prepare('INSERT INTO revisiones (cliente_id, notas, fecha_programada, creado_por) VALUES (:cid, :notas, :fecha, :creador)');
$insRev->execute([':cid' => 1, ':notas' => 'Revisión semestral de grabadores', ':fecha' => '2026-08-10', ':creador' => $tec1]);
$insRev->execute([':cid' => 4, ':notas' => 'Comprobación de discos duros', ':fecha' => '2026-08-12', ':creador' => $tec2]);

// --- Instalaciones (solo administración; atadas al cliente maestro) ---
$insInst = $pdo->prepare("INSERT INTO instalaciones (cliente_id, tecnico_nombre, mas_informacion, estado, creado_por) VALUES (:cid, :tecnico, :info, 'pendiente', :creador)");
$insInst->execute([':cid' => 5, ':tecnico' => 'J. Sánchez', ':info' => 'Instalación de 4 cámaras en garaje y portal.', ':creador' => $admin1]);
$insInst->execute([':cid' => 6, ':tecnico' => 'A. García', ':info' => 'Pendiente de acceso a cuarto de contadores.', ':creador' => $admin1]);

$insInstDone = $pdo->prepare("INSERT INTO instalaciones (cliente_id, tecnico_nombre, mas_informacion, fecha_instalacion, estado, creado_por) VALUES (:cid, :tecnico, :info, :fecha, 'terminada', :creador)");
$insInstDone->execute([':cid' => 7, ':tecnico' => 'M. Sánchez', ':info' => 'Instalación de cámaras en parking subterráneo, 2 plantas.', ':fecha' => '2026-05-01', ':creador' => $admin1]);

echo "Datos de ejemplo insertados correctamente.\n\n";
echo "Usuarios de prueba:\n";
echo "  Admin:    admin@dospuntotres.es / admin123\n";
echo "  Técnico:  j.sanchez@dospuntotres.es / tecnico123\n\n";
echo "⚠️  Ahora BORRA este archivo (seed.php) del servidor por seguridad.\n";
