<?php
/* Optimización de imágenes subidas: reduce las fotos del móvil (3–5 MB) a unos
   cientos de KB sin que se note, para ahorrar espacio en el hosting.

   Uso: justo DESPUÉS de mover el archivo subido a su carpeta de uploads/:
       if (@move_uploaded_file($tmp, $dir.'/'.$fn)) { img_optimizar($dir.'/'.$fn); … }

   Qué hace (solo con JPEG, PNG y WebP; nada más):
     · endereza las fotos JPEG según su orientación EXIF (si no, salen giradas),
     · reduce si el lado mayor pasa de $ladoMax px, manteniendo la proporción,
     · recomprime (JPEG/WebP a $calidad; PNG sigue siendo PNG y conserva la transparencia),
     · quita los metadatos EXIF (privacidad: la ubicación GPS de las fotos),
     · conserva el MISMO nombre y extensión (la base de datos ya guarda ese nombre),
     · solo sobrescribe si el resultado ocupa menos que el original.

   Nunca rompe una subida: si falta GD, si la imagen es enorme (más de ~40 megapíxeles)
   o si algo falla, deja el archivo tal cual y apunta el motivo en error_log.
   Devuelve true si ha reescrito el archivo y false si lo ha dejado como estaba. */

if (!defined('IMG_MAX_MEGAPIXELES')) define('IMG_MAX_MEGAPIXELES', 40000000);

