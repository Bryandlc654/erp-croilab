<?php
namespace Croilab\Modulos\Clientes;

use Croilab\Http\HttpError;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;
use PDO;

/* Reglas de los clientes: permisos, alcance, validación de la ficha, alta con
   sus listas, borrado a la papelera con todo lo suyo, duplicado y contraseña
   del portal. Lo que antes estaba repartido entre edit.php, client.php,
   delete.php y duplicate.php.

   Fuera de su alcance un cliente responde igual que si no existiera (404). */
class ClientesServicio
{
    /* Hijos que se van a la papelera con el cliente y vuelven con él. */
    private const HIJOS_PAPELERA = ['tasks', 'task_lists', 'client_credentials', 'support_tickets', 'invoice_schedules', 'projects', 'portal_meeting_requests'];
    /* Tablas que solo pierden la referencia: las facturas y la contabilidad no se borran nunca con el cliente. */
    private const SUELTAN_REFERENCIA = ['accounting', 'invoices', 'contacts', 'deals'];
    private const CAMPOS_PORTAL = ['estado', 'plan', 'accesos', 'tareas'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ClientesRepositorio $repo,
        private readonly FichaRepositorio $ficha,
        private readonly EquipoRepositorio $equipo
    ) {}

    /* ---------- Lectura ---------- */

    /** @return array{0: array, 1: int} */
    public function listar(Acceso $acc, array $f, int $limit, int $offset, bool $actividad): array
    {
        $acc->exigir('ver.clientes');
        [$items, $total] = $this->repo->listar($acc, $f, $limit, $offset);
        if ($actividad && $items) {
            $act = $this->repo->actividad(array_column($items, 'id'));
            $importes = $acc->puede('ver.importes');
            foreach ($items as &$c) {
                $a = $act[$c['id']];
                if (!$importes) $a['pendiente_cobro'] = null;
                $c += $a;
            }
        }
        return [$items, $total];
    }

    /** La ficha completa (hub): datos, resumen y lo de los demás módulos. */
    public function ficha(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.clientes');
        $c = $this->visible($acc, $id);
        $importes = $acc->puede('ver.importes');
        $met = ContenidoPortal::leerMetricas($c['met_json']);
        $progreso = ContenidoPortal::leerProgreso($c['tareas_json']);
        $facturas = $this->ficha->facturas($id);
        $tickets = $this->ficha->tickets($id);
        $contactId = $c['contact_id'] !== null ? (int)$c['contact_id'] : null;
        $ultimo = $met ? $met[count($met) - 1] : null;
        $fiscales = ['nombre' => (string)$c['fact_nombre'], 'nif' => (string)$c['fact_nif'], 'dir' => (string)$c['fact_dir'], 'email' => (string)$c['fact_email']];
        $faltan = count(array_filter([$fiscales['nombre'] ?: $c['name'], $fiscales['nif'], $fiscales['dir'], $fiscales['email']], fn($v) => trim((string)$v) === ''));
        $sinImporte = fn(int $v) => $importes ? $v : null;

        return [
            'cliente' => $this->cabecera($c) + ['fact' => $fiscales + ['tel' => (string)$c['fact_tel']], 'faltan_fiscales' => $faltan],
            'estado' => ContenidoPortal::leerEstado($c['estado_json']),
            'plan' => ContenidoPortal::leerPlan($c['plan_json']),
            'resumen' => [
                'oportunidades' => $ultimo ? ['mes' => $ultimo['mes'], 'valor' => $ultimo['total']] : null,
                'tareas_en_curso' => array_sum(array_map(fn($m) => count($m['pendiente']), $progreso)),
                'soporte_abierto' => $tickets['abiertos'],
                'cobrado' => $sinImporte($facturas['cobrado']),
            ],
            'listas' => $this->ficha->listas($id),
            'facturas' => [
                'n' => $facturas['n'], 'cobrado' => $sinImporte($facturas['cobrado']), 'pendiente' => $sinImporte($facturas['pendiente']),
                'ultimas' => array_map(fn($f) => ['total' => $sinImporte($f['total'])] + $f, $facturas['ultimas']),
            ],
            'tickets' => $tickets,
            /* La bóveda tiene su propio permiso: sin él la ficha no enseña ni los títulos. */
            'credenciales' => $acc->puede('ver.credenciales') ? $this->ficha->credenciales($id) : null,
            'contacto' => $this->ficha->contacto($id, $contactId),
            'reuniones' => $this->ficha->reuniones($id, $contactId, date('Y-m-d')),
        ];
    }

