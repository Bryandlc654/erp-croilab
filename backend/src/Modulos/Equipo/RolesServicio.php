<?php
namespace Croilab\Modulos\Equipo;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;

/* Roles y permisos (permisos.php). El catálogo, los requisitos y las reglas
   (el Dueño no pierde el acceso total, no se borran roles del sistema ni en
   uso, se completan requisitos al guardar) viven en admin/lib/permisos.php:
   aquí solo se exponen, sin duplicarlos. */
class RolesServicio
{
    public function listar(Acceso $acc): array
    {
        $acc->exigir('roles.gestionar');
        $uso = self::usoActivos();
        $roles = [];
        foreach (roles_todos() as $k => $r) {
            $roles[] = [
                'clave' => (string)$k,
                'nombre' => (string)$r['nombre'],
                'descripcion' => (string)$r['descripcion'],
                'sistema' => (bool)$r['sistema'],
                'permisos' => array_values(array_intersect(perm_todas(), $r['permisos'] ?? [])),
                'total' => in_array('admin.total', $r['permisos'] ?? [], true),
                'uso' => (int)($uso[$k] ?? 0),
            ];
        }
        $catalogo = [];
        foreach (perm_catalogo() as $grupo => $perms) {
            $items = [];
            foreach ($perms as $clave => [$etiqueta, $desc]) $items[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'descripcion' => $desc];
            $catalogo[] = ['grupo' => $grupo, 'permisos' => $items];
        }
        return [
            'roles' => $roles,
            'catalogo' => $catalogo,
            'requisitos' => (object)perm_requisitos(),
            'config' => perm_config(),
            'personas' => array_sum($uso),
        ];
    }

    private function rol(string $clave): array
    {
        $r = roles_todos()[$clave] ?? null;
        if (!$r) throw HttpError::noEncontrado('Ese rol ya no existe.');
        return $r;
    }

    /* Tocar el acceso total, o un rol que lo tiene, es cosa de quien lo tiene. */
    private function exigirTotalSiHaceFalta(Acceso $acc, array $rol, string $perm = ''): void
    {
        $toca = $perm === 'admin.total' || in_array('admin.total', $rol['permisos'] ?? [], true);
        if ($toca && !$acc->puede('admin.total')) throw new HttpError(403, 'Solo alguien con acceso total puede cambiar el acceso total.', 'permiso');
    }

    /** {rol, perm, on} → {permisos, recargar} (al apagar caen los que dependen de él). */
    public function permiso(Acceso $acc, array $d): array
    {
        $acc->exigir('roles.gestionar');
        $clave = (string)($d['rol'] ?? '');
        $perm = (string)($d['perm'] ?? '');
        $on = Validar::bool($d['on'] ?? false);
        $r = $this->rol($clave);
        if (!in_array($perm, perm_todas(), true)) throw HttpError::validacion('Ese permiso no existe.', 'perm');
        $this->exigirTotalSiHaceFalta($acc, $r, $perm);
        if ($clave === 'owner' && $perm === 'admin.total' && !$on) {
            throw new HttpError(409, 'Al rol Dueño no se le puede quitar el acceso total: si no, nadie podría gestionar los roles.', 'dueno');
        }
        $p = $r['permisos'] ?? [];
        if ($on) {
            $p[] = $perm;
        } else {
            $fuera = array_merge([$perm], $this->dependientesRecursivos($perm));
            $p = array_values(array_diff($p, $fuera));
        }
        return $this->guardar($acc, $clave, $r, $p);
    }

    /** {rol, grupo, on}: «Todo» / «Quitar» de una sección. Nunca toca admin.total. */
    public function grupo(Acceso $acc, array $d): array
    {
        $acc->exigir('roles.gestionar');
        $clave = (string)($d['rol'] ?? '');
        $grupo = (string)($d['grupo'] ?? '');
        $on = Validar::bool($d['on'] ?? false);
        $r = $this->rol($clave);
        $cat = perm_catalogo();
        if (!isset($cat[$grupo])) throw HttpError::validacion('Esa sección no existe.', 'grupo');
        $this->exigirTotalSiHaceFalta($acc, $r);
        $delGrupo = array_values(array_diff(array_keys($cat[$grupo]), ['admin.total']));
        $p = $r['permisos'] ?? [];
        if ($on) {
            $p = array_merge($p, $delGrupo);
        } else {
            $fuera = $delGrupo;
            foreach ($delGrupo as $x) $fuera = array_merge($fuera, $this->dependientesRecursivos($x));
            $p = array_values(array_diff($p, $fuera));
        }
        return $this->guardar($acc, $clave, $r, $p);
    }

