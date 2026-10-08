<?php
namespace Croilab\Modulos\Trabajo;

use Croilab\Http\HttpError;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Modulos\Tareas\TextoRico;
use Croilab\Seguridad\Acceso;

/* Actas internas del equipo (actas.php). Leer: ver.actas. Escribir, fijar y
   borrar: además general.editar. Nunca salen al portal.

   El antiguo dejaba que dos personas se pisaran el acta sin enterarse: aquí
   cada guardado lleva la versión que se estaba editando (`version` =
   updated_at) y si otra persona la ha cambiado mientras tanto, 409. */
class ActasServicio
{
    public const MAX_TITULO = 220;
    public const MAX_CONTENIDO = 400000;

    public function __construct(
        private readonly ActasRepositorio $repo,
        private readonly EquipoRepositorio $equipo
    ) {}

    public function listar(Acceso $acc, string $q, int $autor, int $limit, int $offset): array
    {
        $acc->exigir('ver.actas');
        [$filas, $total] = $this->repo->listar(mb_substr(trim($q), 0, 100), $autor, $limit, $offset);
        $autores = array_map(fn($a) => [
            'persona' => $a['admin_id'] !== null && $this->equipo->existe((int)$a['admin_id']) ? $this->equipo->persona((int)$a['admin_id']) : null,
            'n' => (int)$a['n'],
        ], $this->repo->autores());
        return [
            'items' => array_map(fn($a) => $this->forma($a, false), $filas),
            'total' => $total, 'limit' => $limit, 'offset' => $offset,
            'autores' => $autores,
            'puede_editar' => $acc->puede('general.editar'),
        ];
    }

    public function ver(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.actas');
        $a = $this->repo->buscar($id) ?? throw HttpError::noEncontrado('Acta no encontrada.');
        return $this->forma($a, true) + ['puede_editar' => $acc->puede('general.editar')];
    }

    public function crear(Acceso $acc, array $d): array
    {
        $acc->exigir('ver.actas', 'general.editar');
        [$titulo, $contenido] = $this->validar($d);
        $id = $this->repo->crear($titulo, $contenido, $acc->adminId);
        return $this->ver($acc, $id);
    }

    public function guardar(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('ver.actas', 'general.editar');
        $a = $this->repo->buscar($id) ?? throw HttpError::noEncontrado('Acta no encontrada.');
        if (isset($d['version']) && is_string($d['version']) && $d['version'] !== '' && $d['version'] !== (string)$a['updated_at']) {
            throw new HttpError(409, 'Alguien ha cambiado esta acta mientras la editabas. Copia tu texto y recarga para ver su versión.', 'conflicto');
        }
        [$titulo, $contenido] = $this->validar($d + ['titulo' => $a['titulo'], 'contenido' => $a['contenido']]);
        $this->repo->guardar($id, $titulo, $contenido);
        return $this->ver($acc, $id);
    }

    public function fijar(Acceso $acc, int $id, ?bool $fijada): array
    {
        $acc->exigir('ver.actas', 'general.editar');
        $a = $this->repo->buscar($id) ?? throw HttpError::noEncontrado('Acta no encontrada.');
        $this->repo->fijar($id, $fijada ?? (int)$a['pinned'] !== 1);
        return $this->ver($acc, $id);
    }

    /** A la papelera; devuelve su id para «Deshacer». */
    public function borrar(Acceso $acc, int $id): int
    {
        $acc->exigir('ver.actas', 'general.editar');
        $a = $this->repo->buscar($id) ?? throw HttpError::noEncontrado('Acta no encontrada.');
        $tid = pap_borrar('actas', $id, 'acta', trim((string)$a['titulo']) !== '' ? (string)$a['titulo'] : 'Acta sin título');
        if (!$tid) throw new HttpError(500, 'No se ha podido mover el acta a la papelera.', 'papelera');
        return $tid;
    }

    private function validar(array $d): array
    {
        foreach (['titulo', 'contenido'] as $k) {
            if (isset($d[$k]) && !is_string($d[$k])) throw HttpError::validacion('Valor no válido.', $k);
        }
        $titulo = trim(preg_replace('/\s+/u', ' ', (string)($d['titulo'] ?? '')));
        $contenido = rtrim(str_replace("\r\n", "\n", (string)($d['contenido'] ?? '')));
        if (mb_strlen($titulo) > self::MAX_TITULO) throw HttpError::validacion('El título es demasiado largo (máximo 220).', 'titulo');
        if (mb_strlen($contenido) > self::MAX_CONTENIDO) throw HttpError::validacion('El acta es demasiado larga.', 'contenido');
        if ($titulo === '' && TextoRico::vacio($contenido)) throw HttpError::validacion('Escribe un título o algo de contenido.', 'titulo');
        return [$titulo, $contenido];
    }

    private function forma(array $a, bool $completa): array
    {
        $autor = $a['admin_id'] !== null && $this->equipo->existe((int)$a['admin_id']) ? $this->equipo->persona((int)$a['admin_id']) : null;
        $out = [
            'id' => (int)$a['id'],
            'titulo' => (string)$a['titulo'],
            'extracto' => TextoRico::extracto((string)$a['contenido'], 200),
            'autor' => $autor,
            'fijada' => (int)$a['pinned'] === 1,
            'created_at' => (string)$a['created_at'],
            'updated_at' => (string)$a['updated_at'],
        ];
        if ($completa) $out['contenido'] = (string)$a['contenido'];
        return $out;
    }
}
