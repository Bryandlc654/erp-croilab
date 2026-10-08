<?php
namespace Croilab\Modulos\Comunicacion\Mcp;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Servidor MCP (JSON-RPC 2.0 sobre HTTP) para que Claude gestione TAREAS:
   listar, crear, actualizar, etiquetar y comentar; y consultar clientes y
   equipo. No borra nada ni toca facturación, credenciales ni ajustes.

   Mismo contrato que admin/mcp.php del antiguo (mismas herramientas y
   parámetros, mismo token de Ajustes › Integraciones), con lo que faltaba:
     · actúa como UNA persona del equipo (settings `mcp_actor`, o la primera
       con acceso total) y con SUS permisos y SU alcance, no como un superusuario,
     · crea y cambia tareas con el servicio de Tareas (mismas validaciones y
       mismos avisos que desde la pantalla), y comenta con su nombre (el
       antiguo comentaba como «el primer admin por id»),
     · cada llamada a una herramienta queda en audit_log (`mcp.<herramienta>`).

   El token se compara con hash_equals. Se lee de `Authorization: Bearer`,
   `?k=` o `?token=`. */
class McpServidor
{
    public const VERSION_PROTOCOLO = '2024-11-05';
    private const ESTADOS = ['pendiente', 'en proceso', 'atemporal', 'completada'];

    private ?Acceso $acceso = null;

    /** @param \Closure(): object $tareas fábrica del servicio de Tareas (crear/actualizar) */
    public function __construct(private readonly PDO $pdo, private readonly \Closure $tareas) {}

    /** ¿Está activado y el token es el bueno? */
    public function autorizado(string $token): bool
    {
        $activo = (string)get_setting('mcp_enabled', '') === '1';
        $guardado = trim((string)get_setting('mcp_token', ''));
        return $activo && $guardado !== '' && $token !== '' && hash_equals($guardado, $token);
    }

    /**
     * Atiende una petición HTTP: devuelve [código, cuerpo|null].
     * null = sin cuerpo (notificaciones: 202).
     */
    public function atender(string $cuerpo, string $token): array
    {
        if (!$this->autorizado($token)) {
            return [401, self::error(null, -32001, 'No autorizado. Revisa el token del MCP en Ajustes.')];
        }
        $req = json_decode($cuerpo, true);
        if (!is_array($req)) return [400, self::error(null, -32700, 'JSON no válido')];
        if (array_is_list($req) && $req !== []) {
            /* Lote: se responde a cada petición con id (las notificaciones no llevan respuesta). */
            $out = [];
            foreach ($req as $r) {
                $res = is_array($r) ? $this->una($r) : self::error(null, -32600, 'Petición no válida');
                if ($res !== null) $out[] = $res;
            }
            return $out ? [200, $out] : [202, null];
        }
        $res = $this->una($req);
        return $res === null ? [202, null] : [200, $res];
    }

