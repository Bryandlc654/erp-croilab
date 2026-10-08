<?php
namespace Croilab\Modulos\Crm;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* El embudo de negocios (negocio.php): tablero por fases con sus métricas,
   alta, edición, movimientos (con el motivo al perder), archivo, papelera y
   etiquetas. Un negocio se ve si se ve su contacto. */
final class NegociosServicio
{
    /* La factura de un negocio la crea Finanzas y la enlaza en invoices.deal_id. */
    private const COLUMNAS = 'd.*, c.nombre AS c_nombre, c.empresa AS c_empresa, c.sector AS c_sector, c.client_id AS c_client,
        (SELECT i.id FROM invoices i WHERE i.deal_id = d.id ORDER BY i.id DESC LIMIT 1) AS factura_id';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ContactosServicio $contactos,
        private readonly Historial $historial
    ) {}

    public function listar(Acceso $acc, bool $archivados): array
    {
        $acc->exigir('ver.crm');
        $fases = Catalogos::fases($this->pdo);
        $st = $this->pdo->prepare('SELECT ' . self::COLUMNAS . ' FROM deals d JOIN contacts c ON c.id = d.contact_id
                                    WHERE d.archivado = ?' . Alcance::sql($acc) . ' ORDER BY d.orden, d.id DESC');
        $st->execute([$archivados ? 1 : 0]);
        $items = $this->conEtiquetas(array_map(fn($r) => $this->fila($r, $fases), $st->fetchAll()));
        return ['items' => $items, 'metricas' => $archivados ? null : $this->metricas($acc, $fases)];
    }

    /* Las cinco tarjetas de arriba del tablero. Ganado y perdido cuentan también
       los archivados (se cerraron igual); abiertos y pipeline, solo el tablero. */
    private function metricas(Acceso $acc, array $fases): array
    {
        $alc = Alcance::sql($acc);
        $lista = fn(array $s) => $s ? implode(',', array_map(fn($x) => $this->pdo->quote($x), $s)) : "''";
        $abiertas = $lista(Catalogos::slugsDeTipo($fases, 'abierta'));
        $ganadas = $lista(Catalogos::slugsDeTipo($fases, 'ganada'));
        $perdidas = $lista(Catalogos::slugsDeTipo($fases, 'perdida'));
        $base = 'FROM deals d JOIN contacts c ON c.id = d.contact_id WHERE 1=1' . $alc;
        $a = $this->pdo->query("SELECT COUNT(*) n, COALESCE(SUM(d.valor),0) v $base AND d.archivado = 0 AND d.fase IN ($abiertas)")->fetch();
        $st = $this->pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(d.valor),0) v $base AND d.fase IN ($ganadas) AND d.fecha_cierre_real >= ?");
        $st->execute([date('Y-m-01')]);
        $mes = $st->fetch();
        $g = $this->pdo->query("SELECT COUNT(*) n, AVG(d.valor) m $base AND d.fase IN ($ganadas)")->fetch();
        $p = (int)$this->pdo->query("SELECT COUNT(*) $base AND d.fase IN ($perdidas)")->fetchColumn();
        $ng = (int)$g['n'];
        return [
            'abiertos' => (int)$a['n'], 'valor_pipeline' => Dinero::num($a['v']) ?? 0.0,
            'ganado_mes' => Dinero::num($mes['v']) ?? 0.0, 'n_ganado_mes' => (int)$mes['n'],
            'conversion' => $ng + $p > 0 ? (int)round($ng * 100 / ($ng + $p)) : 0,
            'ticket_medio' => Dinero::num($g['m']) ?? 0.0, 'n_ganados' => $ng, 'n_perdidos' => $p,
        ];
    }

    public function detalle(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.crm');
        return $this->buscar($acc, $id);
    }

    public function crear(Acceso $acc, array $d): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.crear');
        $cid = filter_var($d['contact_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$cid) throw HttpError::validacion('Elige un contacto.', 'contact_id');
        try {
            $c = $this->contactos->visible($acc, $cid);
        } catch (HttpError) {
            throw HttpError::validacion('Ese contacto no existe.', 'contact_id');
        }
        $fases = Catalogos::fases($this->pdo);
        $fase = isset($fases['lead_nuevo']) ? 'lead_nuevo' : (Catalogos::slugsDeTipo($fases, 'abierta')[0] ?? 'lead_nuevo');
        $nombre = is_scalar($d['nombre'] ?? null) ? trim((string)$d['nombre']) : '';
        if (mb_strlen($nombre) > 200) throw HttpError::validacion('El nombre es demasiado largo.', 'nombre');
        $valor = array_key_exists('valor', $d) && $d['valor'] !== '' && $d['valor'] !== null ? Dinero::leer($d['valor']) : ($c['valor'] !== null ? (string)$c['valor'] : null);
        $cierre = ($d['fecha_cierre_prevista'] ?? '') !== '' && ($d['fecha_cierre_prevista'] ?? null) !== null
            ? Filtros::fecha(is_string($d['fecha_cierre_prevista']) ? $d['fecha_cierre_prevista'] : '', 'fecha_cierre_prevista') : null;
        $svc = json_decode((string)($c['servicio_json'] ?? ''), true);
        $servicio = is_array($svc) && $svc && is_string($svc[0]) ? mb_substr($svc[0], 0, 80) : null;
        $nombre = $nombre !== '' ? $nombre : (string)$c['nombre'];

        $this->pdo->prepare('INSERT INTO deals (contact_id, nombre, valor, servicio, fase, probabilidad, fecha_cierre_prevista, fecha_entrada_fase, propietario_id, client_id)
                             VALUES (?,?,?,?,?,?,?,CURDATE(),?,?)')
            ->execute([$cid, $nombre, $valor, $servicio, $fase, $fases[$fase]['probabilidad'] ?? 0, $cierre, $c['propietario_id'], $c['client_id']]);
        $id = (int)$this->pdo->lastInsertId();
        $this->historial->anotar($cid, $id, 'negocio', 'Negocio creado: ' . $nombre);
        $this->historial->sincronizarFase($cid, $fases);
        return $this->buscar($acc, $id);
    }

    /** nombre, valor, servicio, fecha_cierre_prevista, propietario_id, archivado. */
    public function actualizar(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $antes = $this->cruda($acc, $id);
        $c = [];
        if (array_key_exists('nombre', $d)) {
            $v = is_scalar($d['nombre']) ? trim((string)$d['nombre']) : '';
            if ($v === '') throw HttpError::validacion('El nombre del negocio no puede quedar vacío.', 'nombre');
            if (mb_strlen($v) > 200) throw HttpError::validacion('El nombre es demasiado largo.', 'nombre');
            $c['nombre'] = $v;
        }
        if (array_key_exists('valor', $d)) $c['valor'] = Dinero::leer($d['valor'], 'valor');
        if (array_key_exists('servicio', $d)) {
            $v = is_string($d['servicio']) ? trim($d['servicio']) : '';
            if (mb_strlen($v) > 80) throw HttpError::validacion('Servicio no válido.', 'servicio');
            $c['servicio'] = $v === '' ? null : $v;
        }
        if (array_key_exists('fecha_cierre_prevista', $d)) {
            $v = $d['fecha_cierre_prevista'];
            $c['fecha_cierre_prevista'] = $v === null || $v === '' ? null : Filtros::fecha(is_string($v) ? $v : '', 'fecha_cierre_prevista');
        }
        if (array_key_exists('propietario_id', $d)) $c['propietario_id'] = $this->contactos->propietario($d['propietario_id']);
        if (array_key_exists('archivado', $d)) {
            $arch = filter_var($d['archivado'], FILTER_VALIDATE_BOOLEAN);
            if ($arch !== ((int)$antes['archivado'] === 1)) {
                $c['archivado'] = $arch ? 1 : 0;
                $c['fecha_archivado'] = $arch ? date('Y-m-d') : null;
            }
        }
        if (!$c) throw HttpError::validacion('No hay nada que cambiar.');
        $this->pdo->prepare('UPDATE deals SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($c))) . ' WHERE id = ?')
            ->execute([...array_values($c), $id]);
        $cid = (int)$antes['contact_id'];
        if (array_key_exists('archivado', $c)) {
            $this->historial->anotar($cid, $id, 'negocio', ($c['archivado'] ? 'Negocio archivado: ' : 'Negocio desarchivado: ') . ($antes['nombre'] ?: ''));
            $this->historial->sincronizarFase($cid);
        }
        return $this->buscar($acc, $id);
    }

    /**
     * Mover de fase (y de posición dentro de la columna). A una fase de tipo
     * perdida solo se llega con motivo: sin él, 422 y el front abre el modal.
     */
    public function mover(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $antes = $this->cruda($acc, $id);
        $fases = Catalogos::fases($this->pdo);
        $fase = is_string($d['fase'] ?? null) ? $d['fase'] : '';
        if (!isset($fases[$fase])) throw HttpError::validacion('Esa fase no existe.', 'fase');
        $tipo = $fases[$fase]['tipo'];
        if ($tipo === 'perdida' && $antes['fase'] !== $fase) {
            if (!isset($d['motivo'])) throw HttpError::validacion('Elige un motivo.', 'motivo');
            return $this->perder($acc, $id, $d, $fase);
        }
        $cambia = $antes['fase'] !== $fase;
        $c = ['fase' => $fase, 'probabilidad' => $fases[$fase]['probabilidad']];
        if ($cambia) {
            $c['fecha_entrada_fase'] = date('Y-m-d');
            $c['fecha_cierre_real'] = in_array($tipo, ['ganada', 'perdida'], true) ? date('Y-m-d') : null;
            /* Si vuelve a abrirse, ya no hay que reactivarlo. */
            if ($tipo !== 'perdida') $c['fecha_reactivacion'] = null;
        }
        db_tx_begin($this->pdo);
        try {
            $this->pdo->prepare('UPDATE deals SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($c))) . ' WHERE id = ?')
                ->execute([...array_values($c), $id]);
            if (isset($d['indice']) && is_int($d['indice'])) $this->colocar($id, $fase, (int)$antes['archivado'], max(0, $d['indice']));
            if ($cambia) {
                $this->historial->anotar((int)$antes['contact_id'], $id, 'fase', 'Movido a ' . $fases[$fase]['nombre']);
                $this->historial->sincronizarFase((int)$antes['contact_id'], $fases);
            }
        } catch (\Throwable $e) {
            db_tx_rollback($this->pdo);
            throw $e;
        }
        db_tx_commit($this->pdo);
        return $this->buscar($acc, $id);
    }

    /* Posición dentro de la columna: se reescribe el orden de la columna entera. */
    private function colocar(int $id, string $fase, int $archivado, int $indice): void
    {
        $st = $this->pdo->prepare('SELECT id FROM deals WHERE fase = ? AND archivado = ? AND id <> ? ORDER BY orden, id DESC');
        $st->execute([$fase, $archivado, $id]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        array_splice($ids, min($indice, count($ids)), 0, [$id]);
        $up = $this->pdo->prepare('UPDATE deals SET orden = ? WHERE id = ?');
        foreach ($ids as $i => $d) $up->execute([$i + 1, $d]);
    }

    public function perder(Acceso $acc, int $id, array $d, ?string $fase = null): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $antes = $this->cruda($acc, $id);
        $motivo = is_string($d['motivo'] ?? null) ? $d['motivo'] : '';
        if (!isset(Catalogos::MOTIVOS_PERDIDA[$motivo])) throw HttpError::validacion('Elige un motivo.', 'motivo');
        $txt = is_scalar($d['comentario'] ?? null) ? trim((string)$d['comentario']) : '';
        if (mb_strlen($txt) > 255) throw HttpError::validacion('El comentario es demasiado largo.', 'comentario');
        $fases = Catalogos::fases($this->pdo);
        $fase ??= isset($fases['perdido']) ? 'perdido' : (Catalogos::slugsDeTipo($fases, 'perdida')[0] ?? null);
        if ($fase === null) throw new HttpError(409, 'No hay ninguna fase de perdidos en el embudo.', 'conflicto');
        [$etiqueta, $meses] = Catalogos::MOTIVOS_PERDIDA[$motivo];
        $react = $meses === null ? null : (new \DateTimeImmutable('today'))->modify("+$meses months")->format('Y-m-d');

        $this->pdo->prepare('UPDATE deals SET fecha_entrada_fase = IF(fase = ?, fecha_entrada_fase, CURDATE()), fase = ?, probabilidad = 0, fecha_cierre_real = CURDATE(),
                             motivo_perdida = ?, motivo_perdida_txt = ?, fecha_reactivacion = ? WHERE id = ?')
            ->execute([$fase, $fase, $motivo, $txt === '' ? null : $txt, $react, $id]);
        $this->historial->anotar((int)$antes['contact_id'], $id, 'perdida', 'Perdido — ' . $etiqueta . ($txt !== '' ? ': ' . $txt : ''));
        $this->historial->sincronizarFase((int)$antes['contact_id'], $fases);
        return $this->buscar($acc, $id);
    }

    /** A la papelera con sus etiquetas y sus seguimientos. Devuelve el id de papelera. */
    public function borrar(Acceso $acc, int $id): int
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.borrar');
        $d = $this->cruda($acc, $id);
        $tid = pap_borrar('deals', $id, 'negocio', (string)($d['nombre'] ?: "Negocio #$id"), [
            ['tabla' => 'deal_tags', 'fk' => 'deal_id'],
            ['tabla' => 'follow_up_tasks', 'fk' => 'deal_id'],
        ]);
        if (!$tid) throw new HttpError(500, 'No se ha podido mover el negocio a la papelera.', 'papelera');
        $this->historial->sincronizarFase((int)$d['contact_id']);
        return $tid;
    }

    public function etiqueta(Acceso $acc, int $id, int $tagId, bool $on): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $this->cruda($acc, $id);
        $st = $this->pdo->prepare('SELECT 1 FROM crm_tags WHERE id = ?');
        $st->execute([$tagId]);
        if (!$st->fetchColumn()) throw HttpError::noEncontrado('Etiqueta no encontrada.');
        $sql = $on ? 'INSERT IGNORE INTO deal_tags (deal_id, tag_id) VALUES (?,?)' : 'DELETE FROM deal_tags WHERE deal_id = ? AND tag_id = ?';
        $this->pdo->prepare($sql)->execute([$id, $tagId]);
        return $this->buscar($acc, $id);
    }

    /** La fila cruda si se ve (por su contacto); 404 si no. */
    public function cruda(Acceso $acc, int $id): array
    {
        $st = $this->pdo->prepare('SELECT d.* FROM deals d JOIN contacts c ON c.id = d.contact_id WHERE d.id = ?' . Alcance::sql($acc));
        $st->execute([$id]);
        return $st->fetch() ?: throw HttpError::noEncontrado('Negocio no encontrado.');
    }

    private function buscar(Acceso $acc, int $id): array
    {
        $st = $this->pdo->prepare('SELECT ' . self::COLUMNAS . ' FROM deals d JOIN contacts c ON c.id = d.contact_id WHERE d.id = ?' . Alcance::sql($acc));
        $st->execute([$id]);
        $r = $st->fetch() ?: throw HttpError::noEncontrado('Negocio no encontrado.');
        return $this->conEtiquetas([$this->fila($r, Catalogos::fases($this->pdo))])[0];
    }

    private function fila(array $r, array $fases): array
    {
        $entrada = $r['fecha_entrada_fase'] ?: null;
        $dias = $entrada ? max(0, (int)(new \DateTimeImmutable('today'))->diff(new \DateTimeImmutable($entrada))->days) : 0;
        $tipo = $fases[$r['fase']]['tipo'] ?? 'abierta';
        return [
            'id' => (int)$r['id'], 'contact_id' => (int)$r['contact_id'],
            'contacto' => ['nombre' => (string)$r['c_nombre'], 'empresa' => (string)($r['c_empresa'] ?? ''), 'sector' => (string)($r['c_sector'] ?? '')],
            'nombre' => (string)($r['nombre'] ?: $r['c_nombre']), 'valor' => Dinero::num($r['valor']), 'servicio' => (string)($r['servicio'] ?? ''),
            'fase' => (string)$r['fase'], 'tipo_fase' => $tipo, 'probabilidad' => (int)$r['probabilidad'],
            'fecha_cierre_prevista' => $r['fecha_cierre_prevista'] ?: null, 'fecha_entrada_fase' => $entrada,
            /* El aviso «N días sin avanzar» solo tiene sentido en fases abiertas. */
            'dias_en_fase' => $tipo === 'abierta' ? $dias : 0,
            'fecha_cierre_real' => $r['fecha_cierre_real'] ?: null,
            'motivo_perdida' => $r['motivo_perdida'] ?: null, 'motivo_perdida_txt' => (string)($r['motivo_perdida_txt'] ?? ''),
            'fecha_reactivacion' => $r['fecha_reactivacion'] ?: null,
            'propietario_id' => $r['propietario_id'] !== null ? (int)$r['propietario_id'] : null,
            'archivado' => (int)$r['archivado'] === 1,
            'client_id' => ($r['client_id'] ?? null) !== null ? (int)$r['client_id'] : (($r['c_client'] ?? null) !== null ? (int)$r['c_client'] : null),
            'invoice_id' => ($r['factura_id'] ?? null) !== null ? (int)$r['factura_id'] : null,
            'etiquetas' => [],
        ];
    }

    private function conEtiquetas(array $filas): array
    {
        if (!$filas) return $filas;
        $ids = implode(',', array_map(fn($f) => (int)$f['id'], $filas));
        $por = [];
        foreach ($this->pdo->query("SELECT dt.deal_id, t.id, t.nombre, t.color FROM deal_tags dt JOIN crm_tags t ON t.id = dt.tag_id WHERE dt.deal_id IN ($ids) ORDER BY t.nombre") as $t) {
            $por[(int)$t['deal_id']][] = ['id' => (int)$t['id'], 'nombre' => (string)$t['nombre'], 'color' => (string)($t['color'] ?: '#5b8def')];
        }
        foreach ($filas as &$f) $f['etiquetas'] = $por[$f['id']] ?? [];
        return $filas;
    }
}
