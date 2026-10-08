<?php
namespace Croilab\Modulos\Crm;

use Croilab\Seguridad\Acceso;
use PDO;

/* SQL de los contactos: listado con filtros y orden, una fila, alta, cambios
   y las etiquetas de cada uno. Sin permisos: eso es cosa del servicio (aquí
   solo se aplica el alcance que recibe). */
final class ContactosRepositorio
{
    /* Orden por cabecera: clave del front => expresión SQL. */
    private const ORDEN = [
        'nombre' => 'c.nombre', 'empresa' => 'c.empresa', 'sector' => 'c.sector', 'valor' => 'c.valor',
        'fase' => 'ps.orden', 'ult' => 'c.fecha_ultimo_contacto', 'prox' => 'c.fecha_prox', 'creado' => 'c.fecha_creacion',
    ];

    public const COLUMNAS = 'c.id, c.nombre, c.empresa, c.sector, c.email, c.telefono, c.whatsapp, c.linkedin, c.web, c.origen_lead,
        c.servicio_json, c.valor, c.fase, c.proxima_accion, c.fecha_prox, c.propietario_id, c.ultima_actualizacion,
        c.fecha_creacion, c.fecha_ultimo_contacto, c.client_id';

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array{0: array, 1: int} filas (con etiquetas) y total que cumple
     */
    public function listar(Acceso $acc, array $f, string $sort, string $dir, int $limit, int $offset): array
    {
        [$sql, $p] = Filtros::sql($f);
        $where = 'WHERE 1=1' . Alcance::sql($acc) . $sql;
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM contacts c $where");
        $st->execute($p);
        $total = (int)$st->fetchColumn();

        $col = self::ORDEN[$sort] ?? null;
        $d = $dir === 'asc' ? 'ASC' : 'DESC';
        /* Los vacíos siempre al final, se ordene como se ordene. */
        $vacio = in_array($sort, ['nombre', 'empresa', 'sector'], true) ? "($col IS NULL OR $col = '')" : "$col IS NULL";
        $orden = $col ? "$vacio ASC, $col $d, c.id DESC" : 'c.fecha_creacion DESC, c.id DESC';
        $st = $this->pdo->prepare('SELECT ' . self::COLUMNAS . " FROM contacts c LEFT JOIN pipeline_stages ps ON ps.slug = c.fase
                                    $where ORDER BY $orden LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset);
        $st->execute($p);
        $filas = array_map([$this, 'fila'], $st->fetchAll());
        return [$this->conEtiquetas($filas), $total];
    }

    /** Ids que cumplen (lote sobre «todos los filtrados», export). */
    public function ids(Acceso $acc, array $f, int $max = 5000): array
    {
        [$sql, $p] = Filtros::sql($f);
        $st = $this->pdo->prepare('SELECT c.id FROM contacts c WHERE 1=1' . Alcance::sql($acc) . $sql . ' ORDER BY c.fecha_creacion DESC, c.id DESC LIMIT ' . (int)$max);
        $st->execute($p);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public function totalVisible(Acceso $acc): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM contacts c WHERE 1=1' . Alcance::sql($acc))->fetchColumn();
    }

    /** La fila cruda (sin formatear) si existe y se ve; null si no. */
    public function cruda(Acceso $acc, int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM contacts c WHERE c.id = ?' . Alcance::sql($acc));
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Contacto formateado con sus etiquetas (null = sin mirar el alcance, para uso interno). */
    public function buscar(?Acceso $acc, int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::COLUMNAS . ' FROM contacts c WHERE c.id = ?' . ($acc ? Alcance::sql($acc) : ''));
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ? $this->conEtiquetas([$this->fila($r)])[0] : null;
    }

    /** Filas por id (respetando el alcance y el orden pedido). */
    public function porIds(Acceso $acc, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $st = $this->pdo->query('SELECT ' . self::COLUMNAS . ' FROM contacts c WHERE c.id IN (' . implode(',', $ids) . ')' . Alcance::sql($acc));
        $por = [];
        foreach ($st->fetchAll() as $r) $por[(int)$r['id']] = $this->fila($r);
        $out = [];
        foreach ($ids as $id) if (isset($por[$id])) $out[] = $por[$id];
        return $this->conEtiquetas($out);
    }

