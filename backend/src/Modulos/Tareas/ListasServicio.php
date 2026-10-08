<?php
namespace Croilab\Modulos\Tareas;

use Croilab\Http\HttpError;
use Croilab\Modulos\Clientes\ClientesRepositorio;
use Croilab\Seguridad\Acceso;

/* Listas de tareas de un cliente (workspace.php §4.3): crear, las cuatro por
   defecto, renombrar, «visible en el portal», reordenar, clonar, borrar a la
   papelera con todo su contenido, el «Informe del mes» de las listas de
   informe y publicar al portal. Siempre dentro del alcance del cliente. */
class ListasServicio
{
    public const TIPOS = ['tareas', 'informe'];
    public const MAX_INFORME = 60000;

    public function __construct(
        private readonly ListasRepositorio $listas,
        private readonly ClientesRepositorio $clientes
    ) {}

    /** Listas de un cliente que se ve (con «visible en el portal»). */
    public function deCliente(Acceso $acc, int $cli): array
    {
        $acc->exigir('ver.tareas');
        return $this->listas->deCliente($this->cliente($acc, $cli));
    }

    /** {client_id, nombre, tipo} o {client_id, por_defecto: true}. Devuelve las listas del cliente. */
    public function crear(Acceso $acc, array $d): array
    {
        $acc->exigir('ver.tareas', 'general.editar', 'tareas.crear');
        $cli = $this->cliente($acc, (int)($d['client_id'] ?? 0));
        if (!empty($d['por_defecto'])) {
            if ($this->listas->deCliente($cli)) throw HttpError::validacion('Este cliente ya tiene listas.', 'por_defecto');
            foreach (ListasRepositorio::POR_DEFECTO as [$n, $t]) $this->listas->crear($cli, $n, $t);
            return ['listas' => $this->listas->deCliente($cli), 'id' => $this->listas->deCliente($cli)[0]['id'] ?? null];
        }
        $tipo = $d['tipo'] ?? 'tareas';
        if (!in_array($tipo, self::TIPOS, true)) throw HttpError::validacion('Tipo de lista desconocido.', 'tipo');
        $nombre = $this->nombre($d['nombre'] ?? null, $tipo === 'informe' ? 'INFORMES CLIENTE' : '');
        /* Una sola lista de informes por cliente (es la que se publica en Informes). */
        if ($tipo === 'informe' && $this->listas->tieneInforme($cli)) throw HttpError::validacion('Este cliente ya tiene su lista de informes.', 'tipo');
        $id = $this->listas->crear($cli, $nombre, $tipo, !empty($d['es_cliente']));
        return ['listas' => $this->listas->deCliente($cli), 'id' => $id];
    }

