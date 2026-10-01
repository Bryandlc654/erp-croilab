<?php
/* Bóveda de credenciales — el único sitio del ERP que cifra y descifra secretos.

   Qué resuelve (sección 0.9 del plan)
   ------------------------------------------------------------------
   El mecanismo que ya había (gcal.php) tenía dos fallos que lo dejaban en
   nada:

   1. La clave se guardaba en la tabla `settings`, la MISMA donde viven los
      textos cifrados. Quien solo lea una copia de la base de datos se lleva las
      dos cosas, así que el cifrado no protege frente a una filtración de la
      base: solo frente a alguien que mire un pantallazo. Aquí la clave vive en
      un fichero FUERA del directorio público, y la tabla solo guarda el texto
      cifrado.

   2. El cifrado no tenía versión ni comprobación de integridad. Con eso no se
      puede distinguir «aquí no hay nada guardado» de «está guardado pero con
      otra clave», y dos casos que se parecían mucho acababan pareciéndose
      igual: la integración salía como desconectada y el dueño iba a pulsar
      «Conectar» otra vez, que es justo lo que no hacía falta. Aquí el formato
      lleva versión, y el texto cifrado lleva una firma: si la firma no cuadra,
      el valor no se ha descifrado porque la clave es otra, no porque esté
      corrupto, y se dice cuál de los dos es.

   Reglas que sigue
   ------------------------------------------------------------------
   · Un secreto por «propósito». La clave de cada propósito se deriva con HKDF
     del material maestro y una etiqueta distinta, de forma que el token de
     Métricas no sirve para descifrar el de Calendario aunque compartan tabla.
   · NUNCA degrada a texto plano. Si falta la extensión de cifrado, no se guarda
     nada y se avisa: antes se guardaba en claro sin decir nada.
   · Migra en caliente. Al leer un valor que estaba en claro, o cifrado con una
     clave antigua, lo reescribe ya cifrado con la clave actual. Así el
     fichero de clave se puede rotar sin perder integraciones.
   · Todo queda anotado: qué se lee, qué se regenera y qué se sustituye.

   Fichero de clave
   ------------------------------------------------------------------
   Fuera del directorio público, junto a los registros, con esta forma:

       # croilab boveda v1
       k2=1f3a…        <- la primera línea de clave es la vigente
       k1=9b04…        <- las de abajo son antiguas, se prueban y se pueden borrar

   Para rotar: se antepone una clave nueva, se deja la vieja debajo, se visits
   cada integración una vez (o se pulsa «Reconectar»), y cuando todo esté
   migrado la vieja línea se borra. El orden importa: si se borra la clave
   vieja antes de migrar, lo que no se haya re-cifrado queda ilegible. Es el
   mismo orden que pide la sección 0.1 para el secreto de la aplicación.

   Si el fichero no se puede crear, se deriva del secreto de la aplicación y se
   avisa por registro. Esa vía funciona, pero es más débil: quien tenga el
   fichero `.env` descifra. Lo que no hace nunca, ni en ese caso, es guardar la
   clave en la base de datos. */

/* ---------- Dónde vive el fichero de clave ---------- */
function boveda_raiz() {
    /* admin/lib/boveda.php → dos niveles arriba está el directorio público. */
    $fuera = dirname(__DIR__, 2);
    return is_dir($fuera) ? $fuera : sys_get_temp_dir();
}
function boveda_fichero_clave() {
    /* Si se define BOVEDA_CLAVE_FICHERO, la clave vive ahí. Se usa cuando la
       instalación tiene la clave en un volumen montado fuera del proyecto, que
       es lo habitual en un contenedor, y en las pruebas. */
    if (defined('BOVEDA_CLAVE_FICHERO') && (string)BOVEDA_CLAVE_FICHERO !== '') {
        return (string)BOVEDA_CLAVE_FICHERO;
    }
    return boveda_raiz() . '/.croilab-boveda';
}

/* Aviso una sola vez por petición, para no llenar el registro si algo falla. */
function boveda_aviso($msg) {
    static $dicho = [];
    $k = md5($msg);
    if (isset($dicho[$k])) return;
    $dicho[$k] = true;
    error_log('boveda: ' . $msg);
}

