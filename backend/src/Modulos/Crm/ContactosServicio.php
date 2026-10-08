<?php
namespace Croilab\Modulos\Crm;

use Croilab\Http\HttpError;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;
use PDO;

/* Reglas de los contactos: permisos (03-crm.md §2.3), alcance, validación de
   cada campo, alta, edición en línea, borrado a la papelera con TODO lo suyo,
   acciones en lote y exportación. */
final class ContactosServicio
{
    /* Hijos que viajan a la papelera con el contacto y vuelven con él. El
       antiguo solo guardaba etiquetas, actividad y comentarios: el resto
       quedaba huérfano (y los negocios desaparecían del tablero). */
    public const HIJOS = ['contact_tags', 'activities', 'comments', 'billing_data', 'proposals', 'attachments', 'list_members', 'follow_up_tasks', 'crm_meetings', 'deals'];

    private const TEXTOS = ['nombre' => 200, 'empresa' => 200, 'sector' => 80, 'email' => 160, 'telefono' => 60, 'whatsapp' => 60,
        'linkedin' => 200, 'web' => 200, 'origen_lead' => 40, 'proxima_accion' => 255];
    public const ORDENES = ['nombre', 'empresa', 'sector', 'valor', 'fase', 'ult', 'prox', 'creado'];
    private const MAX_LOTE = 1000;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ContactosRepositorio $repo,
        private readonly EquipoRepositorio $equipo,
        private readonly Historial $historial
    ) {}

    /* ---------- Lectura ---------- */

    public function listar(Acceso $acc, array $q, int $limit, int $offset): array
    {
        $acc->exigir('ver.crm');
        $f = Filtros::normalizar($q);
        $sort = in_array($q['sort'] ?? '', self::ORDENES, true) ? $q['sort'] : '';
        /* Sin ver las cifras tampoco se puede filtrar ni ordenar por ellas (se adivinarían). */
        if (!$acc->puede('ver.importes')) {
            unset($f['vmin'], $f['vmax']);
            if ($sort === 'valor') $sort = '';
        }
        $dir = ($q['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
        [$items, $total] = $this->repo->listar($acc, $f, $sort, $dir, $limit, $offset);
        return [
            'items' => $items, 'total' => $total, 'total_sin_filtros' => $this->repo->totalVisible($acc),
            'filtros' => (object)$f, 'n_filtros' => Filtros::avanzados($f),
            'negocios_coincidentes' => isset($f['q']) && $offset === 0 ? $this->repo->negociosCoincidentes($acc, $f['q']) : [],
            'limit' => $limit, 'offset' => $offset,
        ];
    }

    /** La fila cruda del contacto si se ve; 404 si no. */
    public function visible(Acceso $acc, int $id): array
    {
        if ($id <= 0) throw HttpError::noEncontrado('Contacto no encontrado.');
        return $this->repo->cruda($acc, $id) ?? throw HttpError::noEncontrado('Contacto no encontrado.');
    }

    /* ---------- Escritura ---------- */

    public function crear(Acceso $acc, array $d): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.crear');
        if (!array_key_exists('nombre', $d)) throw HttpError::validacion('El nombre es obligatorio.', 'nombre');
        $fases = Catalogos::fases($this->pdo);
        $campos = $this->validar($d, $fases);
        unset($campos['fase']);
        $campos['fase'] = isset($fases['lead_nuevo']) ? 'lead_nuevo' : (Catalogos::slugsDeTipo($fases, 'abierta')[0] ?? 'lead_nuevo');
        /* Sin alcance total, un contacto que se crea para otro dejaría de verse al momento. */
        if (!$acc->veTodo() && ($campos['propietario_id'] ?? null) !== null && $campos['propietario_id'] !== $acc->adminId) {
            throw HttpError::validacion('Solo puedes crear contactos para ti o sin propietario.', 'propietario_id');
        }
        $id = $this->repo->insertar($campos);
        $this->historial->anotar($id, null, 'creado', 'Contacto creado');
        if (($campos['propietario_id'] ?? null) && $campos['propietario_id'] !== $acc->adminId) $this->avisarPropietario($acc, $id, (int)$campos['propietario_id'], $campos['empresa'] ?? '' ?: $campos['nombre']);
        return $this->repo->buscar($acc, $id) ?? throw HttpError::noEncontrado('Contacto no encontrado.');
    }

    /** Edición en línea (PATCH): solo los campos que vienen. */
    public function actualizar(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $antes = $this->visible($acc, $id);
        $fases = Catalogos::fases($this->pdo);
        $campos = $this->validar($d, $fases);
        if (!$campos) throw HttpError::validacion('No hay nada que cambiar.');
        $this->repo->actualizar($id, $campos);

        if (array_key_exists('fase', $campos) && $campos['fase'] !== $antes['fase']) {
            $this->historial->anotar($id, null, 'fase', 'Fase cambiada a ' . $fases[$campos['fase']]['nombre']);
        }
        if (array_key_exists('propietario_id', $campos) && $campos['propietario_id'] && $campos['propietario_id'] !== (int)$antes['propietario_id']) {
            $this->avisarPropietario($acc, $id, (int)$campos['propietario_id'], (string)($antes['empresa'] ?: $antes['nombre']));
        }
        /* Si se lo ha pasado a otra persona sin tener alcance total, deja de verlo: se devuelve igual. */
        return $this->repo->buscar($acc, $id) ?? $this->repo->buscar(null, $id) ?? [];
    }

    /** A la papelera con todo lo suyo. Devuelve el id de papelera. */
    public function borrar(Acceso $acc, int $id): int
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.borrar');
        $c = $this->visible($acc, $id);
        return $this->aPapelera($id, trim((string)$c['empresa']) !== '' && trim((string)$c['nombre']) === '' ? (string)$c['empresa'] : (string)$c['nombre']);
    }

    private function aPapelera(int $id, string $titulo): int
    {
        db_tx_begin($this->pdo);
        try {
            /* Las etiquetas de sus negocios cuelgan del negocio, no del contacto:
               se fotografían aparte y se añaden a la foto de la papelera. */
            $deals = $this->pdo->prepare('SELECT id FROM deals WHERE contact_id = ?');
            $deals->execute([$id]);
            $dealIds = array_map('intval', $deals->fetchAll(PDO::FETCH_COLUMN));
            $etiquetasNegocios = [];
            if ($dealIds) {
                $in = implode(',', $dealIds);
                $etiquetasNegocios = $this->pdo->query("SELECT * FROM deal_tags WHERE deal_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            }
            $hijos = array_map(fn($t) => ['tabla' => $t, 'fk' => 'contact_id'], self::HIJOS);
            $tid = pap_borrar('contacts', $id, 'contacto', $titulo !== '' ? $titulo : "Contacto #$id", $hijos);
            if (!$tid) throw new \RuntimeException('pap_borrar devolvió 0');
            if ($dealIds) {
                $this->pdo->exec('DELETE FROM deal_tags WHERE deal_id IN (' . implode(',', $dealIds) . ')');
                $this->anadirAFoto($tid, [['tabla' => 'deal_tags', 'fk' => 'deal_id', 'filas' => $etiquetasNegocios]]);
            }
        } catch (\Throwable $e) {
            db_tx_rollback($this->pdo);
            error_log('CRM borrar contacto #' . $id . ': ' . $e->getMessage());
            throw new HttpError(500, 'No se ha podido mover el contacto a la papelera.', 'papelera');
        }
        db_tx_commit($this->pdo);
        return $tid;
    }

    /* Añade filas hijas a una foto de la papelera (pap_restaurar las devuelve). */
    private function anadirAFoto(int $tid, array $hijos): void
    {
        $st = $this->pdo->prepare('SELECT datos FROM trash WHERE id = ?');
        $st->execute([$tid]);
        $d = json_decode((string)$st->fetchColumn(), true);
        if (!is_array($d)) throw new \RuntimeException('Foto de papelera ilegible');
        $d['hijos'] = array_merge($d['hijos'] ?? [], $hijos);
        $this->pdo->prepare('UPDATE trash SET datos = ? WHERE id = ?')->execute([json_encode($d, JSON_UNESCAPED_UNICODE), $tid]);
    }

    /** «Deshacer» del borrado de un contacto o de un negocio. Devuelve [tipo, id]. */
    public function restaurar(Acceso $acc, int $papeleraId): array
    {
        $acc->exigir('ver.crm', 'general.editar');
        $t = pap_elemento($papeleraId);
        if (!$t || !in_array($t['tipo'], ['contacto', 'negocio'], true)) throw HttpError::noEncontrado('Eso ya no está en la papelera.');
        if ((int)$t['admin_id'] !== $acc->adminId) $acc->exigir('papelera.restaurar');
        $r = pap_restaurar($papeleraId);
        if (empty($r['ok'])) throw new HttpError(409, $r['msg'] ?? 'No se ha podido restaurar.', 'conflicto');
        $id = (int)($r['id'] ?? 0);
        if ($t['tipo'] === 'negocio') {
            $st = $this->pdo->prepare('SELECT contact_id FROM deals WHERE id = ?');
            $st->execute([$id]);
            $cid = (int)$st->fetchColumn();
            if ($cid) $this->historial->sincronizarFase($cid);
        }
        return [(string)$t['tipo'], $id];
    }

    public function etiqueta(Acceso $acc, int $id, int $tagId, bool $on): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $this->visible($acc, $id);
        if (!$this->repo->etiquetaExiste($tagId)) throw HttpError::noEncontrado('Etiqueta no encontrada.');
        $this->repo->ponerEtiqueta($id, $tagId, $on);
        return $this->repo->buscar($acc, $id) ?? [];
    }

    /**
     * Acciones en lote: asignar · etiquetar · a_lista · nueva_lista · borrar.
     * Los ids que no se ven se ignoran. Devuelve lo hecho.
     */
    public function lote(Acceso $acc, array $d): array
    {
        $acc->exigir('ver.crm', 'general.editar');
        $ids = $d['ids'] ?? null;
        if (!is_array($ids) || !$ids) throw HttpError::validacion('Elige al menos un contacto.', 'ids');
        if (count($ids) > self::MAX_LOTE) throw HttpError::validacion('Como mucho ' . self::MAX_LOTE . ' contactos a la vez.', 'ids');
        $ids = array_column($this->repo->porIds($acc, array_map(fn($v) => is_scalar($v) ? (int)$v : 0, $ids)), 'id');
        if (!$ids) throw HttpError::noEncontrado('Contactos no encontrados.');
        $op = (string)($d['op'] ?? '');
        $in = implode(',', $ids);

        switch ($op) {
            case 'asignar':
                $acc->exigir('crm.editar');
                $p = $this->propietario($d['propietario_id'] ?? null);
                if (!$acc->veTodo() && $p !== null && $p !== $acc->adminId) throw HttpError::validacion('Solo puedes asignártelos a ti o dejarlos sin propietario.', 'propietario_id');
                $this->pdo->prepare("UPDATE contacts SET propietario_id = ? WHERE id IN ($in)")->execute([$p]);
                return ['n' => count($ids)];
            case 'etiquetar':
                $acc->exigir('crm.editar');
                $tag = (int)($d['tag_id'] ?? 0);
                if (!$this->repo->etiquetaExiste($tag)) throw HttpError::validacion('Esa etiqueta no existe.', 'tag_id');
                $st = $this->pdo->prepare('INSERT IGNORE INTO contact_tags (contact_id, tag_id) VALUES (?,?)');
                foreach ($ids as $cid) $st->execute([$cid, $tag]);
                return ['n' => count($ids)];
            case 'a_lista':
                $acc->exigir('crm.editar');
                $lista = (int)($d['list_id'] ?? 0);
                $st = $this->pdo->prepare('SELECT 1 FROM lists WHERE id = ?');
                $st->execute([$lista]);
                if (!$st->fetchColumn()) throw HttpError::validacion('Esa lista no existe.', 'list_id');
                $st = $this->pdo->prepare('INSERT IGNORE INTO list_members (list_id, contact_id) VALUES (?,?)');
                foreach ($ids as $cid) $st->execute([$lista, $cid]);
                return ['n' => count($ids), 'list_id' => $lista];
            case 'nueva_lista':
                $acc->exigir('crm.crear');
                $nombre = trim((string)($d['nombre'] ?? ''));
                if ($nombre === '') throw HttpError::validacion('Ponle un nombre a la lista.', 'nombre');
                if (mb_strlen($nombre) > 160) throw HttpError::validacion('El nombre es demasiado largo.', 'nombre');
                db_tx_begin($this->pdo);
                try {
                    $orden = (int)$this->pdo->query('SELECT COALESCE(MAX(orden),0)+1 FROM lists')->fetchColumn();
                    $this->pdo->prepare("INSERT INTO lists (nombre, tipo, condiciones, fecha_congelado, orden) VALUES (?, 'estatica', NULL, NOW(), ?)")->execute([$nombre, $orden]);
                    $lista = (int)$this->pdo->lastInsertId();
                    $st = $this->pdo->prepare('INSERT IGNORE INTO list_members (list_id, contact_id) VALUES (?,?)');
                    foreach ($ids as $cid) $st->execute([$lista, $cid]);
                } catch (\Throwable $e) {
                    db_tx_rollback($this->pdo);
                    throw $e;
                }
                db_tx_commit($this->pdo);
                return ['n' => count($ids), 'list_id' => $lista];
            case 'borrar':
                $acc->exigir('crm.borrar');
                $tids = [];
                $nombres = $this->pdo->query("SELECT id, nombre, empresa FROM contacts WHERE id IN ($in)")->fetchAll();
                foreach ($nombres as $c) $tids[] = $this->aPapelera((int)$c['id'], (string)($c['nombre'] ?: $c['empresa']));
                return ['n' => count($tids), 'papelera_ids' => $tids];
        }
        throw HttpError::validacion('Acción desconocida.', 'op');
    }

    /** CSV de los contactos elegidos (ids) o de los que cumplen los filtros. */
    public function exportar(Acceso $acc, array $q): array
    {
        $acc->exigir('ver.crm');
        $ids = isset($q['ids']) && is_string($q['ids']) && trim($q['ids']) !== ''
            ? array_slice(array_map('intval', explode(',', $q['ids'])), 0, 5000)
            : $this->repo->ids($acc, Filtros::normalizar($q));
        $filas = $this->repo->porIds($acc, $ids);
        return ['nombre' => 'contactos.csv', 'csv' => $this->csv($filas, $acc->puede('ver.importes'))];
    }

    /** Las 10 columnas del export del antiguo (también las de las listas). */
    public function csv(array $filas, bool $importes = true): string
    {
        $fases = Catalogos::fases($this->pdo);
        $nombres = $this->equipo->nombres();
        return Csv::generar(
            ['Nombre', 'Empresa', 'Sector', 'Email', 'Teléfono', 'WhatsApp', 'Origen', 'Valor', 'Embudo', 'Propietario'],
            array_map(fn($c) => [
                $c['nombre'], $c['empresa'], $c['sector'], $c['email'], $c['telefono'], $c['whatsapp'], $c['origen_lead'],
                $importes && $c['valor'] !== null ? number_format($c['valor'], 2, ',', '') : '',
                $fases[$c['fase']]['nombre'] ?? $c['fase'],
                $c['propietario_id'] ? ($nombres[$c['propietario_id']] ?? '') : '',
            ], $filas)
        );
    }

    /* ---------- Validación ---------- */

    /** Campos editables presentes en $d, listos para la base. Lo que no viene no se toca. */
    public function validar(array $d, array $fases): array
    {
        $c = [];
        foreach (self::TEXTOS as $k => $max) {
            if (!array_key_exists($k, $d)) continue;
            if ($d[$k] !== null && !is_scalar($d[$k])) throw HttpError::validacion('Valor no válido.', $k);
            $v = trim((string)$d[$k]);
            if (mb_strlen($v) > $max) throw HttpError::validacion('Texto demasiado largo.', $k);
            if ($k === 'nombre' && $v === '') throw HttpError::validacion('El nombre es obligatorio.', 'nombre');
            if ($k === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) throw HttpError::validacion('Ese email no es válido.', 'email');
            $c[$k] = $k === 'nombre' ? $v : ($v === '' ? null : $v);
        }
        if (array_key_exists('valor', $d)) $c['valor'] = Dinero::leer($d['valor'], 'valor');
        foreach (['fecha_prox', 'fecha_ultimo_contacto'] as $k) {
            if (!array_key_exists($k, $d)) continue;
            $v = $d[$k];
            $c[$k] = $v === null || $v === '' ? null : Filtros::fecha(is_string($v) ? $v : '', $k);
        }
        if (array_key_exists('propietario_id', $d)) $c['propietario_id'] = $this->propietario($d['propietario_id']);
        if (array_key_exists('servicios', $d)) {
            if (!is_array($d['servicios'])) throw HttpError::validacion('Servicios no válidos.', 'servicios');
            $s = [];
            foreach ($d['servicios'] as $v) {
                if (!is_string($v) || trim($v) === '' || mb_strlen(trim($v)) > 40) throw HttpError::validacion('Servicio no válido.', 'servicios');
                $s[trim($v)] = true;
            }
            $json = json_encode(array_keys($s), JSON_UNESCAPED_UNICODE);
            if (strlen($json) > 255) throw HttpError::validacion('Demasiados servicios.', 'servicios');
            $c['servicio_json'] = $s ? $json : null;
        }
        if (array_key_exists('fase', $d)) {
            if (!is_string($d['fase']) || !isset($fases[$d['fase']])) throw HttpError::validacion('Esa fase no existe.', 'fase');
            $c['fase'] = $d['fase'];
        }
        return $c;
    }

    public function propietario(mixed $v): ?int
    {
        if ($v === null || $v === '' || $v === 0 || $v === '0') return null;
        $id = filter_var($v, FILTER_VALIDATE_INT);
        if (!$id || !$this->equipo->existe($id)) throw HttpError::validacion('Esa persona no existe.', 'propietario_id');
        return $id;
    }

    private function avisarPropietario(Acceso $acc, int $id, int $para, string $nombre): void
    {
        if ($para === $acc->adminId || !function_exists('notif_add')) return;
        notif_add($para, 'lead', 'te ha asignado el contacto ' . $nombre, '', '/crm/contactos/' . $id,
            'ctoasig:' . $id . ':' . $para . ':' . date('YmdHis'), '', $this->equipo->persona($acc->adminId)['username']);
    }
}
