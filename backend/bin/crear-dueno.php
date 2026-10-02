<?php
/* Crea la cuenta del dueño (acceso total). Sustituye al install.php que se
   usaba en las instalaciones nuevas y que no estaba en el repositorio.

     php bin/crear-dueno.php <usuario> [--email=correo@dominio]

   El correo es el que recibe el enlace de «he olvidado mi contraseña».

   Pide la contraseña por la terminal (o la lee de CROILAB_DUENO_PASS, para
   automatizar sin dejarla en el historial de la consola). */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../db.php';
require __DIR__ . '/../admin/lib/credenciales.php';

use Croilab\Database\Migrador;

$usuario = trim((string)($argv[1] ?? ''));
$email = '';
foreach (array_slice($argv, 2) as $a) if (str_starts_with($a, '--email=')) $email = trim(substr($a, 8));
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "El correo no es válido.\n"); exit(2); }
if (!preg_match('/^[a-zA-Z0-9._-]{3,80}$/', $usuario)) {
    fwrite(STDERR, "Uso: php bin/crear-dueno.php <usuario>   (3-80 caracteres: letras, números, . _ -)\n");
    exit(2);
}

try {
    $pdo = db();
    if (Migrador::hayPendientes($pdo)) {
        fwrite(STDERR, "Hay migraciones pendientes: ejecuta antes  php bin/migrate.php\n");
        exit(1);
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE username = ?');
    $st->execute([$usuario]);
    if ((int)$st->fetchColumn() > 0) { fwrite(STDERR, "Ya existe una cuenta «{$usuario}».\n"); exit(1); }

    $pass = getenv('CROILAB_DUENO_PASS') ?: '';
    if ($pass === '') {
        echo 'Contraseña: ';
        $pass = rtrim((string)fgets(STDIN), "\r\n");
    }
    $fallo = password_valida($pass, 10);   // la cuenta con acceso total: mínimo 10, no los 6 generales
    if ($fallo) { fwrite(STDERR, "La contraseña no vale: $fallo\n"); exit(1); }

    $pdo->prepare("INSERT INTO admins (username, password_hash, email, role, activo) VALUES (?, ?, ?, 'owner', 1)")
        ->execute([$usuario, password_hash($pass, PASSWORD_DEFAULT), $email !== '' ? $email : null]);
    echo "Cuenta «{$usuario}» creada con acceso total.\n";
    if ($email === '') echo "Aviso: sin --email no podrá recuperar la contraseña por correo.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