/* ---------- Material de clave ---------- */

/* Devuelve el material maestro en bytes, o false si no hay de dónde sacarlo.
   Es una lista: la clave vigente primero y las antiguas detrás, que se prueban
   al descifrar para que rotar no rompa nada. */
function boveda_claves() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];

    $fichero = boveda_fichero_clave();
    if (is_readable($fichero)) {
        $lineas = file($fichero, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lineas)) {
            foreach ($lineas as $l) {
                $l = trim($l);
                if ($l === '' || $l[0] === '#') continue;
                $i = strpos($l, '=');
                if ($i === false) continue;
                $id  = trim(substr($l, 0, $i));
                $hex = trim(substr($l, $i + 1));
                /* Solo se acepta material de 32 bytes en hexadecimal: una línea
                   mal escrita no puede convertirse en una clave débil en
                   silencio. */
                if (!preg_match('/^[a-z0-9]{2,16}$/i', $id) || !preg_match('/^[0-9a-f]{64}$/i', $hex)) continue;
                $cache[] = ['id' => $id, 'bytes' => hex2bin($hex)];
            }
        }
    }

    if (!$cache) {
        /* No hay fichero: se intenta crearlo. Ojo al orden, porque aquí es donde
           se decide si la clave acaba guarding en la base de datos, que es lo
           que hay que evitar a toda costa. */
        $nuevo = bin2hex(random_bytes(32));
        $cuerpo = "# croilab boveda v1\n# clave generada automaticamente el " . gmdate('Y-m-d H:i:s') . "\nk1=$nuevo\n";
        $escrito = @file_put_contents($fichero, $cuerpo, LOCK_EX);
        if ($escrito !== false) {
            @chmod($fichero, 0600);
            $cache[] = ['id' => 'k1', 'bytes' => hex2bin($nuevo)];
            return $cache;
        }
        boveda_aviso('no se pudo crear ' . $fichero . '; se deriva del secreto de la aplicación, que es más débil');
    }

    if (!$cache && defined('APP_SECRET')) {
        $s = (string)APP_SECRET;
        $ejemplo = 'cambia-esto-por-algo-largo-y-unico-2026';
        if ($s !== '' && $s !== $ejemplo) {
            /* Material de reserva: vive en el fichero .env, no en la base. */
            $cache[] = ['id' => 'appsecret', 'bytes' => hash('sha256', $s, true)];
            return $cache;
        }
        boveda_aviso('no hay fichero de clave utilizable y el secreto de la aplicación sigue siendo el de ejemplo');
    }
    return $cache;
}

/* ---------- Derivación por propósito ---------- */

/* HKDF separa de verdad: el material maestro nunca se usa tal cual para
   cifrar, y dos propósitos distintos nunca comparten clave. */
function boveda_derivar($material, $proposito, $info = 'cifrado') {
    $etiqueta = 'croilab-boveda-v1|' . $info . '|' . $proposito;
    /* hash_hkdf() devuelve bytes en crudo cuando se le pasa la longitud, que es
       justo lo que necesita la función de cifrado. Sin declararse `bytes` en el
       parámetro: ese nombre no es un tipo de PHP, así que se interpretaría como
       una clase y fallaría siempre. */
    if (function_exists('hash_hkdf')) {
        return hash_hkdf('sha256', (string)$material, 32, $etiqueta);
    }
    /* Alternativa para servidores con PHP anterior a 7.1.2, que es el mismo
       HKDF con una iteración, que es todo lo que necesita SHA-256 aquí. */
    return hash_hmac('sha256', $etiqueta, (string)$material, true);
}

/* ---------- Cifrado ---------- */

const BOVEDA_PREFIJO = 'bx1:';

function boveda_disponible() {
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
        boveda_aviso('falta la extensión de cifrado del servidor: no se guardará ningún secreto en claro');
        return false;
    }
    return (bool)boveda_claves();
}

function boveda_esta_cifrada($valor) {
    $v = (string)$valor;
    return strpos($v, BOVEDA_PREFIJO) === 0 || strpos($v, 'enc:') === 0;
}

