<?php
namespace Croilab\Modulos\Portal;

use Croilab\Http\HttpError;
use Croilab\Modulos\Clientes\ClientesRepositorio;
use Croilab\Modulos\Clientes\ClientesServicio;
use Croilab\Modulos\Clientes\ContenidoPortal;
use Croilab\Seguridad\Acceso;
use PDO;

/* Lo que hace el equipo con el portal de un cliente: verlo tal cual lo ve el
   cliente (vista previa, solo lectura) y cambiar su contenido desde el editor
   en vivo (antes index.php?cli=N&edit=1 + admin/save-portal.php).

   Permisos (como save-portal.php, pero comprobados todos en el servidor):
     · ver la vista previa o abrir el editor: ver.clientes + alcance (fuera = 404);
     · guardar: general.editar + clientes.portal.
   La identidad (nombre, usuario, tipo…) se valida con el validador de Clientes
   (usuario único, tipo existente…) y los bloques con ContenidoPortal; los
   informes, servicios y Looker (que Clientes no edita) con Contenido. Nunca
   toca met_json, login_email, datos fiscales ni el estado activo. */
class EditorServicio
{
    private const IDENTIDAD = ['name', 'username', 'iniciales', 'saludo', 'actual', 'tipo_id', 'conversiones'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ClientesServicio $clientes,
        private readonly ClientesRepositorio $repoClientes,
        private readonly PortalRepositorio $repo
    ) {}

    /** El cliente si se ve (ver.clientes + alcance); si no, 404/403. */
    public function visible(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.clientes');
        return $this->clientes->visible($acc, $id);
    }

    /** Lo editable, tal cual está guardado. */
    public function leer(Acceso $acc, int $id): array
    {
        $c = $this->visible($acc, $id);
        $servicios = ContenidoPortal::leerServicios($c['servicios_json']);
        $catalogo = function_exists('svc_catalogo') ? array_column(svc_catalogo(), 'nombre') : [];
        /* Opciones de «Servicios contratados»: los seis del portal, el catálogo y lo que ya tuviera. */
        $opciones = [];
        foreach (array_merge(Contenido::SERVICIOS_FIJOS, $catalogo, $servicios ?? []) as $s) {
            if (!in_array(mb_strtolower($s), array_map('mb_strtolower', $opciones), true)) $opciones[] = $s;
        }
        return [
            'contenido' => [
                'name' => (string)$c['name'], 'username' => (string)$c['username'], 'iniciales' => trim((string)$c['iniciales']),
                'saludo' => (string)$c['saludo'], 'actual' => (string)$c['actual'],
                'tipo_id' => $c['tipo_id'] !== null ? (int)$c['tipo_id'] : null, 'conversiones' => (int)$c['conversiones'] === 1,
                'looker' => (string)$c['looker_url'], 'servicios' => $servicios,
                'estado' => ContenidoPortal::leerEstado($c['estado_json']),
                'plan' => ContenidoPortal::leerPlan($c['plan_json']),
                'accesos' => ContenidoPortal::leerAccesos($c['accesos_json']),
                'tareas' => ContenidoPortal::leerProgreso($c['tareas_json']),
                'informes' => Contenido::informesEditables($c['informes_json']),
            ],
            'tipos' => $this->repo->tipos(),
            'servicios_opciones' => $opciones,
            /* publicar_progreso() reescribe estos bloques en cuanto se toca una tarea del cliente. */
            'desde_tareas' => $this->repo->publicaDesdeTareas($id),
            'puede_guardar' => $acc->puede('general.editar') && $acc->puede('clientes.portal'),
        ];
    }

    /** Guarda lo que llega (todo opcional). Una sola transacción. */
    public function guardar(Acceso $acc, int $id, array $d): array
    {
        $this->visible($acc, $id);
        $acc->exigir('general.editar', 'clientes.portal');

        $datos = array_intersect_key($d, array_flip([...self::IDENTIDAD, 'estado', 'plan', 'accesos', 'tareas']));
        $campos = $this->clientes->validar($datos, $id);
        if (array_key_exists('informes', $d)) $campos['informes_json'] = Contenido::validarInformes($d['informes']);
        if (array_key_exists('servicios', $d)) $campos['servicios_json'] = Contenido::validarServicios($d['servicios']);
        if (array_key_exists('looker', $d)) $campos['looker_url'] = Contenido::validarLooker($d['looker']);
        $pass = '';
        if (array_key_exists('password', $d) && $d['password'] !== null && $d['password'] !== '') {
            if (!is_string($d['password'])) throw HttpError::validacion('Contraseña no válida.', 'password');
            $err = password_valida($d['password']);
            if ($err !== '') throw HttpError::validacion($err, 'password');
            $pass = $d['password'];
        }
        if (!$campos && $pass === '') throw HttpError::validacion('No hay nada que cambiar.');

        db_tx_begin($this->pdo);
        try {
            $this->repoClientes->actualizar($id, $campos);
            if ($pass !== '') {
                /* credenciales_cambiar sube cred_ver: el cliente tiene que volver a entrar. */
                $r = credenciales_cambiar('clients', $id, $pass, ['sin_historial' => true]);
                if (empty($r['ok'])) throw HttpError::validacion($r['msg'] ?: 'No se ha podido cambiar la contraseña.', 'password');
            }
        } catch (\Throwable $e) {
            db_tx_rollback($this->pdo);
            throw $e;
        }
        db_tx_commit($this->pdo);
        if (function_exists('audit_log')) audit_log('cliente.portal', "#$id " . implode(',', array_keys($campos)) . ($pass !== '' ? ',password' : ''));
        return $this->leer($acc, $id);
    }

    /** Clientes para el «modo equipo» del acceso al portal (con alcance: el antiguo los listaba todos). */
    public function clientes(Acceso $acc): array
    {
        $acc->exigir('ver.clientes');
        return $this->repo->clientesEquipo($acc->sqlClientes('c.id'));
    }
}
