-- ===========================================================
-- Estructura de la base de datos del portal de cliente
-- (SQLite: install.php la crea sola; este archivo es por si
--  quieres ejecutarla a mano con la consola sqlite3)
-- ===========================================================

CREATE TABLE IF NOT EXISTS admins (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'editor',   -- owner | editor | viewer
  created_at TIMESTAMP DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS client_types (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre VARCHAR(120) NOT NULL,
  secciones_json TEXT,                          -- {"metricas":1,"progreso":1,...}
  created_at TIMESTAMP DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS clients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(200) NOT NULL,
  iniciales VARCHAR(6) NOT NULL DEFAULT 'CL',
  saludo VARCHAR(120) NOT NULL DEFAULT '',
  conversiones TINYINT(1) NOT NULL DEFAULT 1,
  tipo_id INTEGER NULL,                         -- -> client_types.id
  actual VARCHAR(40) NOT NULL DEFAULT '',
  estado_json  TEXT,
  plan_json    TEXT,
  accesos_json TEXT,
  informes_json TEXT,
  servicios_json TEXT,
  looker_url   TEXT,
  met_json     TEXT,
  tareas_json  TEXT,
  created_at TIMESTAMP DEFAULT (datetime('now','localtime'))
);
