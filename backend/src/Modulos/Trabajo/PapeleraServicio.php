<?php
namespace Croilab\Modulos\Trabajo;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* La papelera global (papelera.php) sobre admin/lib/papelera.php.

   Ver la lista: ver.ajustes, y solo los tipos de los módulos que se ven.
   Restaurar: general.editar; lo que borró otra persona exige además
   papelera.restaurar, y siempre el permiso del módulo del elemento («Deshacer»
   de lo propio no necesita entrar en Ajustes). Purgar: papelera.purgar. */
class PapeleraServicio
{
    public function __construct(private readonly PDO $pdo) {}

    public function listar(Acceso $acc, int $limit, int $offset): array
    {
        $acc->exigir('ver.ajustes');
        $tipos = array_keys(array_filter(pap_tipos(), fn($t) => $acc->puede($t[3])));
        $where = $tipos ? 'WHERE tipo IN (' . implode(',', array_fill(0, count($tipos), '?')) . ')' : 'WHERE 1=0';
        /* Los tipos que no están en el catálogo (borrados de otros módulos con
           un tipo nuevo) solo los ve quien puede purgar. */
        if ($acc->puede('papelera.purgar')) $where .= ' OR tipo NOT IN (' . implode(',', array_fill(0, count(pap_tipos()), '?')) . ')';
        $p = $acc->puede('papelera.purgar') ? [...$tipos, ...array_keys(pap_tipos())] : $tipos;

        $st = $this->pdo->prepare("SELECT COUNT(*) FROM trash $where");
        $st->execute($p);
        $total = (int)$st->fetchColumn();
        $st = $this->pdo->prepare("SELECT id, tabla, ref_id, tipo, titulo, admin_id, autor, created_at FROM trash $where ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET $offset");
        $st->execute($p);
        $items = array_map(fn($t) => [
            'id' => (int)$t['id'],
            'tipo' => (string)$t['tipo'],
            'tipo_label' => pap_tipo_label($t['tipo']),
            'titulo' => trim((string)$t['titulo']) !== '' ? (string)$t['titulo'] : pap_tipo_label($t['tipo']) . ' #' . (int)$t['ref_id'],
            'ref_id' => (int)$t['ref_id'],
            'autor' => (string)$t['autor'],
            'mio' => (int)$t['admin_id'] === $acc->adminId,
            'created_at' => (string)$t['created_at'],
            'caduca' => date('Y-m-d', strtotime((string)$t['created_at']) + PAP_DIAS * 86400),
        ], $st->fetchAll());
        return [
            'items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'dias' => PAP_DIAS,
            'permisos' => ['restaurar' => $acc->puede('general.editar'), 'purgar' => $acc->puede('general.editar') && $acc->puede('papelera.purgar')],
        ];
    }

    /** Devuelve {id, tipo, msg, url}: id del elemento restaurado y a dónde ir. */
    public function restaurar(Acceso $acc, int $tid): array
    {
        $acc->exigir('general.editar');
        $t = $this->elemento($tid);
        if ((int)$t['admin_id'] !== $acc->adminId) $acc->exigir('papelera.restaurar');
        $acc->exigir(pap_tipo_permiso($t['tipo']));
        $r = pap_restaurar($tid);
        if (empty($r['ok'])) throw new HttpError(409, $r['msg'] ?? 'No se ha podido restaurar.', 'conflicto');
        $id = (int)($r['id'] ?? 0);
        return ['id' => $id, 'tipo' => (string)$r['tipo'], 'msg' => (string)$r['msg'], 'url' => $this->destino((string)$r['tipo'], $id)];
    }

    public function purgar(Acceso $acc, int $tid): void
    {
        $acc->exigir('general.editar', 'papelera.purgar');
        $this->elemento($tid);
        if (!pap_vaciar($tid)) throw new HttpError(500, 'No se ha podido eliminar.', 'papelera');
    }

    public function vaciar(Acceso $acc): int
    {
        $acc->exigir('general.editar', 'papelera.purgar');
        $n = (int)$this->pdo->query('SELECT COUNT(*) FROM trash')->fetchColumn();
        if (!pap_vaciar()) throw new HttpError(500, 'No se ha podido vaciar la papelera.', 'papelera');
        return $n;
    }

    private function elemento(int $tid): array
    {
        $st = $this->pdo->prepare('SELECT id, tipo, admin_id, tabla, ref_id FROM trash WHERE id = ?');
        $st->execute([$tid]);
        return $st->fetch() ?: throw HttpError::noEncontrado('Eso ya no está en la papelera.');
    }

    /* Ruta del front del elemento restaurado. Una lista vuelve a su tablero. */
    private function destino(string $tipo, int $id): string
    {
        if ($tipo === 'lista') {
            $st = $this->pdo->prepare('SELECT client_id FROM task_lists WHERE id = ?');
            $st->execute([$id]);
            $cli = (int)$st->fetchColumn();
            return $cli ? "/tareas?view=cliente&cli=$cli&list=$id" : '';
        }
        if ($tipo === 'credencial') {
            $st = $this->pdo->prepare('SELECT client_id FROM client_credentials WHERE id = ?');
            $st->execute([$id]);
            $cli = (int)$st->fetchColumn();
            return $cli ? "/ajustes/boveda/$cli" : '/ajustes/boveda';
        }
        if ($tipo === 'documento') return '/finanzas/contabilidad';
        return pap_tipo_url($tipo, $id);
    }
}