    private function una(array $req): ?array
    {
        $metodo = (string)($req['method'] ?? '');
        $id = $req['id'] ?? null;
        $params = is_array($req['params'] ?? null) ? $req['params'] : [];
        if (!array_key_exists('id', $req) || str_starts_with($metodo, 'notifications/')) return null;
        return match ($metodo) {
            'initialize' => self::ok($id, [
                'protocolVersion' => self::VERSION_PROTOCOLO,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'Croilab ERP', 'version' => '2.0.0'],
            ]),
            'ping' => self::ok($id, new \stdClass()),
            'tools/list' => self::ok($id, ['tools' => self::herramientas()]),
            'tools/call' => self::ok($id, $this->llamar((string)($params['name'] ?? ''), is_array($params['arguments'] ?? null) ? $params['arguments'] : [])),
            default => self::error($id, -32601, 'Método no soportado: ' . $metodo),
        };
    }

    /** Resultado de una herramienta: texto JSON, o isError con el motivo. */
    public function llamar(string $nombre, array $a): array
    {
        try {
            $acc = $this->acceso();
            $res = $this->ejecutar($nombre, $a, $acc);
            if (function_exists('audit_log') && !str_starts_with($nombre, 'listar_')) audit_log('mcp.' . $nombre, mb_substr((string)json_encode($a, JSON_UNESCAPED_UNICODE), 0, 200));
            return ['content' => [['type' => 'text', 'text' => (string)json_encode($res, JSON_UNESCAPED_UNICODE)]]];
        } catch (HttpError $e) {
            return ['content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]], 'isError' => true];
        } catch (\InvalidArgumentException $e) {
            return ['content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]], 'isError' => true];
        }
    }

    /** Persona con la que actúa el MCP: `mcp_actor` si es válida y activa; si no, la primera con acceso total. */
    public function actor(): int
    {
        $fijo = (int)get_setting('mcp_actor', '0');
        if ($fijo > 0) {
            $st = $this->pdo->prepare('SELECT id FROM admins WHERE id = ? AND activo = 1');
            $st->execute([$fijo]);
            if ($st->fetchColumn()) return $fijo;
        }
        foreach ($this->pdo->query('SELECT id, role FROM admins WHERE activo = 1 ORDER BY id') as $r) {
            if (in_array('admin.total', self::permisosDe((string)$r['role']), true)) return (int)$r['id'];
        }
        throw new \InvalidArgumentException('No hay nadie en el equipo con quien actuar. Elige la persona del MCP en Ajustes.');
    }

    private function acceso(): Acceso
    {
        if ($this->acceso) return $this->acceso;
        $id = $this->actor();
        $st = $this->pdo->prepare('SELECT role FROM admins WHERE id = ?');
        $st->execute([$id]);
        return $this->acceso = new Acceso($this->pdo, $id, self::permisosDe((string)$st->fetchColumn()));
    }

    private static function permisosDe(string $rol): array
    {
        return function_exists('roles_todos') ? (roles_todos()[$rol]['permisos'] ?? []) : [];
    }

    /* ---------- Herramientas ---------- */

    public static function herramientas(): array
    {
        $cli = ['type' => 'string', 'description' => 'Cliente: id numérico o parte del nombre'];
        $estado = ['type' => 'string', 'enum' => self::ESTADOS];
        $vacio = ['type' => 'object', 'properties' => new \stdClass()];
        return [
            ['name' => 'listar_clientes', 'description' => 'Lista los clientes del ERP (id, nombre, activo).', 'inputSchema' => $vacio],
            ['name' => 'listar_equipo', 'description' => 'Lista los miembros del equipo (id, usuario).', 'inputSchema' => $vacio],
            ['name' => 'listar_listas', 'description' => 'Lista las listas de tareas de un cliente.', 'inputSchema' => ['type' => 'object', 'properties' => ['cliente' => $cli], 'required' => ['cliente']]],
            ['name' => 'listar_tareas', 'description' => 'Lista tareas. Filtros opcionales por cliente, estado, responsable o texto.', 'inputSchema' => ['type' => 'object', 'properties' => [
                'cliente' => $cli, 'estado' => $estado,
                'responsable' => ['type' => 'string', 'description' => 'id o nombre del responsable'],
                'texto' => ['type' => 'string', 'description' => 'buscar en el título'],
                'limite' => ['type' => 'integer', 'description' => 'máximo de resultados (por defecto 50, máximo 200)'],
            ]]],
            ['name' => 'crear_tarea', 'description' => 'Crea una tarea en un cliente. Devuelve el id creado.', 'inputSchema' => ['type' => 'object', 'properties' => [
                'cliente' => $cli, 'titulo' => ['type' => 'string'],
                'lista' => ['type' => 'string', 'description' => 'nombre de la lista (opcional; si no existe se crea)'],
                'estado' => $estado, 'due_date' => ['type' => 'string', 'description' => 'AAAA-MM-DD'],
                'responsable' => ['type' => 'string', 'description' => 'id o nombre'], 'prioridad' => ['type' => 'integer', 'description' => '0-4'],
                'etiquetas' => ['type' => 'string'],
            ], 'required' => ['cliente', 'titulo']]],
            ['name' => 'actualizar_tarea', 'description' => 'Actualiza campos de una tarea por id.', 'inputSchema' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'titulo' => ['type' => 'string'], 'estado' => $estado, 'prioridad' => ['type' => 'integer'],
                'due_date' => ['type' => 'string', 'description' => 'AAAA-MM-DD o vacío para quitar'], 'responsable' => ['type' => 'string'], 'etiquetas' => ['type' => 'string'],
            ], 'required' => ['id']]],
            ['name' => 'etiquetar_tarea', 'description' => 'Añade etiquetas a una tarea (se suman a las que ya tenga).', 'inputSchema' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'etiquetas' => ['type' => 'string', 'description' => 'separadas por coma'],
            ], 'required' => ['id', 'etiquetas']]],
            ['name' => 'comentar_tarea', 'description' => 'Añade un comentario a una tarea.', 'inputSchema' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'texto' => ['type' => 'string'],
            ], 'required' => ['id', 'texto']]],
        ];
    }

    private function ejecutar(string $nombre, array $a, Acceso $acc): array
    {
        $acc->exigir('ver.tareas');
        switch ($nombre) {
            case 'listar_clientes':
                $st = $this->pdo->query('SELECT id, name, activo FROM clients WHERE 1=1' . $acc->sqlClientes('id') . ' ORDER BY name');
                return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'activo' => (int)$r['activo']], $st->fetchAll(PDO::FETCH_ASSOC));
            case 'listar_equipo':
                return array_map(fn($r) => ['id' => (int)$r['id'], 'username' => (string)$r['username']], $this->pdo->query('SELECT id, username FROM admins WHERE activo = 1 ORDER BY username')->fetchAll(PDO::FETCH_ASSOC));
            case 'listar_listas':
                $cid = $this->cliente($a['cliente'] ?? '', $acc);
                $st = $this->pdo->prepare('SELECT id, nombre FROM task_lists WHERE client_id = ? ORDER BY orden, id');
                $st->execute([$cid]);
                return array_map(fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre']], $st->fetchAll(PDO::FETCH_ASSOC));
            case 'listar_tareas':
                return $this->listarTareas($a, $acc);
            case 'crear_tarea':
                return $this->crearTarea($a, $acc);
            case 'actualizar_tarea':
                $id = $this->tarea($a['id'] ?? 0, $acc);
                $campos = $this->camposTarea($a);
                if (!$campos) throw new \InvalidArgumentException('Nada que actualizar.');
                $t = ($this->tareas)()->actualizar($acc, $id, $campos);
                return ['ok' => true, 'id' => $id, 'mensaje' => 'Tarea actualizada', 'estado' => $t['estado'] ?? null];
            case 'etiquetar_tarea':
                $id = $this->tarea($a['id'] ?? 0, $acc);
                $nuevas = trim((string)($a['etiquetas'] ?? ''));
                if ($nuevas === '') throw new \InvalidArgumentException('Faltan las etiquetas.');
                $st = $this->pdo->prepare('SELECT etiquetas FROM tasks WHERE id = ?');
                $st->execute([$id]);
                $todas = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$st->fetchColumn() . ',' . $nuevas)))));
                ($this->tareas)()->actualizar($acc, $id, ['etiquetas' => implode(', ', $todas)]);
                return ['ok' => true, 'id' => $id, 'etiquetas' => implode(', ', $todas)];
            case 'comentar_tarea':
                $id = $this->tarea($a['id'] ?? 0, $acc);
                $acc->exigir('general.editar');
                $texto = trim((string)($a['texto'] ?? ''));
                if ($texto === '') throw new \InvalidArgumentException('Falta el texto.');
                if (mb_strlen($texto) > 20000) throw new \InvalidArgumentException('El comentario es demasiado largo.');
                $this->pdo->prepare('INSERT INTO task_comments (task_id, admin_id, cuerpo) VALUES (?, ?, ?)')->execute([$id, $acc->adminId, $texto]);
                $cid = (int)$this->pdo->lastInsertId();
                if (function_exists('notif_comment_scan')) notif_comment_scan($id, $cid, $texto, $this->nombre($acc->adminId));
                return ['ok' => true, 'id' => $id, 'comentario_id' => $cid, 'mensaje' => 'Comentario añadido'];
        }
        throw new \InvalidArgumentException('Herramienta desconocida: ' . $nombre);
    }

    private function listarTareas(array $a, Acceso $acc): array
    {
        $w = ['1=1'];
        $p = [];
        if (trim((string)($a['cliente'] ?? '')) !== '') { $w[] = 't.client_id = ?'; $p[] = $this->cliente($a['cliente'], $acc); }
        if (!empty($a['estado'])) {
            if (!in_array($a['estado'], self::ESTADOS, true)) throw new \InvalidArgumentException('Estado desconocido.');
            $w[] = 't.estado = ?'; $p[] = $a['estado'];
        }
        if (trim((string)($a['responsable'] ?? '')) !== '') {
            $rid = $this->persona($a['responsable']) ?? throw new \InvalidArgumentException('No encuentro a esa persona.');
            $w[] = 't.responsable_id = ?'; $p[] = $rid;
        }
        if (trim((string)($a['texto'] ?? '')) !== '') { $w[] = 't.titulo LIKE ?'; $p[] = '%' . self::like((string)$a['texto']) . '%'; }
        $lim = max(1, min(200, (int)($a['limite'] ?? 50) ?: 50));
        $st = $this->pdo->prepare('SELECT t.id, t.titulo, t.estado, t.prioridad, t.due_date, t.etiquetas, c.name AS cliente, l.nombre AS lista, ad.username AS responsable
            FROM tasks t JOIN clients c ON c.id = t.client_id LEFT JOIN task_lists l ON l.id = t.list_id LEFT JOIN admins ad ON ad.id = t.responsable_id
            WHERE ' . implode(' AND ', $w) . $acc->sqlTareas('t') . ' ORDER BY t.updated_at DESC, t.id DESC LIMIT ' . $lim);
        $st->execute($p);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'titulo' => (string)$r['titulo'], 'estado' => (string)$r['estado'], 'prioridad' => (int)$r['prioridad'],
            'due_date' => $r['due_date'] ?: null, 'etiquetas' => (string)($r['etiquetas'] ?? ''), 'cliente' => (string)$r['cliente'],
            'lista' => (string)($r['lista'] ?? ''), 'responsable' => $r['responsable'] !== null ? (string)$r['responsable'] : null], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    private function crearTarea(array $a, Acceso $acc): array
    {
        $acc->exigir('general.editar', 'tareas.crear');
        $cid = $this->cliente($a['cliente'] ?? '', $acc);
        if (trim((string)($a['titulo'] ?? '')) === '') throw new \InvalidArgumentException('Falta el título.');
        $lista = trim((string)($a['lista'] ?? ''));
        $lid = 0;
        if ($lista !== '') {
            $st = $this->pdo->prepare('SELECT id FROM task_lists WHERE client_id = ? AND nombre LIKE ? ORDER BY orden, id LIMIT 1');
            $st->execute([$cid, '%' . self::like($lista) . '%']);
            $lid = (int)$st->fetchColumn();
            if (!$lid) $lid = $this->crearLista($cid, mb_substr($lista, 0, 120));
        }
        if (!$lid) {
            $st = $this->pdo->prepare('SELECT id FROM task_lists WHERE client_id = ? ORDER BY orden, id LIMIT 1');
            $st->execute([$cid]);
            $lid = (int)$st->fetchColumn() ?: $this->crearLista($cid, 'Tareas');
        }
        $t = ($this->tareas)()->crear($acc, ['client_id' => $cid, 'list_id' => $lid, 'titulo' => (string)$a['titulo']] + $this->camposTarea($a));
        $id = (int)($t['id'] ?? 0);
        $front = rtrim((string)(getenv('FRONT_URL') ?: ''), '/');
        return ['ok' => true, 'id' => $id, 'mensaje' => 'Tarea creada', 'url' => ($front !== '' ? $front : '') . '/tareas/' . $id];
    }

    private function crearLista(int $cid, string $nombre): int
    {
        $st = $this->pdo->prepare('SELECT COALESCE(MAX(orden), 0) + 1 FROM task_lists WHERE client_id = ?');
        $st->execute([$cid]);
        $this->pdo->prepare('INSERT INTO task_lists (client_id, nombre, orden) VALUES (?, ?, ?)')->execute([$cid, $nombre, (int)$st->fetchColumn()]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Campos de tarea a partir de los argumentos de la herramienta (para el servicio de Tareas). */
    private function camposTarea(array $a): array
    {
        $c = [];
        if (isset($a['titulo']) && trim((string)$a['titulo']) !== '') $c['titulo'] = (string)$a['titulo'];
        if (isset($a['estado'])) $c['estado'] = (string)$a['estado'];
        if (isset($a['prioridad'])) $c['prioridad'] = $a['prioridad'];
        if (array_key_exists('due_date', $a)) $c['due_date'] = trim((string)$a['due_date']) === '' ? null : (string)$a['due_date'];
        if (isset($a['responsable'])) {
            $c['responsable_id'] = trim((string)$a['responsable']) === '' ? null
                : ($this->persona($a['responsable']) ?? throw new \InvalidArgumentException('No encuentro a esa persona.'));
        }
        if (isset($a['etiquetas'])) $c['etiquetas'] = (string)$a['etiquetas'];
        return $c;
    }

    /** Cliente por id o parte del nombre, dentro del alcance del actor. */
    private function cliente(mixed $v, Acceso $acc): int
    {
        $v = trim((string)$v);
        if ($v === '') throw new \InvalidArgumentException('Falta el cliente.');
        if (ctype_digit($v)) {
            $st = $this->pdo->prepare('SELECT id FROM clients WHERE id = ?' . $acc->sqlClientes('id'));
            $st->execute([(int)$v]);
        } else {
            $st = $this->pdo->prepare('SELECT id FROM clients WHERE name LIKE ?' . $acc->sqlClientes('id') . ' ORDER BY activo DESC, name LIMIT 1');
            $st->execute(['%' . self::like($v) . '%']);
        }
        return (int)$st->fetchColumn() ?: throw new \InvalidArgumentException('Cliente no encontrado.');
    }

    private function tarea(mixed $id, Acceso $acc): int
    {
        $id = (int)$id;
        if ($id <= 0) throw new \InvalidArgumentException('Falta el id.');
        $st = $this->pdo->prepare('SELECT 1 FROM tasks WHERE id = ?');
        $st->execute([$id]);
        if (!$st->fetchColumn() || !$acc->veTarea($id)) throw new \InvalidArgumentException('Tarea no encontrada.');
        return $id;
    }

    /** Persona activa por id o parte del nombre. */
    private function persona(mixed $v): ?int
    {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (ctype_digit($v)) {
            $st = $this->pdo->prepare('SELECT id FROM admins WHERE id = ? AND activo = 1');
            $st->execute([(int)$v]);
        } else {
            $st = $this->pdo->prepare('SELECT id FROM admins WHERE username LIKE ? AND activo = 1 ORDER BY username LIKE ? DESC, username LIMIT 1');
            $st->execute(['%' . self::like($v) . '%', self::like($v)]);
        }
        $r = $st->fetchColumn();
        return $r ? (int)$r : null;
    }

    private function nombre(int $id): string
    {
        $st = $this->pdo->prepare('SELECT username FROM admins WHERE id = ?');
        $st->execute([$id]);
        return (string)$st->fetchColumn();
    }

    private static function like(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($s));
    }

    private static function ok(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function error(mixed $id, int $code, string $msg): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $msg]];
    }

    /** Token de la petición: Authorization: Bearer, ?k= o ?token=. */
    public static function tokenDe(string $autorizacion, array $query): string
    {
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $autorizacion, $m)) return $m[1];
        foreach (['k', 'token'] as $k) if (isset($query[$k]) && is_string($query[$k])) return trim($query[$k]);
        return '';
    }
}