    private function dependientesRecursivos(string $perm): array
    {
        $out = [];
        $pend = [$perm];
        while ($pend) {
            $x = array_pop($pend);
            foreach (perm_dependientes($x) as $d) if (!in_array($d, $out, true)) { $out[] = $d; $pend[] = $d; }
        }
        return $out;
    }

    private function guardar(Acceso $acc, string $clave, array $r, array $permisos): array
    {
        $res = rol_guardar($clave, (string)$r['nombre'], (string)$r['descripcion'], array_values(array_unique($permisos)));
        if (empty($res['ok'])) throw new HttpError(409, (string)$res['msg'], 'rol');
        /* No hace falta tumbar sesiones: los permisos se leen de la tabla de
           roles en cada petición, así que el cambio se nota en la siguiente. */
        if (function_exists('audit_log')) audit_log('rol.permisos', $clave);
        $mio = (string)(db()->query('SELECT role FROM admins WHERE id = ' . (int)$acc->adminId)->fetchColumn() ?: '');
        return ['permisos' => array_values(array_intersect(perm_todas(), roles_todos()[$clave]['permisos'] ?? [])), 'recargar' => $mio === $clave];
    }

    /** {nombre} → {clave}. Nace sin permisos. */
    public function crear(Acceso $acc, array $d): array
    {
        $acc->exigir('roles.gestionar');
        $nombre = Validar::texto($d['nombre'] ?? '', 60, 'nombre', 'El nombre');
        if ($nombre === '') throw HttpError::validacion('El rol necesita un nombre.', 'nombre');
        foreach (roles_todos() as $r) if (mb_strtolower($r['nombre']) === mb_strtolower($nombre)) throw HttpError::validacion('Ya hay un rol con ese nombre.', 'nombre');
        $res = rol_guardar('', $nombre, '', []);
        if (empty($res['ok'])) throw new HttpError(409, (string)$res['msg'], 'rol');
        if (function_exists('audit_log')) audit_log('rol.crear', (string)$res['clave']);
        return ['clave' => (string)$res['clave']];
    }

    /** {rol, nombre} */
    public function renombrar(Acceso $acc, array $d): array
    {
        $acc->exigir('roles.gestionar');
        $clave = (string)($d['rol'] ?? '');
        $r = $this->rol($clave);
        $nombre = Validar::texto($d['nombre'] ?? '', 60, 'nombre', 'El nombre');
        if ($nombre === '') throw HttpError::validacion('El rol necesita un nombre.', 'nombre');
        $res = rol_guardar($clave, $nombre, (string)$r['descripcion'], $r['permisos'] ?? []);
        if (empty($res['ok'])) throw new HttpError(409, (string)$res['msg'], 'rol');
        return ['nombre' => $nombre];
    }

    public function borrar(Acceso $acc, string $clave): array
    {
        $acc->exigir('roles.gestionar');
        $this->rol($clave);
        /* rol_borrar cuenta también las bajas: un rol que tenga alguien dado de
           baja no se borra (al reactivarle se quedaría sin permisos). */
        $res = rol_borrar($clave);
        if (empty($res['ok'])) throw new HttpError(409, (string)$res['msg'], 'rol');
        if (function_exists('audit_log')) audit_log('rol.borrar', $clave);
        return [];
    }

    /* Personas ACTIVAS por rol (roles_uso() de permisos.php cuenta también las bajas). */
    private static function usoActivos(): array
    {
        $u = [];
        foreach (db()->query('SELECT role, COUNT(*) n FROM admins WHERE activo = 1 GROUP BY role') as $r) $u[(string)$r['role']] = (int)$r['n'];
        return $u;
    }
}
