<?php
namespace Croilab\Modulos\Trabajo;

use Croilab\Modulos\Tareas\TareasServicio;
use Croilab\Modulos\Tareas\TextoRico;
use Croilab\Seguridad\Acceso;
use PDO;

/* Búsqueda global (buscar.php y la paleta Ctrl+K). Mismo motor que el
   antiguo (LIKE por grupos), pero cada grupo exige el permiso de su módulo y
   respeta el alcance: el antiguo enseñaba facturas, negocios y clientes de
   cualquiera a cualquiera. Solo lee. Las rutas son las del front. */
class BuscarServicio
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array{q:string, n:int, grupos: array<int, array{g:string, r: array<int, array{t:string, s:string, u:string, i:string}>}>}
     */
    public function buscar(Acceso $acc, string $q, int $porGrupo = 5): array
    {
        $q = trim(preg_replace('/\s+/u', ' ', $q));
        if (mb_strlen($q) < 2) return ['q' => $q, 'n' => 0, 'grupos' => []];
        $q = mb_substr($q, 0, 80);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
        $empieza = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
        $lim = max(1, min(40, $porGrupo));
        $g = [];

        if ($acc->puede('ver.clientes')) {
            $r = $this->q("SELECT id, name, username, activo FROM clients c
                           WHERE (name LIKE ? OR username LIKE ? OR fact_nombre LIKE ? OR fact_nif LIKE ?)" . $acc->sqlClientes('c.id') . "
                           ORDER BY (name LIKE ?) DESC, name LIMIT $lim", [$like, $like, $like, $like, $empieza]);
            foreach ($r as $x) $g['Clientes'][] = $this->fila($x['name'], '@' . $x['username'] . ((int)$x['activo'] === 0 ? ' · dado de baja' : ''), '/clientes/' . (int)$x['id'], 'clients');
        }

        $r = $this->q("SELECT id, username, email, role FROM admins WHERE activo = 1 AND (username LIKE ? OR email LIKE ?) ORDER BY username LIMIT $lim", [$like, $like]);
        $roles = $this->nombresRoles();
        foreach ($r as $x) $g['Equipo'][] = $this->fila($x['username'], trim(($roles[$x['role']] ?? 'Miembro') . ($x['email'] ? ' · ' . $x['email'] : '')), '/perfil/' . (int)$x['id'], 'user');

        if ($acc->puede('ver.tareas')) {
            $r = $this->q("SELECT t.id, t.titulo, t.estado, c.name AS cname FROM tasks t LEFT JOIN clients c ON c.id = t.client_id
                           WHERE (t.titulo LIKE ? OR t.descripcion LIKE ?)" . $acc->sqlTareas('t') . "
                           ORDER BY (t.estado = 'completada'), t.updated_at DESC LIMIT $lim", [$like, $like]);
            foreach ($r as $x) {
                $g['Tareas'][] = $this->fila($x['titulo'], trim(($x['cname'] ? $x['cname'] . ' · ' : '') . (TareasServicio::ETIQUETA_ESTADO[$x['estado']] ?? $x['estado'])), '/tareas/' . (int)$x['id'], 'check');
            }
        }

        if ($acc->puede('ver.crm')) {
            $alc = $this->alcanceCrm($acc, 'ct');
            $r = $this->q("SELECT ct.id, ct.nombre, ct.empresa, ct.email, ct.fase FROM contacts ct
                           WHERE (ct.nombre LIKE ? OR ct.empresa LIKE ? OR ct.email LIKE ? OR ct.telefono LIKE ?)$alc
                           ORDER BY ct.updated_at DESC LIMIT $lim", [$like, $like, $like, $like]);
            foreach ($r as $x) {
                $g['Contactos'][] = $this->fila($x['nombre'] ?: ($x['empresa'] ?: 'Contacto #' . (int)$x['id']),
                    trim(($x['empresa'] ? $x['empresa'] . ' · ' : '') . ($x['email'] ?: str_replace('_', ' ', (string)$x['fase']))), '/crm/contactos/' . (int)$x['id'], 'crm');
            }
            $alcD = $this->alcanceCrm($acc, 'd');
            $r = $this->q("SELECT d.id, d.nombre, d.valor, d.fase, ct.nombre AS cn FROM deals d LEFT JOIN contacts ct ON ct.id = d.contact_id
                           WHERE (d.nombre LIKE ? OR d.servicio LIKE ? OR ct.nombre LIKE ? OR ct.empresa LIKE ?)$alcD
                           ORDER BY d.archivado, d.id DESC LIMIT $lim", [$like, $like, $like, $like]);
            $importes = $acc->puede('ver.importes');
            foreach ($r as $x) {
                $valor = $importes && (float)$x['valor'] > 0 ? number_format((float)$x['valor'], 0, ',', '.') . ' €' : str_replace('_', ' ', (string)$x['fase']);
                $g['Negocio'][] = $this->fila($x['nombre'] ?: 'Negocio #' . (int)$x['id'], trim(($x['cn'] ? $x['cn'] . ' · ' : '') . $valor), '/crm/negocio?open=' . (int)$x['id'], 'trend');
            }
        }

        if ($acc->puede('ver.finanzas')) {
            $r = $this->q("SELECT i.id, i.numero, i.cliente_nombre, i.estado, i.fecha FROM invoices i
                           WHERE (i.numero LIKE ? OR i.cliente_nombre LIKE ? OR i.cliente_nif LIKE ? OR i.notas LIKE ?)" . $this->alcanceCliente($acc, 'i.client_id') . "
                           ORDER BY i.fecha DESC, i.id DESC LIMIT $lim", [$like, $like, $like, $like]);
            foreach ($r as $x) {
                $g['Facturas'][] = $this->fila(($x['numero'] ?: 'Borrador #' . (int)$x['id']) . ' · ' . ($x['cliente_nombre'] ?: 'Sin cliente'),
                    ucfirst((string)$x['estado']) . ($x['fecha'] ? ' · ' . date('d/m/Y', strtotime($x['fecha'])) : ''), '/finanzas/facturas/' . (int)$x['id'], 'file');
            }
        }

        if ($acc->puede('ver.soporte')) {
            $r = $this->q("SELECT s.id, s.asunto, s.estado, c.name AS cname FROM support_tickets s LEFT JOIN clients c ON c.id = s.client_id
                           WHERE (s.asunto LIKE ? OR s.cuerpo LIKE ?)" . $this->alcanceCliente($acc, 's.client_id') . "
                           ORDER BY (s.estado = 'cerrado'), s.updated_at DESC LIMIT $lim", [$like, $like]);
            foreach ($r as $x) $g['Soporte'][] = $this->fila($x['asunto'], trim(($x['cname'] ? $x['cname'] . ' · ' : '') . ucfirst(str_replace('_', ' ', (string)$x['estado']))), '/soporte/' . (int)$x['id'], 'ticket');
        }

        if ($acc->puede('ver.proyectos')) {
            $r = $this->q("SELECT p.id, p.nombre, c.name AS cname FROM projects p LEFT JOIN clients c ON c.id = p.client_id
                           WHERE p.nombre LIKE ?" . $this->alcanceCliente($acc, 'p.client_id') . " ORDER BY p.activo DESC, p.nombre LIMIT $lim", [$like]);
            foreach ($r as $x) $g['Proyectos'][] = $this->fila($x['nombre'], $x['cname'] ?: 'Interno', '/finanzas/proyectos?p=' . (int)$x['id'], 'layers');
        }

        if ($acc->puede('ver.actas')) {
            $r = $this->q("SELECT id, titulo, contenido FROM actas WHERE titulo LIKE ? OR contenido LIKE ? ORDER BY pinned DESC, updated_at DESC LIMIT $lim", [$like, $like]);
            foreach ($r as $x) $g['Actas'][] = $this->fila(trim((string)$x['titulo']) !== '' ? $x['titulo'] : '(Sin título)', TextoRico::extracto((string)$x['contenido'], 110), '/actas/' . (int)$x['id'], 'pen');
        }

        /* Páginas del panel («factur» → Facturas). Con textos cortos, primero. */
        $qn = self::normal($q);
        $pg = [];
        foreach (self::paginas() as [$t, $s, $u, $i, $perm]) {
            if ($perm !== null && !$acc->puede($perm)) continue;
            if (str_contains(self::normal($t), $qn) || str_contains(self::normal($s), $qn)) $pg[] = $this->fila($t, $s, $u, $i);
        }
        $pg = array_slice($pg, 0, $lim);

        $grupos = [];
        if ($pg && mb_strlen($q) <= 4) $grupos[] = ['g' => 'Ir a', 'r' => $pg];
        foreach ($g as $nombre => $r) $grupos[] = ['g' => $nombre, 'r' => $r];
        if ($pg && mb_strlen($q) > 4) $grupos[] = ['g' => 'Ir a', 'r' => $pg];
        return ['q' => $q, 'n' => array_sum(array_map(fn($x) => count($x['r']), $grupos)), 'grupos' => $grupos];
    }

    /** Páginas que se pueden buscar: [título, subtítulo, ruta, icono, permiso]. */
    public static function paginas(): array
    {
        return [
            ['Dashboard', 'Resumen del día', '/inicio', 'home', null],
            ['Todas las tareas', 'Tablero de trabajo', '/tareas?view=all', 'inbox', 'ver.tareas'],
            ['Mis tareas', 'Lo que tienes asignado', '/tareas?view=mine', 'usercheck', 'ver.tareas'],
            ['Calendario', 'Vencimientos y reuniones', '/calendario', 'cal', 'ver.agenda'],
            ['Reuniones', 'Próximas y pasadas', '/reuniones', 'video', 'ver.agenda'],
            ['CRM · Contactos', 'Leads y contactos', '/crm', 'crm', 'ver.crm'],
            ['CRM · Negocio', 'Embudo de oportunidades', '/crm/negocio', 'trend', 'ver.crm'],
            ['Clientes en alta', 'Fichas y accesos al portal', '/clientes', 'clients', 'ver.clientes'],
            ['Facturas', 'Emitidas, borradores y cobros', '/finanzas/facturas', 'file', 'ver.finanzas'],
            ['Contabilidad', 'Ingresos y gastos', '/finanzas/contabilidad', 'euro', 'ver.conta'],
            ['Resumen financiero', 'Cierre por meses', '/finanzas', 'chart', 'ver.finanzas'],
            ['Horas de equipo', 'Horas y tarifas por persona', '/finanzas/horas', 'clock', 'ver.horas'],
            ['Tarifas y precios', 'Calculadora de propuestas', '/finanzas/precios', 'calc', 'ver.finanzas'],
            ['Proyectos', 'Rentabilidad por proyecto', '/finanzas/proyectos', 'layers', 'ver.proyectos'],
            ['Soporte', 'Tickets de clientes', '/soporte', 'ticket', 'ver.soporte'],
            ['Chat de equipo', 'Conversaciones internas', '/chat', 'chat', 'ver.chat'],
            ['Actas', 'Notas y actas del equipo', '/actas', 'pen', 'ver.actas'],
            ['Credenciales', 'Bóveda de accesos', '/credenciales', 'vault', 'ver.credenciales'],
            ['Mi equipo', 'Personas y permisos', '/ajustes/equipo', 'user', 'equipo.gestionar'],
            ['Roles y permisos', 'Qué puede hacer cada rol', '/ajustes/roles', 'usercheck', 'roles.gestionar'],
            ['Agencias', 'Marca blanca', '/clientes/agencias', 'building', 'ver.clientes'],
            ['Servicios', 'Catálogo de servicios', '/clientes/servicios', 'list', 'ver.clientes'],
            ['Tipos de cliente', 'Plantillas de alta', '/clientes/tipos', 'flag', 'ver.clientes'],
            ['Ajustes', 'Configuración del ERP', '/ajustes', 'settings', 'ver.ajustes'],
            ['Reglas automáticas', 'Avisos automáticos (Ajustes)', '/ajustes/reglas', 'bolt', 'ver.ajustes'],
            ['Integraciones', 'Google, n8n y Claude', '/ajustes/integraciones', 'bolt', 'ver.ajustes'],
            ['Papelera', 'Recuperar lo borrado', '/ajustes/papelera', 'trash', 'ver.ajustes'],
            ['Notificaciones', 'Tu bandeja', '/notificaciones', 'bell', null],
            ['Mi cuenta', 'Perfil, contraseña y avisos', '/perfil', 'user', null],
        ];
    }

    /* Contactos/negocios: con alcance limitado, solo los propios o de clientes visibles. */
    private function alcanceCrm(Acceso $acc, string $alias): string
    {
        $ids = $acc->clientesVisibles();
        if ($ids === null) return '';
        $a = preg_replace('/[^a-z_]/i', '', $alias);
        $cli = $ids ? " OR $a.client_id IN (" . implode(',', array_map('intval', $ids)) . ')' : '';
        return " AND ($a.propietario_id = " . (int)$acc->adminId . $cli . ') ';
    }

    /* Con alcance limitado, lo que no tiene cliente tampoco se ve. */
    private function alcanceCliente(Acceso $acc, string $col): string
    {
        return $acc->veTodo() ? '' : " AND $col IS NOT NULL" . $acc->sqlClientes($col);
    }

    private function nombresRoles(): array
    {
        $out = ['owner' => 'Dueño', 'editor' => 'Editor', 'viewer' => 'Solo lectura'];
        foreach ($this->q('SELECT clave, nombre FROM roles') as $r) $out[$r['clave']] = $r['nombre'];
        return $out;
    }

    /* Una tabla que no existe en esta instalación no tumba la búsqueda. */
    private function q(string $sql, array $p = []): array
    {
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($p);
            return $st->fetchAll();
        } catch (\PDOException $e) {
            return [];
        }
    }

    private function fila(string $t, string $s, string $u, string $i): array
    {
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        return ['t' => mb_strlen($t) > 120 ? mb_substr($t, 0, 119) . '…' : $t, 's' => $s, 'u' => $u, 'i' => $i];
    }

    private static function normal(string $s): string
    {
        $s = mb_strtolower($s);
        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }
}
