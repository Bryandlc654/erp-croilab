<?php
namespace Croilab\Modulos\Crm;

use Croilab\Seguridad\Acceso;

/* Alcance del CRM (03-crm.md §2.3). El ERP antiguo no filtraba nada: con
   `ver.crm` se veía y editaba todo. Ahora, sin `alcance.todos`, una persona
   ve los contactos de los que es propietaria y los que no tienen propietario
   (los leads que entran sin asignar tienen que poder recogerse), y los
   negocios, comentarios, listas y seguimientos de esos contactos. Lo que no
   se ve responde igual que si no existiera (404). */
final class Alcance
{
    /** Fragmento « AND (…)» sobre la tabla contacts con el alias dado. */
    public static function sql(Acceso $acc, string $alias = 'c'): string
    {
        if ($acc->veTodo()) return '';
        if (!$acc->adminId) return ' AND 1=0 ';
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
        return " AND ({$a}.propietario_id IS NULL OR {$a}.propietario_id = " . (int)$acc->adminId . ') ';
    }

    /** ¿Ve un contacto con este propietario? */
    public static function vePropietario(Acceso $acc, ?int $propietario): bool
    {
        return $acc->veTodo() || $propietario === null || $propietario === 0 || $propietario === $acc->adminId;
    }
}