    /** Lo que edita el formulario de alta/edición. */
    public function datos(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.clientes');
        $c = $this->visible($acc, $id);
        return $this->cabecera($c) + [
            'fact_nombre' => (string)$c['fact_nombre'], 'fact_nif' => (string)$c['fact_nif'], 'fact_dir' => (string)$c['fact_dir'],
            'fact_email' => (string)$c['fact_email'], 'fact_tel' => (string)$c['fact_tel'],
            'estado' => ContenidoPortal::leerEstado($c['estado_json']),
            'plan' => ContenidoPortal::leerPlan($c['plan_json']),
            'accesos' => ContenidoPortal::leerAccesos($c['accesos_json']),
            'tareas' => ContenidoPortal::leerProgreso($c['tareas_json']),
        ];
    }

    /** Revela una contraseña guardada en la bóveda del cliente (bajo demanda, auditado). */
    public function secreto(Acceso $acc, int $id, int $credId): string
    {
        $acc->exigir('ver.clientes', 'ver.credenciales');
        $this->visible($acc, $id);
        $v = $this->ficha->secreto($id, $credId) ?? throw HttpError::noEncontrado('Credencial no encontrada.');
        /* Equipo cifrará la bóveda (propósito 'cred_cliente'); lo que siga en claro se devuelve tal cual. */
        if (function_exists('boveda_esta_cifrada') && boveda_esta_cifrada($v)) {
            $r = boveda_descifrar($v, 'cred_cliente');
            if (empty($r['ok'])) throw new HttpError(500, 'No se ha podido descifrar la credencial.', 'boveda');
            $v = (string)$r['v'];
        }
        if (function_exists('audit_log')) audit_log('credencial.ver', "cliente #$id · credencial #$credId");
        return $v;
    }

    /* ---------- Escritura ---------- */

