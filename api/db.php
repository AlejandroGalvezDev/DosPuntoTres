<?php
/**
 * db.php
 * Conexión PDO a SQLite + creación de tablas (idempotente).
 */
require_once __DIR__ . '/config.php';

function getDb() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON;');

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS usuarios (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        nombre TEXT NOT NULL,
        alias_log TEXT NOT NULL,
        rol TEXT NOT NULL CHECK (rol IN ('admin','tecnico')),
        activo INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS clientes (
        id INTEGER PRIMARY KEY,
        nombre_edificio TEXT NOT NULL,
        direccion TEXT,
        maps_url TEXT,
        dias_grabacion INTEGER,
        tiene_llave INTEGER NOT NULL DEFAULT 0,
        color_llave TEXT,
        persona_contacto TEXT,
        telefono_contacto TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS cliente_fotos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cliente_id INTEGER NOT NULL REFERENCES clientes(id) ON DELETE CASCADE,
        filepath TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS incidencias (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tipo TEXT NOT NULL CHECK (tipo IN ('grabacion','averia')),
        cliente_id INTEGER NOT NULL REFERENCES clientes(id),
        suceso TEXT NOT NULL,
        fecha_incidente TEXT NOT NULL,
        categoria_averia TEXT CHECK (categoria_averia IN ('disco_duro','grabador','camara','otros')),
        importancia TEXT CHECK (importancia IN ('alta','media','baja')),
        numero_atestado TEXT,
        estado TEXT NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente','resuelta')),
        resultado TEXT CHECK (resultado IN ('V','X')),
        descripcion_resolucion TEXT,
        comentario_x TEXT,
        tecnico_resuelve_id INTEGER REFERENCES usuarios(id),
        fecha_resolucion TEXT,
        creado_por INTEGER REFERENCES usuarios(id),
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS incidencia_fotos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        incidencia_id INTEGER NOT NULL REFERENCES incidencias(id) ON DELETE CASCADE,
        fase TEXT NOT NULL CHECK (fase IN ('inicial','resolucion')),
        filepath TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS revisiones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cliente_id INTEGER NOT NULL REFERENCES clientes(id),
        notas TEXT,
        fecha_programada TEXT,
        estado TEXT NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente','realizada')),
        tecnico_id INTEGER REFERENCES usuarios(id),
        fecha_realizada TEXT,
        creado_por INTEGER REFERENCES usuarios(id),
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS instalaciones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cliente_id INTEGER NOT NULL REFERENCES clientes(id),
        tecnico_nombre TEXT,
        mas_informacion TEXT,
        fecha_instalacion TEXT,
        estado TEXT NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente','terminada')),
        creado_por INTEGER REFERENCES usuarios(id),
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS instalacion_fotos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        instalacion_id INTEGER NOT NULL REFERENCES instalaciones(id) ON DELETE CASCADE,
        fase TEXT NOT NULL DEFAULT 'presupuesto' CHECK (fase IN ('presupuesto','finalizada')),
        filepath TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        timestamp TEXT NOT NULL DEFAULT (datetime('now')),
        user_id INTEGER REFERENCES usuarios(id),
        alias_log TEXT,
        accion TEXT NOT NULL,
        entidad TEXT NOT NULL,
        entidad_id INTEGER,
        detalle TEXT
    );
    ");

    return $pdo;
}
