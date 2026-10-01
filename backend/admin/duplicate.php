<?php
/* Duplica un cliente: crea uno nuevo copiando todos sus datos, con un
   usuario único. Útil para montar clientes parecidos sin empezar de cero. */
require_once __DIR__ . '/../auth.php';
require_can_edit();
ensure_schema();

/* Crea datos, así que solo por POST + CSRF (auth.php lo comprueba solo). */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: index.php'); exit; }

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
/* Alcance: un miembro con rol limitado no puede duplicar clientes fuera de su alcance. */
if ($id && function_exists('alcance_ve_cliente') && !alcance_ve_cliente($id)) { header('Location: index.php'); exit; }
$st = db()->prepare('SELECT * FROM clients WHERE id = ?');
$st->execute([$id]);
$c = $st->fetch();
if (!$c) { header('Location: index.php'); exit; }

// usuario único: base_copia, base_copia2, ...
$base = $c['username'] . '_copia';
$u = $base; $n = 2;
$chk = db()->prepare('SELECT id FROM clients WHERE username = ?');
while (true) {
    $chk->execute([$u]);
    if (!$chk->fetch()) break;
    $u = $base . $n; $n++;
    if ($n > 200) { $u = $base . '_' . time(); break; }
}

$ins = db()->prepare('INSERT INTO clients
    (username, password_hash, name, iniciales, saludo, conversiones, tipo_id, actual, estado_json, plan_json, accesos_json, met_json, tareas_json)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
$ins->execute([
    $u, $c['password_hash'], $c['name'].' (copia)', $c['iniciales'], $c['saludo'],
    $c['conversiones'], $c['tipo_id'], $c['actual'],
    $c['estado_json'], $c['plan_json'], $c['accesos_json'], $c['met_json'], $c['tareas_json'],
]);
$newId = (int)db()->lastInsertId();

// abrir la ficha del nuevo para que el equipo la ajuste (usuario/contraseña, etc.)
header('Location: client.php?id=' . $newId . '&dup=1');
exit;