    public function crear(Acceso $acc, array $datos): int
    {
        $acc->exigir('general.editar', 'clientes.crear');
        foreach (['name', 'username'] as $k) if (!array_key_exists($k, $datos)) $datos[$k] = '';
        $pass = $this->password($datos['password'] ?? '', true);
        $campos = $this->validar($datos, 0) + [
            'iniciales' => 'CL', 'conversiones' => 1, 'activo' => 1,
            'estado_json' => ContenidoPortal::estado(null), 'plan_json' => ContenidoPortal::plan(null),
            'accesos_json' => '[]', 'tareas_json' => '{}',
        ];
        $campos['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);

        db_tx_begin($this->pdo);
        try {
            $id = $this->repo->insertar($campos);
            $this->repo->crearListasPorDefecto($id);
        } catch (\Throwable $e) {
            db_tx_rollback($this->pdo);
            throw $e;
        }
        db_tx_commit($this->pdo);
        $this->despuesDeAlta($acc, $id, 'cliente.crear');
        return $id;
    }

    public function actualizar(Acceso $acc, int $id, array $datos): void
    {
        $acc->exigir('general.editar', 'clientes.editar');
        $c = $this->visible($acc, $id);
        $campos = $this->validar($datos, $id);
        /* Lo que ve el cliente en su portal tiene su propio permiso: solo se pide si cambia de verdad. */
        foreach (self::CAMPOS_PORTAL as $k) {
            $col = $k . '_json';
            if (array_key_exists($col, $campos) && $campos[$col] !== $this->normalizado($k, $c[$col])) {
                $acc->exigir('clientes.portal');
                break;
            }
        }
        $pass = array_key_exists('password', $datos) ? $this->password($datos['password'], false) : '';
        if (!$campos && $pass === '') throw HttpError::validacion('No hay nada que cambiar.');

        $this->repo->actualizar($id, $campos);
        if ($pass !== '') $this->cambiarPassword($id, $pass, false);
        if (function_exists('audit_log')) audit_log('cliente.editar', "#$id " . implode(',', array_keys($campos)) . ($pass !== '' ? ',password' : ''));
    }

    /** Genera una contraseña nueva para el portal; se devuelve una sola vez. */
    public function restablecerPassword(Acceso $acc, int $id): string
    {
        $acc->exigir('general.editar', 'clientes.editar');
        $this->visible($acc, $id);
        $nueva = password_generar(12);
        $this->cambiarPassword($id, $nueva, true);
        return $nueva;
    }

    /** Copia del cliente para usarla de plantilla. [id, contraseña nueva]. */
    public function duplicar(Acceso $acc, int $id): array
    {
        $acc->exigir('general.editar', 'clientes.crear');
        $c = $this->visible($acc, $id);
        $username = $this->usuarioCopia((string)$c['username'] ?: 'cliente');
        $pass = password_generar(12);
        $campos = [
            'name' => mb_substr($c['name'] . ' (copia)', 0, 160), 'username' => $username,
            'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
            'activo' => 1,
        ];
        /* Lo que define cómo trabaja con él; nunca sus datos fiscales, sus
           métricas (son de otra web), sus informes ni su correo de Google. */
        foreach (['iniciales', 'saludo', 'conversiones', 'tipo_id', 'actual', 'estado_json', 'plan_json', 'accesos_json', 'tareas_json', 'servicios_json', 'partner_id'] as $k) {
            $campos[$k] = $c[$k];
        }
        db_tx_begin($this->pdo);
        try {
            $nuevo = $this->repo->insertar($campos);
            $this->repo->crearListasPorDefecto($nuevo);
        } catch (\Throwable $e) {
            db_tx_rollback($this->pdo);
            throw $e;
        }
        db_tx_commit($this->pdo);
        $this->despuesDeAlta($acc, $nuevo, 'cliente.duplicar', "desde #$id");
        return [$nuevo, $pass];
    }

    /**
     * A la papelera con sus tareas, listas, credenciales, tickets, programaciones,
     * proyectos y solicitudes de reunión. Las horas, facturas, apuntes, contactos y
     * negocios se quedan y solo sueltan la referencia. Todo o nada.
     */
    public function borrar(Acceso $acc, int $id): int
    {
        $acc->exigir('general.editar', 'clientes.borrar');
        $c = $this->visible($acc, $id);
        $nombre = trim((string)$c['name']) !== '' ? (string)$c['name'] : "Cliente #$id";

        db_tx_begin($this->pdo);
        try {
            $st = $this->pdo->prepare('SELECT id FROM tasks WHERE client_id = ?');
            $st->execute([$id]);
            $tareas = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            /* Comentarios, checklist y adjuntos de las tareas no viajan a la papelera (como antes). */
            pap_borrar_hijos_tareas($tareas);

            /* Lo que solo suelta la referencia se anota para volver a enlazarlo al restaurar. */
            $enTareas = $tareas ? ' OR te.task_id IN (' . implode(',', $tareas) . ')' : '';
            $refs = ['time_entries' => $this->pdo->query('SELECT te.id, te.task_id FROM time_entries te WHERE te.client_id = ' . $id . $enTareas)->fetchAll(PDO::FETCH_NUM)];
            foreach (self::SUELTAN_REFERENCIA as $t) {
                if (db_tabla_existe($t, $this->pdo)) $refs[$t] = array_map('intval', $this->pdo->query("SELECT id FROM `$t` WHERE client_id = $id")->fetchAll(PDO::FETCH_COLUMN));
            }

            /* Las horas no se borran: se les pone un concepto que se entienda sin el cliente. */
            $this->pdo->prepare("UPDATE time_entries te LEFT JOIN tasks t ON t.id = te.task_id
                                    SET te.concepto = LEFT(CASE WHEN t.id IS NOT NULL THEN CONCAT(t.titulo, ' · ', ?) ELSE CONCAT('Horas de ', ?) END, 255)
                                  WHERE (te.client_id = ?$enTareas) AND (te.concepto IS NULL OR TRIM(te.concepto) = '' OR te.concepto = 'Horas de la tarea')")
                ->execute([$nombre, $nombre, $id]);
            $this->pdo->prepare('UPDATE time_entries te SET te.client_id = NULL, te.task_id = NULL WHERE te.client_id = ?' . $enTareas)->execute([$id]);

            /* La factura conserva a quién se hizo aunque el cliente ya no esté. */
            $this->pdo->prepare("UPDATE invoices SET cliente_nombre = ? WHERE client_id = ? AND (cliente_nombre IS NULL OR cliente_nombre = '')")
                ->execute([mb_substr($nombre, 0, 200), $id]);
            foreach (self::SUELTAN_REFERENCIA as $t) {
                if (db_tabla_existe($t, $this->pdo)) $this->pdo->prepare("UPDATE `$t` SET client_id = NULL WHERE client_id = ?")->execute([$id]);
            }

            $hijos = array_map(fn($t) => ['tabla' => $t, 'fk' => 'client_id'], self::HIJOS_PAPELERA);
            $tid = pap_borrar('clients', $id, 'cliente', $nombre, $hijos);
            if (!$tid) throw new \RuntimeException('pap_borrar devolvió 0');
            $this->anotarReferencias($tid, $refs);
            /* Las respuestas de los tickets se quedan: el ticket vuelve con su mismo id al restaurar. */
        } catch (\Throwable $e) {
            db_tx_rollback($this->pdo);
            error_log("Borrar cliente #$id: " . $e->getMessage());
            throw new HttpError(500, 'No se ha podido borrar el cliente. No se ha tocado nada.', 'papelera');
        }
        db_tx_commit($this->pdo);
        if (function_exists('audit_log')) audit_log('cliente.borrar', "#$id $nombre → papelera #$tid");
        return $tid;
    }

    /** «Deshacer» de un borrado de cliente. Quien borró puede; si no, hace falta el permiso de la papelera. */
    public function restaurar(Acceso $acc, int $papeleraId): int
    {
        $acc->exigir('general.editar');
        $t = pap_elemento($papeleraId);
        if (!$t || $t['tipo'] !== 'cliente') throw HttpError::noEncontrado('Eso ya no está en la papelera.');
        if ((int)$t['admin_id'] !== $acc->adminId) $acc->exigir('papelera.restaurar');
        // pap_restaurar() también vuelve a enlazar facturas, apuntes, horas… (pap_reenlazar_refs).
        $r = pap_restaurar($papeleraId);
        if (empty($r['ok'])) throw new HttpError(409, $r['msg'] ?? 'No se ha podido restaurar.', 'conflicto');
        $id = (int)($r['id'] ?? 0);
        if (function_exists('audit_log')) audit_log('cliente.restaurar', "#$id desde papelera #$papeleraId");
        return $id;
    }

    /* Guarda en la foto de la papelera qué filas soltaron la referencia al cliente. */
    private function anotarReferencias(int $tid, array $refs): void
    {
        $st = $this->pdo->prepare('SELECT datos FROM trash WHERE id = ?');
        $st->execute([$tid]);
        $d = json_decode((string)$st->fetchColumn(), true);
        if (!is_array($d)) return;
        $d['refs'] = $refs;
        $this->pdo->prepare('UPDATE trash SET datos = ? WHERE id = ?')->execute([json_encode($d, JSON_UNESCAPED_UNICODE), $tid]);
    }

    /* ---------- Validación ---------- */

    /**
     * Valida los campos de la ficha presentes en $datos y los devuelve como
     * columnas. Lo que no viene no se toca. Nunca toca met_json, informes_json,
     * servicios_json, looker_url, partner_id ni ga4_*.
     */
    public function validar(array $datos, int $id): array
    {
        $c = [];
        if (array_key_exists('name', $datos)) {
            $c['name'] = ContenidoPortal::texto($datos['name'], 160, 'name');
            if ($c['name'] === '') throw HttpError::validacion('El nombre es obligatorio.', 'name');
        }
        if (array_key_exists('username', $datos)) {
            $u = ContenidoPortal::texto($datos['username'], 80, 'username');
            if ($u === '') throw HttpError::validacion('El usuario es obligatorio.', 'username');
            if (preg_match('/\s/u', $u)) throw HttpError::validacion('El usuario no puede llevar espacios.', 'username');
            if ($this->repo->usuarioOcupado($u, $id)) throw HttpError::validacion('Ese usuario ya existe, elige otro.', 'username');
            $c['username'] = $u;
        }
        if (array_key_exists('iniciales', $datos)) {
            $i = ContenidoPortal::texto($datos['iniciales'], 4, 'iniciales');
            $c['iniciales'] = $i === '' ? 'CL' : mb_strtoupper($i);
        }
        foreach (['saludo' => 160, 'fact_nombre' => 200, 'fact_nif' => 40, 'fact_dir' => 300] as $k => $max) {
            if (array_key_exists($k, $datos)) $c[$k] = ContenidoPortal::texto($datos[$k], $max, $k);
        }
        if (array_key_exists('actual', $datos)) $c['actual'] = ContenidoPortal::texto($datos['actual'], 40, 'actual');
        if (array_key_exists('fact_email', $datos)) $c['fact_email'] = $this->email($datos['fact_email'], 'fact_email', 'El email de facturación no es válido.');
        if (array_key_exists('login_email', $datos)) {
            $e = mb_strtolower($this->email($datos['login_email'], 'login_email', 'El correo de Google no es válido.'));
            if ($e !== '' && $this->repo->loginEmailOcupado($e, $id)) throw HttpError::validacion('Ese correo de Google ya está en otro cliente.', 'login_email');
            $c['login_email'] = $e;
        }
        foreach (['conversiones', 'activo'] as $k) {
            if (array_key_exists($k, $datos)) $c[$k] = filter_var($datos[$k], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (array_key_exists('tipo_id', $datos)) {
            $t = $datos['tipo_id'];
            if ($t === null || $t === '' || $t === 0) $c['tipo_id'] = null;
            else {
                $t = filter_var($t, FILTER_VALIDATE_INT);
                if (!$t || !$this->repo->tipoExiste($t)) throw HttpError::validacion('Ese tipo de cliente no existe.', 'tipo_id');
                $c['tipo_id'] = $t;
            }
        }
        if (array_key_exists('estado', $datos)) $c['estado_json'] = ContenidoPortal::estado($datos['estado']);
        if (array_key_exists('plan', $datos)) $c['plan_json'] = ContenidoPortal::plan($datos['plan']);
        if (array_key_exists('accesos', $datos)) $c['accesos_json'] = ContenidoPortal::accesos($datos['accesos']);
        if (array_key_exists('tareas', $datos)) $c['tareas_json'] = ContenidoPortal::progreso($datos['tareas']);
        return $c;
    }

    /* ---------- Ayudas ---------- */

    /** La fila del cliente si se ve; si no, 404 (fuera de alcance = no existe). */
    public function visible(Acceso $acc, int $id): array
    {
        if (!$id || !$acc->veCliente($id)) throw HttpError::noEncontrado('Cliente no encontrado.');
        return $this->repo->fila($id) ?? throw HttpError::noEncontrado('Cliente no encontrado.');
    }

    private function cabecera(array $c): array
    {
        return [
            'id' => (int)$c['id'], 'name' => (string)$c['name'], 'username' => (string)$c['username'],
            'iniciales' => trim((string)$c['iniciales']), 'saludo' => (string)$c['saludo'],
            'conversiones' => (int)$c['conversiones'] === 1, 'activo' => (int)$c['activo'] === 1, 'actual' => (string)$c['actual'],
            'tipo_id' => $c['tipo_id'] !== null ? (int)$c['tipo_id'] : null,
            'tipo_nombre' => $c['tipo_nombre'] !== null ? (string)$c['tipo_nombre'] : null,
            'login_email' => (string)$c['login_email'],
            'partner_id' => $c['partner_id'] !== null ? (int)$c['partner_id'] : null,
            'contact_id' => $c['contact_id'] !== null ? (int)$c['contact_id'] : null,
            'google' => trim((string)($c['gsc_site_url'] ?? '')) !== '' || trim((string)($c['ga4_property_id'] ?? '')) !== '',
        ];
    }

    /* El JSON guardado pasado por el mismo validador, para comparar con lo que llega. */
    private function normalizado(string $k, ?string $json): string
    {
        try {
            return match ($k) {
                'estado' => ContenidoPortal::estado(ContenidoPortal::leerEstado($json)),
                'plan' => ContenidoPortal::plan(ContenidoPortal::leerPlan($json)),
                'accesos' => ContenidoPortal::accesos(ContenidoPortal::leerAccesos($json)),
                'tareas' => ContenidoPortal::progreso(ContenidoPortal::leerProgreso($json)),
            };
        } catch (HttpError) {
            return '';   // lo guardado ya no cuadra con las reglas: cualquier cambio cuenta como cambio
        }
    }

    private function email(mixed $v, string $campo, string $msg): string
    {
        $e = ContenidoPortal::texto($v, 160, $campo);
        if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) throw HttpError::validacion($msg, $campo);
        return $e;
    }

    private function password(mixed $v, bool $obligatoria): string
    {
        if ($v !== null && !is_string($v)) throw HttpError::validacion('Contraseña no válida.', 'password');
        $p = (string)$v;
        if ($p === '') {
            if ($obligatoria) throw HttpError::validacion('Pon una contraseña para el cliente.', 'password');
            return '';
        }
        $err = password_valida($p);
        if ($err !== '') throw HttpError::validacion($err, 'password');
        return $p;
    }

    /* Siempre por credenciales_cambiar(): guarda el hash y sube cred_ver, que
       cierra las sesiones del portal que tuviera abiertas. */
    private function cambiarPassword(int $id, string $pass, bool $generada): void
    {
        $r = credenciales_cambiar('clients', $id, $pass, ['sin_historial' => $generada]);
        if (empty($r['ok'])) throw HttpError::validacion($r['msg'] ?: 'No se ha podido cambiar la contraseña.', 'password');
    }

    private function usuarioCopia(string $base): string
    {
        $base = mb_substr($base, 0, 60);
        for ($n = 1; $n <= 200; $n++) {
            $u = $base . '_copia' . ($n > 1 ? $n : '');
            if (!$this->repo->usuarioOcupado($u)) return $u;
        }
        return $base . '_copia_' . time();
    }

    private function despuesDeAlta(Acceso $acc, int $id, string $accion, string $detalle = ''): void
    {
        $actor = $this->equipo->persona($acc->adminId)['username'];
        if (function_exists('notif_client_new')) notif_client_new($id, $actor);
        if (function_exists('audit_log')) audit_log($accion, trim("#$id $detalle"));
    }
}
