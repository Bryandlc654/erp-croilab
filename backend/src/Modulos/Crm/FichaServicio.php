<?php
namespace Croilab\Modulos\Crm;

use Croilab\Http\HttpError;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;
use PDO;

/* La ficha del contacto (crm_profile.php): lectura completa y lo que se hace
   desde ella — comentarios, interacciones, facturación, propuestas y
   archivos adjuntos. Las reuniones solo se leen (las agenda Comunicación). */
final class FichaServicio
{
    private const FACTURACION = ['razon_social' => 200, 'cif' => 40, 'direccion' => 200, 'cp' => 15, 'ciudad' => 100,
        'provincia' => 100, 'pais' => 80, 'email_facturacion' => 160, 'iban' => 40];
    public const MAX_ADJUNTO = 25 * 1024 * 1024;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ContactosServicio $contactos,
        private readonly ContactosRepositorio $repo,
        private readonly EquipoRepositorio $equipo,
        private readonly Historial $historial,
        private readonly string $carpetaSubidas
    ) {}

    public function ficha(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.crm');
        $this->contactos->visible($acc, $id);
        $contacto = $this->repo->buscar($acc, $id);
        $nombres = $this->equipo->nombres();
        $esDueno = $acc->puede('admin.total');

        $q = fn(string $sql) => $this->filas($sql, [$id]);
        $fact = $q('SELECT * FROM billing_data WHERE contact_id = ?')[0] ?? [];
        $cliente = null;
        if ($contacto['client_id']) {
            $st = $this->pdo->prepare('SELECT id, name FROM clients WHERE id = ?');
            $st->execute([$contacto['client_id']]);
            $c = $st->fetch();
            $cliente = $c ? ['id' => (int)$c['id'], 'name' => (string)$c['name']] : null;
        }
        return [
            'contacto' => $contacto,
            'facturacion' => array_combine(array_keys(self::FACTURACION), array_map(fn($k) => (string)($fact[$k] ?? ''), array_keys(self::FACTURACION))),
            'comentarios' => array_map(fn($r) => [
                'id' => (int)$r['id'], 'autor_id' => $r['autor_id'] !== null ? (int)$r['autor_id'] : null,
                'autor' => $r['autor_id'] !== null ? ($nombres[(int)$r['autor_id']] ?? '') : '',
                'tipo' => isset(Catalogos::TIPOS_COMENTARIO[$r['tipo']]) ? (string)$r['tipo'] : 'nota',
                'contenido' => (string)$r['contenido'], 'fecha' => (string)$r['fecha'],
                'puede_borrar' => $esDueno || ((int)$r['autor_id'] === $acc->adminId && $acc->puede('general.editar')),
            ], $q('SELECT id, autor_id, tipo, contenido, fecha FROM comments WHERE contact_id = ? ORDER BY fecha DESC, id DESC')),
            'propuestas' => array_map([$this, 'propuesta'], $q('SELECT * FROM proposals WHERE contact_id = ? ORDER BY fecha_envio DESC, id DESC')),
            'adjuntos' => array_map([$this, 'adjunto'], $q('SELECT * FROM attachments WHERE contact_id = ? ORDER BY fecha_subida DESC, id DESC')),
            'listas' => array_map(fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre'], 'tipo' => (string)$r['tipo']],
                $q('SELECT l.id, l.nombre, l.tipo FROM list_members m JOIN lists l ON l.id = m.list_id WHERE m.contact_id = ? ORDER BY l.nombre')),
            'reuniones' => array_map([$this, 'reunion'], $q('SELECT * FROM crm_meetings WHERE contact_id = ? ORDER BY fecha DESC, hora DESC, id DESC')),
            'actividad' => array_map(fn($r) => ['id' => (int)$r['id'], 'tipo' => (string)$r['tipo'], 'descripcion' => (string)($r['descripcion'] ?? ''), 'fecha' => (string)$r['fecha']],
                $q('SELECT id, tipo, descripcion, fecha FROM activities WHERE contact_id = ? ORDER BY fecha DESC, id DESC LIMIT 60')),
            'negocios' => array_map(fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)($r['nombre'] ?: $contacto['nombre']), 'valor' => Dinero::num($r['valor']),
                'fase' => (string)$r['fase'], 'archivado' => (int)$r['archivado'] === 1],
                $q('SELECT id, nombre, valor, fase, archivado FROM deals WHERE contact_id = ? ORDER BY archivado, id DESC')),
            'cliente' => $cliente,
        ];
    }

    /* ---------- Comentarios ---------- */

    public function comentar(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.crear');
        $c = $this->contactos->visible($acc, $id);
        $tipo = is_string($d['tipo'] ?? null) && isset(Catalogos::TIPOS_COMENTARIO[$d['tipo']]) ? $d['tipo'] : 'nota';
        $texto = is_string($d['contenido'] ?? null) ? trim($d['contenido']) : '';
        if ($texto === '') throw HttpError::validacion('Escribe algo antes de publicar.', 'contenido');
        if (mb_strlen($texto) > 20000) throw HttpError::validacion('El comentario es demasiado largo.', 'contenido');
        preg_match_all('/@([\p{L}0-9_.\-]+)/u', $texto, $m);
        $menciones = array_values(array_unique($m[1]));

        $this->pdo->prepare('INSERT INTO comments (contact_id, autor_id, tipo, contenido, menciones, fecha) VALUES (?,?,?,?,?,NOW())')
            ->execute([$id, $acc->adminId ?: null, $tipo, $texto, $menciones ? mb_substr(json_encode($menciones, JSON_UNESCAPED_UNICODE), 0, 255) : null]);
        $cid = (int)$this->pdo->lastInsertId();
        [$etiqueta, $interaccion] = Catalogos::TIPOS_COMENTARIO[$tipo];
        $this->historial->anotar($id, null, $tipo, $etiqueta . ': ' . mb_substr((string)preg_replace('/\s+/u', ' ', $texto), 0, 80));
        if ($interaccion) $this->historial->contactado($id);
        $this->historial->sincronizarUltima($id);
        $this->avisarMenciones($acc, $id, $cid, $texto, (string)($c['empresa'] ?: $c['nombre']));
        return $this->ficha($acc, $id);
    }

    /* Las @menciones avisan a quien se nombra (el antiguo solo las guardaba). */
    private function avisarMenciones(Acceso $acc, int $id, int $comentario, string $texto, string $nombre): void
    {
        if (!function_exists('notif_menciones') || !function_exists('notif_add')) return;
        $resumen = mb_substr(trim((string)preg_replace('/\s+/u', ' ', $texto)), 0, 160);
        $actor = $this->equipo->persona($acc->adminId)['username'];
        foreach (notif_menciones($texto) as $para) {
            if ($para === $acc->adminId) continue;
            notif_add($para, 'lead', 'te ha mencionado en ' . $nombre . ': ' . $resumen, '', '/crm/contactos/' . $id, 'crmcmt:' . $comentario . ':' . $para, '', $actor);
        }
    }

    public function borrarComentario(Acceso $acc, int $comentarioId): array
    {
        $acc->exigir('ver.crm', 'general.editar');
        $st = $this->pdo->prepare('SELECT contact_id, autor_id FROM comments WHERE id = ?');
        $st->execute([$comentarioId]);
        $r = $st->fetch() ?: throw HttpError::noEncontrado('Comentario no encontrado.');
        $cid = (int)$r['contact_id'];
        $this->contactos->visible($acc, $cid);
        /* El dueño borra cualquiera; el resto solo los suyos. */
        if (!$acc->puede('admin.total') && (int)$r['autor_id'] !== $acc->adminId) throw HttpError::permiso();
        $this->pdo->prepare('DELETE FROM comments WHERE id = ?')->execute([$comentarioId]);
        $this->historial->sincronizarUltima($cid);
        return $this->ficha($acc, $cid);
    }

    /** Botones Email / Llamar / WhatsApp de la ficha: deja constancia y cuenta como contacto. */
    public function interaccion(Acceso $acc, int $id, array $d): void
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $this->contactos->visible($acc, $id);
        $tipo = (string)($d['tipo'] ?? '');
        if (!isset(Catalogos::TIPOS_COMENTARIO[$tipo])) throw HttpError::validacion('Tipo de actividad desconocido.', 'tipo');
        $desc = is_string($d['descripcion'] ?? null) ? trim($d['descripcion']) : '';
        if ($desc === '' || mb_strlen($desc) > 255) throw HttpError::validacion('Falta la descripción.', 'descripcion');
        $this->historial->anotar($id, null, $tipo, $desc);
        if (Catalogos::TIPOS_COMENTARIO[$tipo][1]) $this->historial->contactado($id);
    }

    /* ---------- Facturación ---------- */

    public function facturacion(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $this->contactos->visible($acc, $id);
        $c = [];
        foreach (self::FACTURACION as $k => $max) {
            if (!array_key_exists($k, $d)) continue;
            if ($d[$k] !== null && !is_scalar($d[$k])) throw HttpError::validacion('Valor no válido.', $k);
            $v = trim((string)$d[$k]);
            if (mb_strlen($v) > $max) throw HttpError::validacion('Texto demasiado largo.', $k);
            if ($k === 'email_facturacion' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) throw HttpError::validacion('Ese email no es válido.', $k);
            if ($k === 'iban' && $v !== '') {
                $v = strtoupper((string)preg_replace('/\s+/', '', $v));
                if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $v)) throw HttpError::validacion('Ese IBAN no es válido.', 'iban');
            }
            $c[$k] = $v === '' ? null : $v;
        }
        if (!$c) throw HttpError::validacion('No hay nada que cambiar.');
        $cols = array_keys($c);
        $this->pdo->prepare('INSERT INTO billing_data (contact_id, `' . implode('`,`', $cols) . '`) VALUES (?' . str_repeat(',?', count($cols)) . ')
                             ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($k) => "`$k` = VALUES(`$k`)", $cols)))
            ->execute([$id, ...array_values($c)]);
        return $this->ficha($acc, $id)['facturacion'];
    }

    /* ---------- Propuestas ---------- */

    public function crearPropuesta(Acceso $acc, int $id, array $d): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.crear');
        $this->contactos->visible($acc, $id);
        $p = $this->validarPropuesta($d) + ['nombre' => 'Propuesta', 'estado' => 'enviada', 'fecha_envio' => date('Y-m-d')];
        $cols = array_keys($p);
        $this->pdo->prepare('INSERT INTO proposals (contact_id, `' . implode('`,`', $cols) . '`) VALUES (?' . str_repeat(',?', count($cols)) . ')')
            ->execute([$id, ...array_values($p)]);
        $this->historial->anotar($id, null, 'propuesta', 'Propuesta enviada: ' . mb_substr((string)$p['nombre'], 0, 200));
        return $this->ficha($acc, $id)['propuestas'];
    }

    public function actualizarPropuesta(Acceso $acc, int $propuestaId, array $d): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.editar');
        $cid = $this->contactoDe('proposals', $propuestaId, 'Propuesta no encontrada.');
        $this->contactos->visible($acc, $cid);
        $p = $this->validarPropuesta($d);
        if (!$p) throw HttpError::validacion('No hay nada que cambiar.');
        $this->pdo->prepare('UPDATE proposals SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($p))) . ' WHERE id = ?')
            ->execute([...array_values($p), $propuestaId]);
        if (isset($p['estado'])) $this->historial->anotar($cid, null, 'propuesta', 'Propuesta ' . mb_strtolower(Catalogos::ESTADOS_PROPUESTA[$p['estado']]));
        return $this->ficha($acc, $cid)['propuestas'];
    }

    public function borrarPropuesta(Acceso $acc, int $propuestaId): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.borrar');
        $cid = $this->contactoDe('proposals', $propuestaId, 'Propuesta no encontrada.');
        $this->contactos->visible($acc, $cid);
        $this->pdo->prepare('DELETE FROM proposals WHERE id = ?')->execute([$propuestaId]);
        return $this->ficha($acc, $cid)['propuestas'];
    }

    private function validarPropuesta(array $d): array
    {
        $p = [];
        if (array_key_exists('nombre', $d)) {
            $v = is_scalar($d['nombre']) ? trim((string)$d['nombre']) : '';
            if (mb_strlen($v) > 200) throw HttpError::validacion('El nombre es demasiado largo.', 'nombre');
            $p['nombre'] = $v === '' ? 'Propuesta' : $v;
        }
        if (array_key_exists('importe', $d)) $p['importe'] = Dinero::leer($d['importe'], 'importe');
        if (array_key_exists('estado', $d)) {
            if (!is_string($d['estado']) || !isset(Catalogos::ESTADOS_PROPUESTA[$d['estado']])) throw HttpError::validacion('Estado desconocido.', 'estado');
            $p['estado'] = $d['estado'];
        }
        if (array_key_exists('fecha_envio', $d)) {
            $v = $d['fecha_envio'];
            $p['fecha_envio'] = $v === null || $v === '' ? null : Filtros::fecha(is_string($v) ? $v : '', 'fecha_envio');
        }
        if (array_key_exists('url_archivo', $d)) {
            $v = is_string($d['url_archivo']) ? trim($d['url_archivo']) : '';
            if ($v !== '' && (!preg_match('#^https?://#i', $v) || !filter_var($v, FILTER_VALIDATE_URL) || mb_strlen($v) > 400)) {
                throw HttpError::validacion('El enlace tiene que empezar por http:// o https://.', 'url_archivo');
            }
            $p['url_archivo'] = $v === '' ? null : $v;
        }
        return $p;
    }

    /* ---------- Adjuntos ---------- */

    /** Guarda un fichero de $_FILES. Lista blanca de extensiones, nombre aleatorio, imágenes reducidas. */
    public function subirAdjunto(Acceso $acc, int $id, mixed $f): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.crear');
        $this->contactos->visible($acc, $id);
        if (!is_array($f) || !isset($f['tmp_name'], $f['error'], $f['name']) || is_array($f['tmp_name'])) throw HttpError::validacion('Falta el archivo.', 'archivo');
        if (in_array((int)$f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw HttpError::validacion('El archivo pesa demasiado (máximo 25 MB).', 'archivo');
        if ((int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw HttpError::validacion('No ha llegado el archivo. Inténtalo otra vez.', 'archivo');
        return $this->guardarAdjunto($acc, $id, (string)$f['tmp_name'], (string)$f['name'], true);
    }

    /** Lo mismo desde un fichero en disco (tests). */
    public function guardarAdjunto(Acceso $acc, int $id, string $origen, string $nombre, bool $mover = false): array
    {
        $nombre = mb_substr(trim(str_replace(["\0", '/', '\\'], '', $nombre)), 0, 240) ?: 'archivo';
        if (!upload_ext_ok($nombre)) throw HttpError::validacion('Ese tipo de archivo no está permitido.', 'archivo');
        $tam = @filesize($origen);
        if (!$tam) throw HttpError::validacion('El archivo está vacío.', 'archivo');
        if ($tam > self::MAX_ADJUNTO) throw HttpError::validacion('El archivo pesa demasiado (máximo 25 MB).', 'archivo');
        if (!is_dir($this->carpetaSubidas) && !@mkdir($this->carpetaSubidas, 0755, true) && !is_dir($this->carpetaSubidas)) {
            throw new \RuntimeException('No se puede crear ' . $this->carpetaSubidas);
        }
        $fn = upload_nombre_seguro($nombre, 'crm');
        $destino = $this->carpetaSubidas . '/' . $fn;
        if (!($mover ? @move_uploaded_file($origen, $destino) : @copy($origen, $destino))) throw new \RuntimeException('No se ha podido guardar el adjunto');
        @chmod($destino, 0644);
        $ext = upload_ext($nombre);
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            require_once __DIR__ . '/../../../admin/lib/imagen.php';
            if (function_exists('img_optimizar')) img_optimizar($destino, 2000);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($destino) ?: '';
        $this->pdo->prepare('INSERT INTO attachments (contact_id, nombre, url, tipo, fecha_subida) VALUES (?,?,?,?,NOW())')
            ->execute([$id, $nombre, '../archivo.php?d=crm&f=' . $fn, mb_substr($mime, 0, 60)]);
        $this->historial->anotar($id, null, 'archivo', 'Archivo adjuntado: ' . mb_substr($nombre, 0, 200));
        return $this->ficha($acc, $id)['adjuntos'];
    }

    public function borrarAdjunto(Acceso $acc, int $adjuntoId): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.borrar');
        $st = $this->pdo->prepare('SELECT contact_id, url FROM attachments WHERE id = ?');
        $st->execute([$adjuntoId]);
        $r = $st->fetch() ?: throw HttpError::noEncontrado('Archivo no encontrado.');
        $cid = (int)$r['contact_id'];
        $this->contactos->visible($acc, $cid);
        $this->pdo->prepare('DELETE FROM attachments WHERE id = ?')->execute([$adjuntoId]);
        $fn = self::fichero((string)$r['url']);
        if ($fn !== '' && is_file($this->carpetaSubidas . '/' . $fn)) @unlink($this->carpetaSubidas . '/' . $fn);
        return $this->ficha($acc, $cid)['adjuntos'];
    }

    /** Nombre del fichero en disco a partir de la URL guardada (formato nuevo y antiguo). */
    public static function fichero(string $url): string
    {
        if (preg_match('/[?&]f=([^&]+)/', $url, $m)) $fn = rawurldecode($m[1]);
        else $fn = basename((string)parse_url($url, PHP_URL_PATH));
        $fn = basename(str_replace("\0", '', $fn));
        return $fn === '.' || $fn === '..' ? '' : $fn;
    }

    /* ---------- Ayudas ---------- */

    private function contactoDe(string $tabla, int $id, string $msg): int
    {
        $st = $this->pdo->prepare("SELECT contact_id FROM `$tabla` WHERE id = ?");
        $st->execute([$id]);
        $c = $st->fetchColumn();
        if ($c === false) throw HttpError::noEncontrado($msg);
        return (int)$c;
    }

    private function filas(string $sql, array $p): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }

    private function propuesta(array $r): array
    {
        return ['id' => (int)$r['id'], 'nombre' => (string)($r['nombre'] ?: 'Propuesta'), 'importe' => Dinero::num($r['importe']),
            'estado' => isset(Catalogos::ESTADOS_PROPUESTA[$r['estado']]) ? (string)$r['estado'] : 'enviada',
            'fecha_envio' => $r['fecha_envio'] ?: null, 'url_archivo' => (string)($r['url_archivo'] ?? '')];
    }

    private function adjunto(array $r): array
    {
        $fn = self::fichero((string)$r['url']);
        return ['id' => (int)$r['id'], 'nombre' => (string)($r['nombre'] ?: $fn), 'url' => $fn !== '' ? 'archivo.php?d=crm&f=' . rawurlencode($fn) : '',
            'mime' => (string)($r['tipo'] ?? ''), 'fecha' => (string)$r['fecha_subida']];
    }

    private function reunion(array $r): array
    {
        $docs = json_decode((string)($r['notas_doc'] ?? ''), true);
        $docs = is_array($docs) ? array_values(array_filter(array_map(
            fn($d) => is_array($d) && preg_match('#^https://#i', (string)($d['url'] ?? '')) ? ['title' => (string)($d['title'] ?? 'Notas de la reunión'), 'url' => (string)$d['url']] : null,
            $docs
        ))) : [];
        return ['id' => (int)$r['id'], 'fecha' => $r['fecha'] ?: null, 'hora' => (string)($r['hora'] ?? ''), 'titulo' => (string)($r['titulo'] ?? ''),
            'estado' => isset(Catalogos::ESTADOS_REUNION[$r['estado']]) ? (string)$r['estado'] : 'agendada', 'notas' => (string)($r['notas'] ?? ''), 'docs' => $docs];
    }
}
