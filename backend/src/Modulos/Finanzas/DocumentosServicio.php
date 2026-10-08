<?php
namespace Croilab\Modulos\Finanzas;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Documentos subidos en Facturas: gastos (y algún ingreso externo) con su PDF
   o imagen. Cada uno crea su propio apunte de caja al instante (no pasa por
   «pagada»). Se sirven por archivo.php?d=facturas (con sesión y ver.finanzas).
   Corrige al antiguo: tipo REAL del archivo (finfo) y tamaño máximo; borrar
   va a la papelera con su apunte (antes era físico, §17.13 y §17.20). */
final class DocumentosServicio
{
    public const TIPOS = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public const MAX_BYTES = 15 * 1024 * 1024;

    public function __construct(
        private readonly PDO $pdo,
        private readonly EmisoresServicio $emisores,
        private readonly Caja $caja,
        private readonly ProyectosServicio $proyectos,
        private readonly string $carpeta
    ) {}

    public static function carpetaPorDefecto(): string
    {
        return dirname(__DIR__, 3) . '/uploads/facturas';
    }

    public function listar(Acceso $acc, array $f): array
    {
        $acc->exigir('ver.finanzas');
        $w = '';
        $p = [];
        if (($f['emisor'] ?? '') !== '') { $w .= ' AND u.emisor = ?'; $p[] = (string)$f['emisor']; }
        if (($f['tipo'] ?? '') !== '') {
            if (!in_array($f['tipo'], ['gasto', 'ingreso'], true)) throw HttpError::validacion('Tipo no válido.', 'tipo');
            $w .= ' AND u.tipo = ?';
            $p[] = (string)$f['tipo'];
        }
        if (($f['mes'] ?? '') !== '') {
            [$ini, $fin] = Validar::rangoMes(Validar::mes((string)$f['mes']));
            $w .= ' AND u.fecha BETWEEN ? AND ?';
            array_push($p, $ini, $fin);
        }
        return ['items' => $this->filas($w, $p)];
    }