if (!function_exists('img_optimizar')) {
function img_optimizar($ruta, $ladoMax = 2000, $calidad = 82) {
  $origen = null; $final = null; $tmp = null;
  try {
    $ruta = (string)$ruta;
    if ($ruta === '' || !is_file($ruta) || !is_writable($ruta)) return false;

    /* 1) Solo JPEG, PNG y WebP, por extensión Y por contenido real. */
    $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) return false;
    if (!function_exists('imagecreatetruecolor') || !function_exists('getimagesize')) return false;
    $info = @getimagesize($ruta);
    if ($info === false) return false;
    $tipo = (int)$info[2];
    $w = (int)$info[0]; $h = (int)$info[1];
    if ($w < 1 || $h < 1) return false;

    /* Soporte de GD para ese formato concreto (Hostinger lo trae; algunos Docker no). */
    $soporte = function_exists('imagetypes') ? imagetypes() : 0;
    if ($tipo === IMAGETYPE_JPEG) {
      if (!($soporte & IMG_JPG) || !function_exists('imagecreatefromjpeg')) return false;
    } elseif ($tipo === IMAGETYPE_PNG) {
      if (!($soporte & IMG_PNG) || !function_exists('imagecreatefrompng')) return false;
    } elseif ($tipo === IMAGETYPE_WEBP) {
      if (!defined('IMG_WEBP') || !($soporte & IMG_WEBP) || !function_exists('imagecreatefromwebp')) return false;
    } else {
      return false;   // GIF, BMP, AVIF, SVG… no se tocan
    }

    /* 2) Protege la memoria: las imágenes gigantes se dejan como están. */
    if ($w * $h > IMG_MAX_MEGAPIXELES) return false;

    /* Las animaciones no se tocan (GD solo conservaría el primer fotograma). */
    if (img_es_animada($ruta, $tipo)) return false;

    /* Memoria aproximada: original + copia reducida/girada, 4 bytes por píxel y margen.
       Si el límite de PHP no llega, se intenta subir; si no se puede, no se toca. */
    $necesaria = (int)($w * $h * 4 * 2.2) + 8 * 1024 * 1024;
    if (!img_memoria_suficiente($necesaria)) {
      error_log('img_optimizar: memoria insuficiente para '.basename($ruta).' ('.$w.'x'.$h.')');
      return false;
    }

    $tamOriginal = (int)@filesize($ruta);
    if ($tamOriginal <= 0) return false;

    /* 3) Abrir. */
    if ($tipo === IMAGETYPE_JPEG)      $origen = @imagecreatefromjpeg($ruta);
    elseif ($tipo === IMAGETYPE_PNG)   $origen = @imagecreatefrompng($ruta);
    else                               $origen = @imagecreatefromwebp($ruta);
    if (!$origen) { error_log('img_optimizar: GD no pudo abrir '.basename($ruta)); return false; }
    $conAlfa = ($tipo !== IMAGETYPE_JPEG);
    if ($conAlfa) { imagealphablending($origen, false); imagesavealpha($origen, true); }

    /* 4) Enderezar según la orientación EXIF (solo JPEG). */
    if ($tipo === IMAGETYPE_JPEG) {
      $orient = img_orientacion_exif($ruta);
      if ($orient > 1) {
        $girada = img_aplicar_orientacion($origen, $orient);
        if ($girada && $girada !== $origen) { imagedestroy($origen); $origen = $girada; }
      }
    }
    $w = imagesx($origen); $h = imagesy($origen);

    /* 5) Reducir si el lado mayor pasa del máximo, manteniendo la proporción. */
    $ladoMax = max(1, (int)$ladoMax);
    $final = $origen;
    if (max($w, $h) > $ladoMax) {
      $f = $ladoMax / max($w, $h);
      $nw = max(1, (int)round($w * $f)); $nh = max(1, (int)round($h * $f));
      $lienzo = imagecreatetruecolor($nw, $nh);
      if (!$lienzo) throw new RuntimeException('no se pudo crear el lienzo');
      $final = $lienzo;
      if ($conAlfa) {
        imagealphablending($final, false); imagesavealpha($final, true);
        imagefill($final, 0, 0, imagecolorallocatealpha($final, 0, 0, 0, 127));
      }
      if (!imagecopyresampled($final, $origen, 0, 0, 0, 0, $nw, $nh, $w, $h)) throw new RuntimeException('falló el redimensionado');
      imagedestroy($origen); $origen = null;
    }

    /* 6) Recomprimir a un temporal de la MISMA carpeta (así el cambio final es un rename).
          Al volver a codificar con GD no se copia ningún metadato: el EXIF (y el GPS) desaparece. */
    $calidad = max(1, min(100, (int)$calidad));
    $tmp = dirname($ruta).'/.opt_'.bin2hex(random_bytes(6)).'.tmp';
    if ($tipo === IMAGETYPE_JPEG) {
      imageinterlace($final, true);                    // JPEG progresivo: suele ocupar algo menos
      $ok = @imagejpeg($final, $tmp, $calidad);
    } elseif ($tipo === IMAGETYPE_PNG) {
      $ok = @imagepng($final, $tmp, 9);                // PNG sin pérdida, compresión máxima
    } else {
      $ok = @imagewebp($final, $tmp, $calidad);
    }
    imagedestroy($final);
    if ($origen && $origen !== $final) imagedestroy($origen);
    $final = null; $origen = null;
    if (!$ok || !is_file($tmp)) throw new RuntimeException('no se pudo guardar la versión optimizada');

    /* 7) Solo se queda si ocupa menos que el original. */
    clearstatcache(true, $tmp);
    $tamNuevo = (int)@filesize($tmp);
    if ($tamNuevo <= 0 || $tamNuevo >= $tamOriginal) { @unlink($tmp); return false; }
    $perms = @fileperms($ruta);
    if (!@rename($tmp, $ruta)) {
      /* Algún sistema no deja sobrescribir con rename: copia y borra el temporal. */
      if (!@copy($tmp, $ruta)) { @unlink($tmp); throw new RuntimeException('no se pudo reemplazar el archivo'); }
      @unlink($tmp);
    }
    if ($perms) @chmod($ruta, $perms & 0777);
    clearstatcache(true, $ruta);
    return true;
  } catch (Throwable $e) {
    error_log('img_optimizar('.basename((string)$ruta).'): '.$e->getMessage());
    if ($final && $final !== $origen) @imagedestroy($final);
    if ($origen) @imagedestroy($origen);
    if ($tmp && is_file($tmp)) @unlink($tmp);
    return false;
  }
}
}

/* ¿Es una imagen animada? APNG lleva el bloque «acTL» y el WebP animado el «ANIM». */
if (!function_exists('img_es_animada')) {
function img_es_animada($ruta, $tipo) {
  $cab = @file_get_contents($ruta, false, null, 0, 256 * 1024);
  if ($cab === false) return true;   // si no se puede leer, mejor no tocarla
  if ($tipo === IMAGETYPE_PNG) {
    $p = strpos($cab, 'IDAT');
    $a = strpos($cab, 'acTL');
    return $a !== false && ($p === false || $a < $p);
  }
  if ($tipo === IMAGETYPE_WEBP) {
    /* Cabecera VP8X: el bit 1 del byte de indicadores marca la animación. */
    if (substr($cab, 12, 4) === 'VP8X' && strlen($cab) > 20 && (ord($cab[20]) & 0x02)) return true;
    if (strpos(substr($cab, 0, 4096), 'ANIM') !== false) return true;
  }
  return false;
}
}

