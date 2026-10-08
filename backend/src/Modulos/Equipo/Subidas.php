<?php
namespace Croilab\Modulos\Equipo;

use Croilab\Http\HttpError;

/* Subida de imágenes (foto de perfil, logo de la agencia).

   · El tipo se mira en el CONTENIDO (finfo + getimagesize), no en la extensión
     ni en el Content-Type que manda el navegador.
   · Solo JPEG, PNG, GIF y WebP: nada de SVG (es XML y puede llevar scripts).
   · Nombre aleatorio con la extensión que corresponde al tipo real.
   · Se guardan en backend/uploads/<carpeta>/, donde uploads/.htaccess impide
     ejecutar nada; las fotos se sirven por archivo.php (con sesión).
   · Se reducen y se les quitan los metadatos EXIF (img_optimizar). */
final class Subidas
{
    public const TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    public function __construct(private readonly string $base) {}

    public static function porDefecto(): self
    {
        return new self(dirname(__DIR__, 3) . '/uploads');
    }

    public function carpeta(string $carpeta): string
    {
        return $this->base . '/' . $carpeta;
    }

    /**
     * Valida y guarda una imagen de $_FILES. Devuelve el nombre del fichero.
     * @throws HttpError 422 si no vale
     */
    public function imagenSubida(mixed $f, string $carpeta, string $prefijo, int $maxBytes, int $ladoMax): string
    {
        if (!is_array($f) || !isset($f['tmp_name'], $f['error']) || is_array($f['tmp_name'])) throw HttpError::validacion('Falta la imagen.', 'archivo');
        if ((int)$f['error'] === UPLOAD_ERR_INI_SIZE || (int)$f['error'] === UPLOAD_ERR_FORM_SIZE) throw HttpError::validacion($this->msgTamano($maxBytes), 'archivo');
        if ((int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw HttpError::validacion('No ha llegado la imagen. Inténtalo otra vez.', 'archivo');
        return $this->guardarImagen((string)$f['tmp_name'], $carpeta, $prefijo, $maxBytes, $ladoMax, true);
    }

    /** Lo mismo con un fichero ya en disco (tests y procesos internos). */
    public function guardarImagen(string $origen, string $carpeta, string $prefijo, int $maxBytes, int $ladoMax, bool $mover = false): string
    {
        $tam = @filesize($origen);
        if ($tam === false || $tam <= 0) throw HttpError::validacion('La imagen está vacía.', 'archivo');
        if ($tam > $maxBytes) throw HttpError::validacion($this->msgTamano($maxBytes), 'archivo');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($origen) ?: '';
        $info = @getimagesize($origen);
        if (!isset(self::TIPOS[$mime]) || $info === false || ($info['mime'] ?? '') !== $mime) {
            throw HttpError::validacion('Ese archivo no es una imagen JPG, PNG, GIF o WebP.', 'archivo');
        }
        $dir = $this->carpeta($carpeta);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new \RuntimeException("No se puede crear $dir");
        $nombre = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefijo) . '_' . bin2hex(random_bytes(8)) . '.' . self::TIPOS[$mime];
        $destino = $dir . '/' . $nombre;
        $ok = $mover ? @move_uploaded_file($origen, $destino) : @copy($origen, $destino);
        if (!$ok) throw new \RuntimeException('No se ha podido guardar la imagen en ' . $dir);
        @chmod($destino, 0644);
        require_once dirname(__DIR__, 3) . '/admin/lib/imagen.php';
        img_optimizar($destino, $ladoMax);
        return $nombre;
    }

    /** Borra un fichero de la carpeta (solo el nombre: nada de rutas). */
    public function borrar(string $carpeta, string $nombre): void
    {
        $nombre = basename($nombre);
        if ($nombre === '' || $nombre === '.' || $nombre === '..') return;
        $ruta = $this->carpeta($carpeta) . '/' . $nombre;
        if (is_file($ruta)) @unlink($ruta);
    }

    private function msgTamano(int $max): string
    {
        return 'La imagen pesa demasiado (máximo ' . round($max / 1048576) . ' MB).';
    }
}