    /** @return array<int,array> */
    public function filas(string $where, array $params): array
    {
        $st = $this->pdo->prepare('SELECT u.*, a.project_id, a.deducible, p.nombre AS project_nombre, p.color AS project_color
                                   FROM invoice_uploads u LEFT JOIN accounting a ON a.id = u.acc_id LEFT JOIN projects p ON p.id = a.project_id
                                   WHERE 1=1 ' . $where . ' ORDER BY u.fecha DESC, u.id DESC');
        $st->execute($params);
        return array_map([$this, 'item'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    private function item(array $r): array
    {
        $fn = (string)($r['filename'] ?? '');
        $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
        return [
            'id' => (int)$r['id'], 'emisor' => (string)$r['emisor'], 'tipo' => (string)$r['tipo'],
            'concepto' => (string)$r['concepto'], 'proveedor' => (string)$r['proveedor'],
            'importe' => Dinero::c($r['importe']), 'fecha' => $r['fecha'] ?: null,
            'archivo' => $fn !== '' ? $fn : null, 'nombre_archivo' => (string)($r['orig_name'] ?: $fn), 'mime' => (string)$r['mime'],
            'tipo_archivo' => $fn === '' ? null : ($ext === 'pdf' ? 'pdf' : 'imagen'),
            'efectivo' => (bool)$r['efectivo'], 'personal' => (bool)$r['personal'], 'deducible' => (bool)($r['deducible'] ?? false),
            'project' => !empty($r['project_id']) ? ['id' => (int)$r['project_id'], 'nombre' => (string)$r['project_nombre'], 'color' => (string)($r['project_color'] ?: '#2f6df6')] : null,
            'acc_id' => $r['acc_id'] ? (int)$r['acc_id'] : null,
        ];
    }

    public function detalle(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.finanzas');
        $r = $this->filas(' AND u.id = ?', [$id]);
        if (!$r) throw HttpError::noEncontrado('Documento no encontrado.');
        return $r[0];
    }

    /**
     * Alta o edición. $archivo = entrada de $_FILES (o null). $subido = viene
     * de una petición HTTP (move_uploaded_file); false en tests y procesos internos.
     */
    public function guardar(Acceso $acc, ?int $id, array $d, ?array $archivo, bool $subido = true): array
    {
        Validar::escribe($acc, 'conta.editar');
        $antes = null;
        if ($id) {
            $st = $this->pdo->prepare('SELECT * FROM invoice_uploads WHERE id = ?');
            $st->execute([$id]);
            $antes = $st->fetch(PDO::FETCH_ASSOC) ?: throw HttpError::noEncontrado('Documento no encontrado.');
        }
        $emisor = (string)($d['emisor'] ?? ($antes['emisor'] ?? ''));
        if (!$this->emisores->existe($emisor)) throw HttpError::validacion('Ese emisor no existe.', 'emisor');
        $tipo = (string)($d['tipo'] ?? ($antes['tipo'] ?? 'gasto'));
        if (!in_array($tipo, ['gasto', 'ingreso'], true)) throw HttpError::validacion('Tipo no válido.', 'tipo');
        $concepto = Validar::texto($d, 'concepto', 250, (string)($antes['concepto'] ?? ''));
        $proveedor = Validar::texto($d, 'proveedor', 200, (string)($antes['proveedor'] ?? ''));
        $importe = Dinero::exigir($d['importe'] ?? ($antes['importe'] ?? null), 'importe', 'el importe', 1, 999999999);
        $fecha = Validar::fecha($d, 'fecha') ?? ($antes['fecha'] ?? date('Y-m-d'));
        $efectivo = Validar::bool($d, 'efectivo', (bool)($antes['efectivo'] ?? false));
        $personal = Validar::bool($d, 'personal', (bool)($antes['personal'] ?? false));
        $pid = null;
        $nombreP = Validar::texto($d, 'project_nombre', 160);
        if ($nombreP !== '') $pid = $this->proyectos->obtenerOCrear($acc, $nombreP, null);
        elseif (array_key_exists('project_id', $d)) $pid = Validar::idONull($d, 'project_id');
        elseif ($antes && $antes['acc_id']) {
            $st = $this->pdo->prepare('SELECT project_id FROM accounting WHERE id = ?');
            $st->execute([(int)$antes['acc_id']]);
            $pid = ($v = $st->fetchColumn()) ? (int)$v : null;
        }

        $nuevoFichero = null;
        if ($archivo !== null && ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $nuevoFichero = $this->guardarFichero($archivo, $subido);
        }
        try {
            $docId = Tx::run($this->pdo, function () use ($id, $antes, $emisor, $tipo, $concepto, $proveedor, $importe, $fecha, $efectivo, $personal, $pid, $nuevoFichero) {
                $campos = ['emisor' => $emisor, 'tipo' => $tipo, 'concepto' => $concepto, 'proveedor' => $proveedor, 'importe' => $importe,
                           'fecha' => $fecha, 'efectivo' => (int)$efectivo, 'personal' => (int)$personal];
                if ($nuevoFichero) $campos += ['filename' => $nuevoFichero['filename'], 'orig_name' => $nuevoFichero['orig'], 'mime' => $nuevoFichero['mime']];
                if ($id) {
                    $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($campos)));
                    $this->pdo->prepare("UPDATE invoice_uploads SET $set WHERE id = ?")->execute([...array_values($campos), $id]);
                    $docId = $id;
                } else {
                    $cols = array_keys($campos);
                    $this->pdo->prepare('INSERT INTO invoice_uploads (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($campos));
                    $docId = (int)$this->pdo->lastInsertId();
                }
                $accId = $this->caja->guardarApunte($antes && $antes['acc_id'] ? (int)$antes['acc_id'] : null, [
                    'fecha' => $fecha, 'tipo' => $tipo,
                    'concepto' => mb_substr(($concepto !== '' ? $concepto : ($tipo === 'gasto' ? 'Gasto' : 'Ingreso')) . ($proveedor !== '' ? ' · ' . $proveedor : ''), 0, 250),
                    'categoria' => $tipo === 'gasto' ? 'Gasto' : 'Cliente', 'importe' => $importe,
                    'metodo' => $efectivo ? 'efectivo' : 'transferencia', 'legal' => $efectivo ? 0 : 1, 'ambito' => $emisor,
                    'deducible' => $tipo === 'gasto' && $personal ? 1 : 0, 'personal' => (int)$personal, 'project_id' => $pid,
                    'notas' => 'Subida en Facturas', 'upload_id' => $docId,
                ]);
                $this->pdo->prepare('UPDATE invoice_uploads SET acc_id = ? WHERE id = ?')->execute([$accId, $docId]);
                return $docId;
            });
        } catch (\Throwable $e) {
            if ($nuevoFichero) @unlink($this->carpeta . '/' . $nuevoFichero['filename']);
            throw $e;
        }
        /* Archivo sustituido: el viejo ya no lo referencia nadie. */
        if ($nuevoFichero && $antes && $antes['filename']) @unlink($this->carpeta . '/' . basename((string)$antes['filename']));
        return $this->detalle($acc, $docId);
    }

    /** A la papelera con su apunte (el archivo se queda hasta que se purgue). */
    public function borrar(Acceso $acc, int $id): int
    {
        Validar::escribe($acc, 'conta.editar');
        $st = $this->pdo->prepare('SELECT concepto, proveedor, tipo FROM invoice_uploads WHERE id = ?');
        $st->execute([$id]);
        $d = $st->fetch(PDO::FETCH_ASSOC) ?: throw HttpError::noEncontrado('Documento no encontrado.');
        $titulo = ($d['concepto'] ?: ($d['tipo'] === 'gasto' ? 'Gasto' : 'Ingreso')) . ($d['proveedor'] ? ' · ' . $d['proveedor'] : '');
        $tid = pap_borrar('invoice_uploads', $id, 'documento', $titulo, [['tabla' => 'accounting', 'fk' => 'upload_id']]);
        if (!$tid) throw new HttpError(500, 'No se ha podido mover el documento a la papelera.', 'papelera');
        return $tid;
    }

    /** @return array{filename:string, orig:string, mime:string} */
    private function guardarFichero(array $f, bool $subido): array
    {
        $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw HttpError::validacion('El archivo pesa demasiado (máximo 15 MB).', 'archivo');
        $tmp = (string)($f['tmp_name'] ?? '');
        if ($err !== UPLOAD_ERR_OK || $tmp === '' || ($subido && !is_uploaded_file($tmp))) throw HttpError::validacion('No ha llegado el archivo. Inténtalo otra vez.', 'archivo');
        $tam = @filesize($tmp);
        if (!$tam) throw HttpError::validacion('El archivo está vacío.', 'archivo');
        if ($tam > self::MAX_BYTES) throw HttpError::validacion('El archivo pesa demasiado (máximo 15 MB).', 'archivo');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        if (!isset(self::TIPOS[$mime])) throw HttpError::validacion('Solo se admiten PDF o imágenes (JPG, PNG, WebP).', 'archivo');
        if (!is_dir($this->carpeta) && !@mkdir($this->carpeta, 0755, true) && !is_dir($this->carpeta)) throw new \RuntimeException('No se puede crear ' . $this->carpeta);
        $nombre = bin2hex(random_bytes(16)) . '.' . self::TIPOS[$mime];
        $destino = $this->carpeta . '/' . $nombre;
        $ok = $subido ? @move_uploaded_file($tmp, $destino) : @copy($tmp, $destino);
        if (!$ok) throw new \RuntimeException('No se ha podido guardar el archivo en ' . $this->carpeta);
        @chmod($destino, 0644);
        if ($mime !== 'application/pdf') {
            $lib = dirname(__DIR__, 3) . '/admin/lib/imagen.php';
            if (is_file($lib)) {
                require_once $lib;
                if (function_exists('img_optimizar')) @img_optimizar($destino, 3000);
            }
        }
        $orig = mb_substr(preg_replace('/[\x00-\x1f\/\\\\]/u', '', (string)($f['name'] ?? '')) ?: $nombre, 0, 200);
        return ['filename' => $nombre, 'orig' => $orig, 'mime' => $mime];
    }
}
