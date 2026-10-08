<?php
namespace Croilab\Modulos\Tareas;

use Croilab\Http\HttpError;

/* Ficheros de las tareas (adjuntos, imágenes de la descripción y de los
   comentarios) en backend/uploads/tasks/. Se sirven solo por archivo.php
   (sesión + ver.tareas + alcance de la tarea).

   · Lista blanca de extensiones única del ERP (upload_extensiones_ok: sin SVG).
   · Nombre en disco «{tarea}_{azar}.{ext}»: nunca el original.
   · Las fotos se reducen y pierden el EXIF (img_optimizar). */
final class ArchivosTarea
{
    public const IMAGENES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'];
    public const MAX_BYTES = 25 * 1024 * 1024;

    public function __construct(private readonly string $dir) {}

    public static function porDefecto(): self
    {
        return new self(dirname(__DIR__, 3) . '/uploads/tasks');
    }

    /**
     * Normaliza $_FILES['x'] (uno o varios) a [['tmp', 'nombre', 'error', 'tam'], …].
     * @return array<int, array{tmp:string, nombre:string, error:int, tam:int}>
     */
    public static function deFiles(mixed $f): array
    {
        if (!is_array($f) || !isset($f['tmp_name'])) return [];
        if (!is_array($f['tmp_name'])) return [['tmp' => (string)$f['tmp_name'], 'nombre' => (string)($f['name'] ?? ''), 'error' => (int)($f['error'] ?? 4), 'tam' => (int)($f['size'] ?? 0)]];
        $out = [];
        foreach ($f['tmp_name'] as $i => $tmp) {
            $out[] = ['tmp' => (string)$tmp, 'nombre' => (string)($f['name'][$i] ?? ''), 'error' => (int)($f['error'][$i] ?? 4), 'tam' => (int)($f['size'][$i] ?? 0)];
        }
        return $out;
    }

    /**
     * Guarda un fichero y devuelve [fn, nombre original, mime, es_imagen].
     * $subido = true exige que venga de una subida HTTP (is_uploaded_file).
     * @throws HttpError 422 si no vale
     */
    public function guardar(array $f, int $taskId, bool $subido = true): array
    {
        $nombre = trim(str_replace(["\0", '/', '\\'], '', (string)($f['nombre'] ?? ''))) ?: 'archivo';
        $err = (int)($f['error'] ?? UPLOAD_ERR_OK);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw HttpError::validacion("«{$nombre}» pesa demasiado.", 'archivos');
        if ($err !== UPLOAD_ERR_OK) throw HttpError::validacion("No ha llegado «{$nombre}». Inténtalo otra vez.", 'archivos');
        $tmp = (string)($f['tmp'] ?? '');
        if ($subido && !is_uploaded_file($tmp)) throw HttpError::validacion("No ha llegado «{$nombre}». Inténtalo otra vez.", 'archivos');
        if (!is_file($tmp)) throw HttpError::validacion("No ha llegado «{$nombre}».", 'archivos');
        $tam = (int)@filesize($tmp);
        if ($tam <= 0) throw HttpError::validacion("«{$nombre}» está vacío.", 'archivos');
        if ($tam > self::MAX_BYTES) throw HttpError::validacion("«{$nombre}» pesa más de 25 MB.", 'archivos');
        if (!upload_ext_ok($nombre)) throw HttpError::validacion("«{$nombre}» no es de un tipo permitido.", 'archivos');

        $ext = upload_ext($nombre);
        $imagen = in_array($ext, self::IMAGENES, true);
        /* Una «imagen» que no lo es (un HTML con extensión .png) no se acepta. */
        if ($imagen && $ext !== 'avif' && @getimagesize($tmp) === false) throw HttpError::validacion("«{$nombre}» no es una imagen válida.", 'archivos');

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) throw new \RuntimeException('No se puede crear ' . $this->dir);
        $fn = upload_nombre_seguro($nombre, (string)$taskId);
        $destino = $this->dir . '/' . $fn;
        $ok = $subido ? @move_uploaded_file($tmp, $destino) : @copy($tmp, $destino);
        if (!$ok) throw new \RuntimeException('No se ha podido guardar ' . $fn);
        @chmod($destino, 0644);
        if ($imagen && in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            require_once dirname(__DIR__, 3) . '/admin/lib/imagen.php';
            if (function_exists('img_optimizar')) { try { img_optimizar($destino, 2400); } catch (\Throwable $e) { error_log('img_optimizar: ' . $e->getMessage()); } }
        }
        $mime = upload_mime_seguro($ext) ?? ((new \finfo(FILEINFO_MIME_TYPE))->file($destino) ?: 'application/octet-stream');
        return [$fn, mb_substr($nombre, 0, 240), $mime, $imagen];
    }

    /** Borra el fichero (solo el nombre: nada de rutas). */
    public function borrar(string $fn): void
    {
        $fn = basename($fn);
        if ($fn === '' || $fn === '.' || $fn === '..') return;
        $ruta = $this->dir . '/' . $fn;
        if (is_file($ruta)) @unlink($ruta);
    }
}
