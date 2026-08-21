<?php
/**
 * upload.php
 * Manejo seguro de subida de fotografías:
 *   1. Validación de tamaño máximo.
 *   2. Validación de tipo MIME real (magic bytes via mime_content_type).
 *   3. Verificación de que es una imagen válida con getimagesize().
 *   4. Recodificación con GD para eliminar metadatos EXIF y código embebido.
 *   5. Verificación de espacio libre en disco antes de guardar.
 *   6. Nombre de archivo aleatorio (no predecible).
 */
require_once __DIR__ . '/config.php';

define('MIME_PERMITIDOS', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('EXT_PERMITIDAS',  ['jpg', 'jpeg', 'png', 'webp', 'gif']);

function guardarFotosSubidas($campo = 'fotos') {
    if (!isset($_FILES[$campo])) return [];

    $lista = [];
    $files = $_FILES[$campo];
    if (is_array($files['name'])) {
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            $lista[] = [
                'name'     => $files['name'][$i],
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];
        }
    } else {
        if ($files['error'] !== UPLOAD_ERR_NO_FILE) $lista[] = $files;
    }

    $guardadas = [];
    foreach ($lista as $f) {
        // 1. Error de subida
        if ($f['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Error al subir una fotografía (código ' . $f['error'] . ').');
        }

        // 2. Tamaño máximo por archivo
        if ($f['size'] > MAX_FOTO_BYTES) {
            throw new Exception('Una fotografía supera el tamaño máximo permitido (' . (MAX_FOTO_BYTES / 1024 / 1024) . ' MB).');
        }

        // 3. MIME real (magic bytes, no el campo type del cliente que es manipulable)
        $mime = function_exists('mime_content_type') ? mime_content_type($f['tmp_name']) : $f['type'];
        if (!in_array($mime, MIME_PERMITIDOS, true)) {
            throw new Exception('Formato de imagen no soportado. Usa JPG, PNG, WEBP o GIF.');
        }

        // 4. Verificar que es una imagen real (no solo magic bytes correctos)
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info['mime'], MIME_PERMITIDOS, true)) {
            throw new Exception('El archivo no es una imagen válida.');
        }

        // 5. Espacio libre en disco antes de guardar
        $libreEnDisco = disk_free_space(UPLOADS_DIR);
        if ($libreEnDisco !== false && $libreEnDisco < MIN_DISK_FREE_BYTES) {
            throw new Exception('El servidor no tiene espacio suficiente para guardar la fotografía. Contacta con el administrador.');
        }

        // 6. Nombre final aleatorio e impredecible (sin relación con el nombre original)
        $ext        = 'jpg'; // por defecto siempre jpg para uniformidad
        $nombreFinal = bin2hex(random_bytes(16)) . '.' . $ext;
        $destino     = UPLOADS_DIR . '/' . $nombreFinal;

        // 7. Recodificar con GD para eliminar metadatos EXIF y cualquier código embebido.
        //    Si GD no está disponible, se guarda el archivo sin recodificar (move_uploaded_file).
        $recodificado = false;
        if (function_exists('imagecreatefromstring')) {
            $contenido = file_get_contents($f['tmp_name']);
            $imagen    = @imagecreatefromstring($contenido);
            if ($imagen !== false) {
                // Preservar transparencia para PNG/WebP/GIF
                if (in_array($mime, ['image/png', 'image/webp', 'image/gif'], true)) {
                    $nombreFinal = bin2hex(random_bytes(16)) . '.png';
                    $destino     = UPLOADS_DIR . '/' . $nombreFinal;
                    imagepng($imagen, $destino, 8); // compresión máxima, sin metadatos
                } else {
                    imagejpeg($imagen, $destino, 85); // calidad 85%, sin metadatos EXIF
                }
                imagedestroy($imagen);
                $recodificado = true;
            }
        }

        if (!$recodificado) {
            // Fallback: mover el archivo tal cual (GD no disponible o imagen no procesable)
            if (!move_uploaded_file($f['tmp_name'], $destino)) {
                throw new Exception('No se pudo guardar la fotografía en el servidor.');
            }
        }

        $guardadas[] = $nombreFinal;
    }

    return $guardadas;
}
