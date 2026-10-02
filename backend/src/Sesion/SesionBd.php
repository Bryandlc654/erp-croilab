<?php
namespace Croilab\Sesion;

use PDO;

/* Sesiones de PHP guardadas en la tabla `sessions` (SESSION_DRIVER=db).
   Con ficheros, cada servidor tiene las suyas y un balanceador manda a la
   gente fuera de su sesión; en la base de datos las comparten todos.
   Implementa validateId para poder usar session.use_strict_mode: PHP rechaza
   ids de sesión que no ha creado él (evita la fijación de sesión). */
class SesionBd implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false
    {
        $st = $this->pdo->prepare('SELECT datos FROM sessions WHERE id = ?');
        $st->execute([$id]);
        $d = $st->fetchColumn();
        return $d === false ? '' : (string)$d;
    }

    public function write(string $id, string $data): bool
    {
        $this->pdo->prepare('INSERT INTO sessions (id, datos, actualizada) VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE datos = VALUES(datos), actualizada = VALUES(actualizada)')
            ->execute([$id, $data, time()]);
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $st = $this->pdo->prepare('DELETE FROM sessions WHERE actualizada < ?');
        $st->execute([time() - $max_lifetime]);
        return $st->rowCount();
    }

    public function validateId(string $id): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM sessions WHERE id = ?');
        $st->execute([$id]);
        return (bool)$st->fetchColumn();
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->pdo->prepare('UPDATE sessions SET actualizada = ? WHERE id = ?')->execute([time(), $id]);
        return true;
    }
}