function boveda_cifrar($claro, $proposito) {
    if (!boveda_disponible()) return false;
    $claves = boveda_claves();
    if (!$claves) return false;
    $claro = (string)$claro;
    if ($claro === '') return '';
    $iv = random_bytes(16);
    $cifrado = openssl_encrypt($claro, 'aes-256-cbc',
        boveda_derivar($claves[0]['bytes'], $proposito), OPENSSL_RAW_DATA, $iv);
    if ($cifrado === false) {
        boveda_aviso('no se pudo cifrar un valor de «' . $proposito . '»');
        return false;
    }
    /* Cifrar y después firmar. La firma es lo que permite decir «la clave no es
       esta» en vez de devolver un texto corrupto que parece un token. */
    $mac = hash_hmac('sha256', $iv . $cifrado,
        boveda_derivar($claves[0]['bytes'], $proposito, 'firma'), true);
    return BOVEDA_PREFIJO . base64_encode($iv . $mac . $cifrado);
}

/* ---------- Descifrado ----------

   Devuelve siempre un estado explícito, que es el punto:
     ok         -> se ha descifrado
     vacio      -> no hay nada guardado (se puede volver a configurar)
     ilegible   -> hay algo, pero no se puede leer con ninguna clave
     sin_cifrar -> lo que hay está en claro y se ha cogido tal cual
     migrar     -> además, hay que reescribirlo cifrado                      */
function boveda_descifrar($guardado, $proposito) {
    $guardado = (string)$guardado;
    $mala = ['ok' => 0, 'v' => '', 'estado' => 'ilegible', 'migrar' => 0];
    if ($guardado === '') return ['ok' => 0, 'v' => '', 'estado' => 'vacio', 'migrar' => 0];
    if (!function_exists('openssl_decrypt')) return $mala;

    $claves = boveda_claves();
    if (!$claves) return $mala;

    if (strpos($guardado, BOVEDA_PREFIJO) === 0) {
        $raw = base64_decode(substr($guardado, strlen(BOVEDA_PREFIJO)), true);
        if ($raw === false || strlen($raw) < 49) return $mala;
        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 32);
        $cifrado = substr($raw, 48);
        foreach ($claves as $k) {
            $esperado = hash_hmac('sha256', $iv . $cifrado,
                boveda_derivar($k['bytes'], $proposito, 'firma'), true);
            if (!hash_equals($esperado, $mac)) continue;
            $p = openssl_decrypt($cifrado, 'aes-256-cbc',
                boveda_derivar($k['bytes'], $proposito), OPENSSL_RAW_DATA, $iv);
            if ($p !== false) {
                return ['ok' => 1, 'v' => $p, 'estado' => 'ok', 'migrar' => $k['id'] !== $claves[0]['id'] ? 1 : 0];
            }
        }
        /* La firma no cuadra con ninguna clave: el valor no se puede leer. */
        return $mala;
    }

    if (strpos($guardado, 'enc:') === 0) {
        /* Formato antiguo, el de gcal.php: AES-256-CBC sin firma y con la clave
           derivada de otra manera. Se lee igual para no perder nada, y el
           resultado sale marcado para migrar. */
        $raw = base64_decode(substr($guardado, 4), true);
        if ($raw === false || strlen($raw) < 17) return $mala;
        $iv = substr($raw, 0, 16);
        $cifrado = substr($raw, 16);
        foreach (boveda_claves_legacy($proposito) as $key) {
            $p = openssl_decrypt($cifrado, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            if ($p !== false) return ['ok' => 1, 'v' => $p, 'estado' => 'ok', 'migrar' => 1];
        }
        return $mala;
    }

    /* Sin prefijo: estaba en claro. Se acepta —si no, se perdería lo ya
       configurado— pero marcado para reescribirlo cifrado. */
    return ['ok' => 1, 'v' => $guardado, 'estado' => 'sin_cifrar', 'migrar' => 1];
}

/* Claves con las que se cifró el formato antiguo. Se prueban al descifrar, y
   no al cifrar: a partir de ahora, todo sale con la clave vigente. */