    /** {nombre?, es_cliente?} */
    public function actualizar(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('ver.tareas', 'general.editar', 'tareas.editar');
        $l = $this->lista($acc, $id);
        $c = [];
        if (array_key_exists('nombre', $d)) $c['nombre'] = $this->nombre($d['nombre']);
        if (array_key_exists('es_cliente', $d)) $c['es_cliente'] = filter_var($d['es_cliente'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        if (!$c) throw HttpError::validacion('No hay nada que cambiar.');
        $this->listas->actualizar($id, $c);
        if (isset($c['es_cliente'])) tareas_publicar_progreso($l['client_id']);
        return $this->listas->lista($id);
    }

    public function reordenar(Acceso $acc, int $cli, mixed $ids): array
    {
        $acc->exigir('ver.tareas', 'general.editar', 'tareas.editar');
        $cli = $this->cliente($acc, $cli);
        if (!is_array($ids) || !$ids) throw HttpError::validacion('Falta el orden.', 'ids');
        $suyas = array_column($this->listas->deCliente($cli), 'id');
        $this->listas->reordenar($cli, array_values(array_filter(array_map('intval', $ids), fn($i) => in_array($i, $suyas, true))));
        return $this->listas->deCliente($cli);
    }

    public function clonar(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.tareas', 'general.editar', 'tareas.crear');
        $l = $this->lista($acc, $id);
        $nueva = $this->listas->clonar($l);
        tareas_publicar_progreso($l['client_id']);
        return ['listas' => $this->listas->deCliente($l['client_id']), 'id' => $nueva];
    }

    /** A la papelera con sus tareas y todo lo de ellas: «Deshacer» la devuelve entera. */
    public function borrar(Acceso $acc, int $id): int
    {
        $acc->exigir('ver.tareas', 'general.editar', 'tareas.borrar');
        $l = $this->lista($acc, $id);
        $hijos = [];
        foreach (TareasServicio::HIJOS_PAPELERA as $h) {
            $h['en'] = [...($h['en'] ?? []), ['tasks', 'list_id']];
            $hijos[] = $h;
        }
        $hijos[] = ['tabla' => 'tasks', 'fk' => 'list_id'];
        $tid = pap_borrar('task_lists', $id, 'lista', $l['nombre'], $hijos);
        if (!$tid) throw new HttpError(500, 'No se ha podido mover la lista a la papelera.', 'papelera');
        tareas_publicar_progreso($l['client_id']);
        return $tid;
    }

    /** El texto del «Informe del mes» y si ya está publicado. */
    public function informe(Acceso $acc, int $id, string $mes): array
    {
        $acc->exigir('ver.tareas');
        $l = $this->lista($acc, $id);
        if ($l['tipo'] !== 'informe') throw HttpError::validacion('Esa lista no es de informes.');
        $mes = $this->mes($mes);
        $e = $this->listas->informeMes($id, $mes);
        $texto = (string)($e['explicacion_cliente'] ?? '') !== '' ? (string)$e['explicacion_cliente'] : (string)($e['descripcion'] ?? '');
        return ['mes' => $mes, 'texto' => $texto, 'publicado' => trim($texto) !== '', 'id' => $e ? (int)$e['id'] : null, 'updated_at' => $e['updated_at'] ?? null];
    }

    /** Guarda y publica el «Informe del mes» (lo lee el cliente en Portal › Informes). */
    public function guardarInforme(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('ver.tareas', 'general.editar', 'tareas.editar');
        $l = $this->lista($acc, $id);
        if ($l['tipo'] !== 'informe') throw HttpError::validacion('Esa lista no es de informes.');
        $mes = $this->mes($d['mes'] ?? '');
        if (!is_string($d['texto'] ?? null)) throw HttpError::validacion('Falta el texto.', 'texto');
        $texto = rtrim(str_replace("\r\n", "\n", $d['texto']));
        if (mb_strlen($texto) > self::MAX_INFORME) throw HttpError::validacion('El informe es demasiado largo.', 'texto');
        $this->listas->guardarInformeMes($l, $mes, $texto);
        tareas_publicar_progreso($l['client_id']);
        return $this->informe($acc, $id, $mes);
    }

    /** Vuelve a generar lo que el cliente ve en su portal (Progreso e Informes). */
    public function publicar(Acceso $acc, int $cli): void
    {
        $acc->exigir('general.editar', 'clientes.portal');
        $cli = $this->cliente($acc, $cli);
        publicar_progreso($cli);
    }

    /* ---------- Auxiliares ---------- */

    private function cliente(Acceso $acc, int $cli): int
    {
        if (!$cli || !$this->clientes->buscar($acc, $cli)) throw HttpError::noEncontrado('Cliente no encontrado.');
        return $cli;
    }

    private function lista(Acceso $acc, int $id): array
    {
        $l = $this->listas->lista($id);
        if (!$l || !$acc->veCliente($l['client_id'])) throw HttpError::noEncontrado('Lista no encontrada.');
        return $l;
    }

    private function nombre(mixed $v, string $porDefecto = ''): string
    {
        if ($v !== null && !is_scalar($v)) throw HttpError::validacion('Nombre no válido.', 'nombre');
        $n = trim(preg_replace('/\s+/u', ' ', (string)$v));
        if ($n === '') $n = $porDefecto;
        if ($n === '') throw HttpError::validacion('Ponle un nombre a la lista.', 'nombre');
        if (mb_strlen($n) > 120) throw HttpError::validacion('El nombre es demasiado largo.', 'nombre');
        return $n;
    }

    private function mes(mixed $v): string
    {
        $m = is_string($v) ? trim($v) : '';
        if ($m === '') throw HttpError::validacion('Falta el mes.', 'mes');
        if (mb_strlen($m) > 40) throw HttpError::validacion('Mes no válido.', 'mes');
        return $m;
    }
}
