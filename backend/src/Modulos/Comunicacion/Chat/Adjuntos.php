<?php
namespace Croilab\Modulos\Comunicacion\Chat;

use Croilab\Http\HttpError;

/* Ficheros adjuntos del chat.

   El antiguo decidía por la extensión del nombre original, guardaba
   `<12hex>_<nombre original>` y los servía por archivo.php sin mirar si quien
   los pedía estaba en la sala. Aquí:
     · el tipo se mira en el CONTENIDO (finfo); la extensión solo desempata
       formatos que finfo ve como «zip» u «ole» (docx, xlsx, doc…),
     · nombre aleatorio (96 bits) con la extensión del tipo real,
     · 25 MB por fichero y 10 por mensaje,
     · nada ejecutable ni que el navegador pueda interpretar (svg, html…),
     · se guardan en uploads/chat (uploads/.htaccess no deja ejecutar nada) y
       se sirven por la API comprobando que eres de la sala (ChatController). */
final class Adjuntos
{
    public const MAX_BYTES = 25 * 1024 * 1024;
    public const MAX_POR_MENSAJE = 10;
    public const CARPETA = 'chat';

    /* Tipo real => extensión con la que se guarda. */
    private const TIPOS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/bmp' => 'bmp', 'image/x-ms-bmp' => 'bmp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc', 'application/vnd.ms-excel' => 'xls', 'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'text/plain' => 'txt', 'text/csv' => 'csv',
        'application/zip' => 'zip', 'application/x-rar' => 'rar', 'application/vnd.rar' => 'rar', 'application/x-rar-compressed' => 'rar',
        'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'audio/webm' => 'webm',
        'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'video/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/vnd.wave' => 'wav',
        'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a',
    ];
    /* Formatos que son un zip o un contenedor OLE por dentro: finfo no siempre
       sabe cuál es, así que manda la extensión del nombre (solo entre estos). */
    private const CONTENEDORES = [
        'application/zip' => ['docx', 'xlsx', 'pptx', 'zip'],
        'application/octet-stream' => ['docx', 'xlsx', 'pptx', 'doc', 'xls', 'ppt'],
        'application/cdfv2' => ['doc', 'xls', 'ppt'],
        'application/x-ole-storage' => ['doc', 'xls', 'ppt'],
        'text/plain' => ['csv', 'txt'],
    ];
    /* Lo que el navegador puede enseñar sin riesgo dentro de la página (el resto se descarga). */
    private const EN_LINEA = ['jpg', 'png', 'gif', 'webp', 'avif', 'bmp', 'pdf', 'mp4', 'mov', 'webm', 'mp3', 'ogg', 'wav', 'm4a'];
    private const MIME_EXT = [
        'jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'bmp' => 'image/bmp',
        'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'zip' => 'application/zip', 'rar' => 'application/vnd.rar',
        'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];
    public const IMAGENES = ['jpg', 'png', 'gif', 'webp', 'avif', 'bmp'];

    public function __construct(private readonly string $base) {}

    public static function porDefecto(): self
    {
        return new self(dirname(__DIR__, 4) . '/uploads');
    }

    public function carpeta(): string
    {
        return $this->base . '/' . self::CARPETA;
    }

    /**
     * Normaliza $_FILES['adjuntos'] (uno o varios) a una lista de
     * [tmp, nombre, error, tamano]. Ignora los huecos vacíos del formulario.
     */
    public static function desdeFiles(mixed $f): array
    {
        if (!is_array($f) || !isset($f['tmp_name'])) return [];
        $out = [];
        $n = is_array($f['tmp_name']) ? count($f['tmp_name']) : 1;
        for ($i = 0; $i < $n; $i++) {
            $g = fn(string $k) => is_array($f[$k] ?? null) ? ($f[$k][$i] ?? null) : ($f[$k] ?? null);
            $error = (int)$g('error');
            if ($error === UPLOAD_ERR_NO_FILE) continue;
            $out[] = ['tmp' => (string)$g('tmp_name'), 'nombre' => (string)$g('name'), 'error' => $error, 'tamano' => (int)$g('size'), 'subido' => true];
        }
        return $out;
    }

    /**
     * Valida y guarda los ficheros. Si alguno no vale, no se guarda ninguno (422).
     * @param array<int, array{tmp:string, nombre:string, error?:int, subido?:bool}> $ficheros
     * @return array<int, array{fn:string, orig:string, img:int, mime:string, size:int}>
     */
    public function guardar(array $ficheros): array
    {
        if (count($ficheros) > self::MAX_POR_MENSAJE) throw HttpError::validacion('Como mucho ' . self::MAX_POR_MENSAJE . ' adjuntos por mensaje.', 'adjuntos');
        $listos = [];
        foreach ($ficheros as $f) {
            $orig = self::nombreLimpio((string)$f['nombre']);
            $error = (int)($f['error'] ?? UPLOAD_ERR_OK);
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) throw HttpError::validacion("«{$orig}» pesa demasiado (máximo 25 MB).", 'adjuntos');
            if ($error !== UPLOAD_ERR_OK) throw HttpError::validacion("No ha llegado «{$orig}». Inténtalo otra vez.", 'adjuntos');
            if (!empty($f['subido']) && !is_uploaded_file($f['tmp'])) throw HttpError::validacion("No ha llegado «{$orig}». Inténtalo otra vez.", 'adjuntos');
            $tam = @filesize($f['tmp']);
            if ($tam === false || $tam <= 0) throw HttpError::validacion("«{$orig}» está vacío.", 'adjuntos');
            if ($tam > self::MAX_BYTES) throw HttpError::validacion("«{$orig}» pesa demasiado (máximo 25 MB).", 'adjuntos');
            $ext = self::extensionReal($f['tmp'], $orig);
            if ($ext === null) throw HttpError::validacion("«{$orig}» no es un tipo de archivo admitido (imágenes, PDF, Office, texto, zip, audio o vídeo).", 'adjuntos');
            $listos[] = $f + ['ext' => $ext, 'orig' => $orig, 'size' => $tam];
        }

        $dir = $this->carpeta();
        if ($listos && !is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new \RuntimeException("No se puede crear $dir");
        /* Segunda barrera: estos ficheros solo salen por la API (con permiso), nunca por su URL. */
        if ($listos && !is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "# Adjuntos del chat: se sirven solo por la API (/api/v1/chat/adjuntos).\nRequire all denied\n<IfModule mod_php.c>\n    php_admin_flag engine off\n</IfModule>\n");
        }
        $guardados = [];
        try {
            foreach ($listos as $f) {
                $fn = bin2hex(random_bytes(12)) . '.' . $f['ext'];
                $destino = $dir . '/' . $fn;
                $ok = !empty($f['subido']) ? @move_uploaded_file($f['tmp'], $destino) : @copy($f['tmp'], $destino);
                if (!$ok) throw new \RuntimeException('No se ha podido guardar el adjunto en ' . $dir);
                @chmod($destino, 0644);
                $img = in_array($f['ext'], self::IMAGENES, true);
                if ($img) {
                    /* Reduce las fotos del móvil y les quita el EXIF (ubicación GPS). */
                    require_once dirname(__DIR__, 4) . '/admin/lib/imagen.php';
                    img_optimizar($destino);
                    clearstatcache(true, $destino);
                }
                $guardados[] = ['fn' => $fn, 'orig' => $f['orig'], 'img' => $img ? 1 : 0, 'mime' => self::MIME_EXT[$f['ext']] ?? 'application/octet-stream', 'size' => (int)(@filesize($destino) ?: $f['size'])];
            }
        } catch (\Throwable $e) {
            $this->borrar($guardados);
            throw $e;
        }
        return $guardados;
    }

    /** Extensión según el contenido, o null si no es un tipo admitido. */
    public static function extensionReal(string $ruta, string $nombre): ?string
    {
        $mime = strtolower((string)((new \finfo(FILEINFO_MIME_TYPE))->file($ruta) ?: ''));
        $extNombre = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        if ($extNombre === 'jpeg') $extNombre = 'jpg';
        if (isset(self::CONTENEDORES[$mime]) && in_array($extNombre, self::CONTENEDORES[$mime], true)) return $extNombre;
        $ext = self::TIPOS[$mime] ?? null;
        if ($ext === null) return null;
        /* Una imagen tiene que poder leerse como imagen (un .jpg con otra cosa dentro, no). */
        if (in_array($ext, ['jpg', 'png', 'gif', 'webp', 'bmp'], true) && @getimagesize($ruta) === false) return null;
        return $ext;
    }

    /** Ruta en disco de un adjunto guardado (o null si no está). Solo el nombre: nada de rutas. */
    public function ruta(string $fn): ?string
    {
        $fn = basename(str_replace("\0", '', $fn));
        if ($fn === '' || $fn === '.' || $fn === '..') return null;
        $base = realpath($this->carpeta());
        $ruta = $base ? realpath($base . DIRECTORY_SEPARATOR . $fn) : false;
        if (!$ruta || !is_file($ruta) || !str_starts_with($ruta, $base . DIRECTORY_SEPARATOR)) return null;
        return $ruta;
    }

    /** Cómo servirlo: [mime, en línea]. Lo que no está en la lista se descarga siempre. */
    public static function comoServir(string $fn): array
    {
        $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') $ext = 'jpg';
        if (in_array($ext, self::EN_LINEA, true)) return [self::MIME_EXT[$ext], true];
        return ['application/octet-stream', false];
    }

    /** @param array<int, array{fn?:string}> $adjuntos */
    public function borrar(array $adjuntos): void
    {
        foreach ($adjuntos as $a) {
            $r = $this->ruta((string)($a['fn'] ?? ''));
            if ($r !== null) @unlink($r);
        }
    }

    /** Nombre original presentable: sin rutas ni caracteres de control, ≤120. */
    public static function nombreLimpio(string $n): string
    {
        $n = basename(str_replace('\\', '/', $n));
        $n = trim((string)preg_replace('/[\x00-\x1f\x7f]+/u', '', $n));
        if ($n === '') $n = 'archivo';
        return mb_substr($n, 0, 120);
    }
}