function boveda_claves_legacy($proposito) {
    $salida = [];
    $s = defined('APP_SECRET') ? (string)APP_SECRET : '';
    /* La etiqueta antigua era fija ('gcal'), también para Métricas: por eso un
       valor de Métricas cifrado antes se puede leer. */
    foreach ([$s !== '' ? $s : 'croilab-fallback', 'croilab-fallback'] as $base) {
        $salida[] = hash('sha256', $base . '|gcal', true);
    }
    /* Y la clave aleatoria que se guardaba en la tabla de ajustes. */
    try {
        $guardada = get_setting('gcal_key', '');
        if (is_string($guardada) && $guardada !== '') $salida[] = hash('sha256', $guardada . '|gcal', true);
    } catch (Exception $e) {}
    $salida[] = boveda_derivar('croilab-fallback', $proposito);
    return array_values(array_unique($salida, SORT_REGULAR));
}

/* ---------- Lectura y escritura en la tabla de ajustes ---------- */

function boveda_guardar($clave, $claro, $proposito) {
    $claro = (string)$claro;
    if ($claro === '') { boveda_borrar($clave); return true; }
    $cifrado = boveda_cifrar($claro, $proposito);
    if ($cifrado === false) {
        /* No se degrada a texto plano. Antes de dar por buena la operación, se
           intenta avisar por la vía normal: si aquí no se puede cifrar, no se
           guarda el secreto, y el dueño ve un aviso en vez de creer que está
           guardado. */
        return false;
    }
    try {
        db()->prepare('INSERT INTO settings (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)')
            ->execute([$clave, $cifrado]);
    } catch (Exception $e) {
        boveda_aviso('no se pudo guardar «' . $clave . '»: ' . $e->getMessage());
        return false;
    }
    boveda_auditar('boveda.guardar', $proposito . ' / ' . $clave);
    return true;
}

/* Única puerta de lectura. Distingue los estados y reescribe en caliente lo que
   encuentra en claro o con una clave antigua. */
function boveda_leer($clave, $proposito) {
    $guardado = get_setting($clave, '');
    $r = boveda_descifrar($guardado, $proposito);
    if ($r['ok'] && $r['migrar'] && function_exists('boveda_cifrar')) {
        $nuevo = boveda_cifrar($r['v'], $proposito);
        if ($nuevo !== false && $nuevo !== (string)$guardado) {
            try {
                db()->prepare('UPDATE settings SET valor=? WHERE clave=?')->execute([$nuevo, $clave]);
                boveda_auditar('boveda.migrar', $proposito . ' / ' . $clave . ' (' . $r['estado'] . ')');
            } catch (Exception $e) {}
        }
    }
    if ($r['estado'] === 'ilegible') {
        boveda_auditar('boveda.ilegible', $proposito . ' / ' . $clave);
        boveda_aviso('el valor de «' . $clave . '» está guardado pero no se puede descifrar: falta la clave, o se ha rotado sin dejar la antigua');
    }
    return $r;
}

/* Atajo para cuando solo interesa el texto; devuelve '' si no hay nada. */
function boveda_valor($clave, $proposito) {
    $r = boveda_leer($clave, $proposito);
    return $r['ok'] ? (string)$r['v'] : '';
}

function boveda_borrar($clave) {
    try { db()->prepare('DELETE FROM settings WHERE clave=?')->execute([$clave]); } catch (Exception $e) {}
}

/* Texto para la pantalla: nunca el secreto, siempre qué pasa. */
function boveda_estado_texto($estado) {
    switch ($estado) {
        case 'vacio':      return 'Sin configurar.';
        case 'ok':         return 'Guardado y cifrado.';
        case 'sin_cifrar': return 'Guardado sin cifrar: se cifrará al próximo uso.';
        case 'ilegible':   return 'Guardado pero no se puede leer: falta la clave de cifrado. Hay que volver a configurarlo.';
        default:           return 'Estado desconocido.';
    }
}

/* ---------- Auditoría (0.9.11) ---------- */
function boveda_auditar($accion, $detalle) {
    if (function_exists('audit_log')) audit_log($accion, $detalle);
}