    public function insertar(array $campos): int
    {
        $cols = array_keys($campos);
        $this->pdo->prepare('INSERT INTO contacts (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($campos));
        return (int)$this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $campos): void
    {
        if (!$campos) return;
        $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($campos)));
        $this->pdo->prepare("UPDATE contacts SET $set WHERE id = ?")->execute([...array_values($campos), $id]);
    }

    /** Negocios no archivados que coinciden con la búsqueda (máx. 8). */
    public function negociosCoincidentes(Acceso $acc, string $q): array
    {
        $like = '%' . Filtros::escaparLike($q) . '%';
        $st = $this->pdo->prepare('SELECT d.id, d.nombre, d.valor, d.fase, c.id AS contact_id, c.nombre AS c_nombre, c.empresa AS c_empresa
                                     FROM deals d JOIN contacts c ON c.id = d.contact_id
                                    WHERE d.archivado = 0 AND (d.nombre LIKE ? OR d.servicio LIKE ? OR c.nombre LIKE ? OR c.empresa LIKE ?)'
                                    . Alcance::sql($acc) . ' ORDER BY d.id DESC LIMIT 8');
        $st->execute([$like, $like, $like, $like]);
        return array_map(fn($r) => [
            'id' => (int)$r['id'], 'nombre' => (string)($r['nombre'] ?: $r['c_nombre']), 'valor' => Dinero::num($r['valor']),
            'fase' => (string)$r['fase'], 'contact_id' => (int)$r['contact_id'],
            'contacto' => (string)($r['c_empresa'] ?: $r['c_nombre']),
        ], $st->fetchAll());
    }

    /* ---------- Etiquetas ---------- */

    public function etiquetaExiste(int $tagId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM crm_tags WHERE id = ?');
        $st->execute([$tagId]);
        return (bool)$st->fetchColumn();
    }

    public function ponerEtiqueta(int $contactId, int $tagId, bool $on): void
    {
        $sql = $on ? 'INSERT IGNORE INTO contact_tags (contact_id, tag_id) VALUES (?,?)' : 'DELETE FROM contact_tags WHERE contact_id = ? AND tag_id = ?';
        $this->pdo->prepare($sql)->execute([$contactId, $tagId]);
    }

    /* ---------- Formato ---------- */

    /** Fila de la base → ContactoFila (API.md / 03-crm.md §7). */
    public function fila(array $r): array
    {
        $svc = json_decode((string)($r['servicio_json'] ?? ''), true);
        $prox = $r['fecha_prox'] && $r['fecha_prox'] !== '0000-00-00' ? (string)$r['fecha_prox'] : null;
        return [
            'id' => (int)$r['id'],
            'nombre' => (string)$r['nombre'],
            'empresa' => (string)($r['empresa'] ?? ''),
            'sector' => (string)($r['sector'] ?? ''),
            'email' => (string)($r['email'] ?? ''),
            'telefono' => (string)($r['telefono'] ?? ''),
            'whatsapp' => (string)($r['whatsapp'] ?? ''),
            'linkedin' => (string)($r['linkedin'] ?? ''),
            'web' => (string)($r['web'] ?? ''),
            'origen_lead' => (string)($r['origen_lead'] ?? ''),
            'servicios' => is_array($svc) ? array_values(array_filter($svc, 'is_string')) : [],
            'valor' => Dinero::num($r['valor'] ?? null),
            'fase' => (string)$r['fase'],
            'proxima_accion' => (string)($r['proxima_accion'] ?? ''),
            'fecha_prox' => $prox,
            'accion_vencida' => $prox !== null && $prox <= date('Y-m-d'),
            'propietario_id' => $r['propietario_id'] !== null ? (int)$r['propietario_id'] : null,
            'ultima_actualizacion' => (string)($r['ultima_actualizacion'] ?? ''),
            'fecha_creacion' => (string)$r['fecha_creacion'],
            'fecha_ultimo_contacto' => $r['fecha_ultimo_contacto'] && $r['fecha_ultimo_contacto'] !== '0000-00-00' ? (string)$r['fecha_ultimo_contacto'] : null,
            'client_id' => $r['client_id'] !== null ? (int)$r['client_id'] : null,
            'etiquetas' => [],
        ];
    }

    private function conEtiquetas(array $filas): array
    {
        if (!$filas) return $filas;
        $ids = implode(',', array_map(fn($f) => (int)$f['id'], $filas));
        $por = [];
        foreach ($this->pdo->query("SELECT ct.contact_id, t.id, t.nombre, t.color FROM contact_tags ct JOIN crm_tags t ON t.id = ct.tag_id
                                     WHERE ct.contact_id IN ($ids) ORDER BY t.nombre") as $t) {
            $por[(int)$t['contact_id']][] = ['id' => (int)$t['id'], 'nombre' => (string)$t['nombre'], 'color' => (string)($t['color'] ?: '#5b8def')];
        }
        foreach ($filas as &$f) $f['etiquetas'] = $por[$f['id']] ?? [];
        return $filas;
    }
}