/* Comprueba que cabe en memoria; si no, intenta subir el límite de PHP (hasta 512 MB). */
if (!function_exists('img_memoria_suficiente')) {
function img_memoria_suficiente($bytes) {
  $lim = trim((string)ini_get('memory_limit'));
  if ($lim === '' || $lim === '-1') return true;
  $n = (int)$lim; $u = strtolower(substr($lim, -1));
  if ($u === 'g') $n *= 1024 * 1024 * 1024; elseif ($u === 'm') $n *= 1024 * 1024; elseif ($u === 'k') $n *= 1024;
  $libre = $n - memory_get_usage(true);
  if ($libre >= $bytes) return true;
  $quiero = memory_get_usage(true) + $bytes;
  if ($quiero > 512 * 1024 * 1024) return false;
  return @ini_set('memory_limit', (string)(int)ceil($quiero / 1048576).'M') !== false;
}
}

/* Orientación EXIF de un JPEG (1–8; 1 = derecha). Usa la extensión exif si está y,
   si no, lee la etiqueta 0x0112 directamente del bloque APP1 del archivo. */
if (!function_exists('img_orientacion_exif')) {
function img_orientacion_exif($ruta) {
  if (function_exists('exif_read_data')) {
    $ex = @exif_read_data($ruta);
    if (is_array($ex) && isset($ex['Orientation'])) { $o = (int)$ex['Orientation']; return ($o >= 1 && $o <= 8) ? $o : 1; }
  }
  $d = @file_get_contents($ruta, false, null, 0, 256 * 1024);
  if ($d === false || strlen($d) < 4 || substr($d, 0, 2) !== "\xFF\xD8") return 1;
  $p = 2; $len = strlen($d);
  while ($p + 4 <= $len) {
    if (ord($d[$p]) !== 0xFF) return 1;
    $marca = ord($d[$p + 1]);
    if ($marca === 0xDA || $marca === 0xD9) return 1;          // empiezan los datos: no hay EXIF
    $tam = unpack('n', substr($d, $p + 2, 2))[1];
    if ($marca === 0xE1 && substr($d, $p + 4, 6) === "Exif\0\0") {
      $t = $p + 10;                                             // inicio de la cabecera TIFF
      $orden = substr($d, $t, 2);
      if ($orden === 'II') { $f16 = 'v'; $f32 = 'V'; } elseif ($orden === 'MM') { $f16 = 'n'; $f32 = 'N'; } else return 1;
      if ($t + 8 > $len) return 1;
      $ifd = $t + unpack($f32, substr($d, $t + 4, 4))[1];
      if ($ifd + 2 > $len) return 1;
      $n = unpack($f16, substr($d, $ifd, 2))[1];
      for ($i = 0; $i < $n; $i++) {
        $e = $ifd + 2 + $i * 12;
        if ($e + 12 > $len) return 1;
        if (unpack($f16, substr($d, $e, 2))[1] === 0x0112) {
          $o = unpack($f16, substr($d, $e + 8, 2))[1];
          return ($o >= 1 && $o <= 8) ? $o : 1;
        }
      }
      return 1;
    }
    $p += 2 + $tam;
  }
  return 1;
}
}

/* Aplica la orientación EXIF a la imagen. Devuelve la imagen resultante (puede ser otra). */
if (!function_exists('img_aplicar_orientacion')) {
function img_aplicar_orientacion($im, $o) {
  /* imagerotate gira en sentido ANTIHORARIO: -90 = 90° a la derecha. */
  switch ((int)$o) {
    case 2: imageflip($im, IMG_FLIP_HORIZONTAL); return $im;
    case 3: return imagerotate($im, 180, 0) ?: $im;
    case 4: imageflip($im, IMG_FLIP_VERTICAL); return $im;
    case 5: imageflip($im, IMG_FLIP_HORIZONTAL); return imagerotate($im, 90, 0) ?: $im;
    case 6: return imagerotate($im, -90, 0) ?: $im;
    case 7: imageflip($im, IMG_FLIP_HORIZONTAL); return imagerotate($im, -90, 0) ?: $im;
    case 8: return imagerotate($im, 90, 0) ?: $im;
  }
  return $im;
}
}
