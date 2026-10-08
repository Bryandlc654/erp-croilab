<?php
namespace Croilab\Modulos\Comunicacion;

use Croilab\Seguridad\Acceso;
use PDO;

/* Alcance sobre contactos del CRM (Acceso solo lo da para clientes y tareas).
   Mismo criterio que Acceso::clientesVisibles: sin `alcance.todos` se ven los
   contactos de los que se es propietario y los de los clientes visibles. */
final class Alcance
{
    /** Fragmento SQL (" AND …") para filtrar contactos por el alias de la tabla contacts. */
    public static function sqlContactos(Acceso $acc, string $alias = 'c'): string
    {
        if ($acc->veTodo()) return '';
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
        $yo = (int)$acc->adminId;
        $cli = $acc->sqlClientes("$a.client_id");
        $porCliente = $cli === ' AND 1=0 ' ? '' : " OR ($a.client_id IS NOT NULL $cli)";
        return " AND ($a.propietario_id = $yo$porCliente) ";
    }

    public static function veContacto(PDO $pdo, Acceso $acc, int $id): bool
    {
        if ($id <= 0) return false;
        try {
            $st = $pdo->prepare('SELECT 1 FROM contacts c WHERE c.id = ?' . self::sqlContactos($acc, 'c'));
            $st->execute([$id]);
            return (bool)$st->fetchColumn();
        } catch (\PDOException $e) {
            return false;   // sin CRM
        }
    }
}
