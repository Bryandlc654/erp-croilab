<?php
namespace Croilab\Modulos\Finanzas;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Quién factura (los autónomos «emisores») y con qué datos fiscales.
   Mismas claves de settings que el ERP antiguo (lib/fin_prog.php):
   emisores_lista = [{k, nombre}], emisor_{k}_name|nif|dir|email|phone|banco|iban|
   iva|irpf|venc|serie y emisor_por_defecto.
   Reglas que no se pueden romper:
   · La CLAVE no cambia nunca (está grabada en facturas y apuntes).
   · El prefijo de serie es del emisor y no se repite entre emisores.
   · Un emisor con histórico no se borra. */
final class EmisoresServicio
{
    public const CAMPOS_FISCALES = ['name' => 200, 'nif' => 40, 'dir' => 300, 'email' => 160, 'phone' => 40, 'banco' => 80, 'iban' => 40, 'venc' => 60];

    public function __construct(private readonly PDO $pdo, private readonly Ajustes $aj) {}

    /** clave => nombre visible, en el orden configurado (como fin_emisores()). */
    public function lista(): array
    {
        $arr = json_decode($this->aj->get('emisores_lista'), true);
        if (!is_array($arr) || !$arr) $arr = [['k' => 'victor', 'nombre' => 'Víctor'], ['k' => 'gavi', 'nombre' => 'Gabi']];
        $out = [];
        foreach ($arr as $r) {
            if (!is_array($r)) continue;
            $k = trim((string)($r['k'] ?? ''));
            if ($k === '' || isset($out[$k])) continue;
            $n = trim((string)($r['nombre'] ?? ''));
            $out[$k] = $n !== '' ? $n : $k;
        }
        return $out ?: ['victor' => 'Víctor', 'gavi' => 'Gabi'];
    }

    public function existe(string $k): bool
    {
        return isset($this->lista()[$k]);
    }

    /** Normaliza un emisor que llega de fuera: si no existe, el por defecto o el primero. */
    public function ok(?string $k): string
    {
        $l = $this->lista();
        if ($k !== null && isset($l[$k])) return $k;
        $def = $this->aj->get('emisor_por_defecto');
        return isset($l[$def]) ? $def : (string)array_key_first($l);
    }

    public function porDefecto(): string
    {
        return $this->ok(null);
    }

    public function nombre(string $k): string
    {
        return $this->lista()[$k] ?? $k;
    }

    /** Prefijo de serie (la «V» de V-2026-001): guardado, no deducido. */
    public function prefijo(string $k): string
    {
        $v = trim($this->aj->get("emisor_{$k}_serie"));
        if ($v !== '') return $v;
        return self::inicial($this->nombre($k));
    }

    /** Datos fiscales de un emisor (lo que se congela en la factura al emitir). */
    public function datos(string $k): array
    {
        $g = fn(string $c, string $def = '') => ($v = $this->aj->get("emisor_{$k}_$c")) !== '' ? $v : $def;
        return [
            'key' => $k,
            'name' => $g('name', $this->nombre($k)),
            'nif' => $g('nif'), 'dir' => $g('dir'), 'email' => $g('email'), 'phone' => $g('phone'),
            'iban' => $g('iban'), 'banco' => $g('banco'),
            'iva' => $g('iva', '21'), 'irpf' => $g('irpf', '0'), 'venc' => $g('venc', 'Contado'),
        ];
    }

    /** Facturas, programaciones, apuntes y documentos a su nombre. */
    public function uso(string $k): array
    {
        $u = [];
        foreach (['facturas' => 'SELECT COUNT(*) FROM invoices WHERE emisor = ?', 'programaciones' => 'SELECT COUNT(*) FROM invoice_schedules WHERE emisor = ?',
                  'apuntes' => 'SELECT COUNT(*) FROM accounting WHERE ambito = ?', 'documentos' => 'SELECT COUNT(*) FROM invoice_uploads WHERE emisor = ?'] as $c => $sql) {
            $st = $this->pdo->prepare($sql);
            $st->execute([$k]);
            $u[$c] = (int)$st->fetchColumn();
        }
        $u['total'] = array_sum($u);
        return $u;
    }

    /** Para pantallas: emisores activos + dados de baja que aún tienen histórico. */
    public function listar(Acceso $acc): array
    {
        $acc->exigir('ver.finanzas');
        $items = [];
        foreach ($this->lista() as $k => $n) {
            $d = $this->datos($k);
            $items[] = ['clave' => $k, 'nombre' => $n, 'prefijo' => $this->prefijo($k), 'baja' => false,
                        'defaults' => ['iva' => $d['iva'], 'irpf' => $d['irpf'], 'venc' => $d['venc']],
                        'serie_recordada' => $this->aj->get("serie_$k")];
        }
        foreach ($this->clavesConHistorico() as $k) {
            if ($this->existe($k)) continue;
            $items[] = ['clave' => $k, 'nombre' => $k . ' (baja)', 'prefijo' => '', 'baja' => true,
                        'defaults' => ['iva' => '21', 'irpf' => '0', 'venc' => 'Contado'], 'serie_recordada' => ''];
        }
        return ['items' => $items, 'por_defecto' => $this->porDefecto()];
    }

    /** Ajustes › Facturación: todo, con datos fiscales y uso. */
    public function ajustes(Acceso $acc): array
    {
        $acc->exigir('finanzas.emisores');
        $items = [];
        foreach ($this->lista() as $k => $n) {
            $d = $this->datos($k);
            $fiscal = [];
            foreach (array_keys(self::CAMPOS_FISCALES) as $c) $fiscal[$c] = $this->aj->get("emisor_{$k}_$c");
            $items[] = ['clave' => $k, 'nombre' => $n, 'prefijo' => $this->prefijo($k), 'fiscal' => $fiscal,
                        'iva' => $d['iva'], 'irpf' => $d['irpf'], 'uso' => $this->uso($k)];
        }
        return ['items' => $items, 'por_defecto' => $this->porDefecto(), 'anio' => (int)date('Y')];
    }

    /**
     * Guarda la lista entera (como el formulario antiguo): renombres, altas,
     * datos fiscales, prefijos y emisor por defecto. Quitar a alguien va por
     * borrar(), que comprueba su histórico.
     * $d = {emisores: [{clave?, nombre, prefijo?, fiscal:{…}, iva, irpf}], por_defecto?}
     */
    public function guardar(Acceso $acc, array $d): array
    {
        Validar::escribe($acc, 'finanzas.emisores');
        $filas = $d['emisores'] ?? null;
        if (!is_array($filas) || !$filas || !array_is_list($filas)) throw HttpError::validacion('Tiene que quedar al menos alguien que facture.', 'emisores');
        if (count($filas) > 20) throw HttpError::validacion('Demasiados emisores.', 'emisores');

        $previas = $this->lista();
        $usadas = array_merge(array_keys($previas), $this->clavesConHistorico());
        $nuevaLista = [];
        $guardar = [];
        $prefijos = [];
        foreach ($filas as $i => $f) {
            if (!is_array($f)) throw HttpError::validacion('Datos no válidos.', "emisores.$i");
            $nombre = Validar::texto($f, 'nombre', 60);
            if ($nombre === '') throw HttpError::validacion('Cada emisor necesita un nombre.', "emisores.$i.nombre");
            $k = trim((string)($f['clave'] ?? ''));
            $nuevo = $k === '' || !isset($previas[$k]);
            if ($nuevo) {
                $k = self::slug($nombre, $usadas);
                $usadas[] = $k;
            }
            if (isset($nuevaLista[$k])) throw HttpError::validacion('Hay un emisor repetido.', "emisores.$i");
            $nuevaLista[$k] = $nombre;

            $pref = strtoupper(Validar::texto($f, 'prefijo', 10));
            if ($pref === '') $pref = $nuevo ? self::inicial($nombre) : $this->prefijo($k);
            if (!preg_match('/^[A-Z0-9][A-Z0-9\-\/]*$/', $pref)) throw HttpError::validacion('El prefijo solo admite letras, números, «-» y «/».', "emisores.$i.prefijo");
            if (isset($prefijos[$pref])) throw HttpError::validacion("El prefijo «{$pref}» ya lo usa otro emisor: cada uno necesita el suyo para no mezclar numeraciones.", "emisores.$i.prefijo");
            $prefijos[$pref] = $k;

            $fiscal = is_array($f['fiscal'] ?? null) ? $f['fiscal'] : [];
            $vals = ['serie' => $pref];
            foreach (self::CAMPOS_FISCALES as $c => $max) $vals[$c] = Validar::texto($fiscal, $c, $max);
            if ($vals['email'] !== '' && !filter_var($vals['email'], FILTER_VALIDATE_EMAIL)) throw HttpError::validacion('Ese correo no es válido.', "emisores.$i.email");
            $vals['iban'] = strtoupper(preg_replace('/\s+/', '', $vals['iban']));
            if ($vals['iban'] !== '' && !self::ibanValido($vals['iban'])) throw HttpError::validacion('El IBAN no es válido (revisa los dígitos).', "emisores.$i.iban");
            $vals['iban'] = trim(chunk_split($vals['iban'], 4, ' '));
            foreach (['iva', 'irpf'] as $p) {
                $vals[$p] = Dinero::exigir($f[$p] ?? '0', "emisores.$i.$p", $p === 'iva' ? 'el IVA' : 'el IRPF', 0, 10000);
                $vals[$p] = Dinero::pct($vals[$p]);
            }
            $guardar[$k] = $vals;
        }
        $def = (string)($d['por_defecto'] ?? '');
        if (!isset($nuevaLista[$def])) $def = (string)array_key_first($nuevaLista);

        /* Quien sale de la lista sin pasar por borrar() solo puede hacerlo si no tiene histórico. */
        foreach (array_keys($previas) as $k) {
            if (!isset($nuevaLista[$k]) && $this->uso($k)['total'] > 0) {
                throw new HttpError(409, 'No se puede quitar a ' . $previas[$k] . ': tiene histórico.', 'conflicto');
            }
        }

        $json = [];
        foreach ($nuevaLista as $k => $n) $json[] = ['k' => $k, 'nombre' => $n];
        $this->aj->set('emisores_lista', json_encode($json, JSON_UNESCAPED_UNICODE));
        foreach ($guardar as $k => $vals) foreach ($vals as $c => $v) $this->aj->set("emisor_{$k}_$c", (string)$v);
        $this->aj->set('emisor_por_defecto', $def);
        return $this->ajustes($acc);
    }

    /** Baja de un emisor: 409 si tiene histórico o si es el último. */
    public function borrar(Acceso $acc, string $k): array
    {
        Validar::escribe($acc, 'finanzas.emisores');
        $l = $this->lista();
        if (!isset($l[$k])) throw HttpError::noEncontrado('Ese emisor ya no existe.');
        if (count($l) <= 1) throw new HttpError(409, 'Tiene que quedar al menos alguien que facture.', 'conflicto');
        $u = $this->uso($k);
        if ($u['total'] > 0) {
            $partes = [];
            foreach (['facturas' => ['factura', 'facturas'], 'programaciones' => ['programación', 'programaciones'], 'apuntes' => ['apunte de contabilidad', 'apuntes de contabilidad'], 'documentos' => ['documento', 'documentos']] as $c => [$s, $p]) {
                if ($u[$c]) $partes[] = $u[$c] . ' ' . ($u[$c] === 1 ? $s : $p);
            }
            throw new HttpError(409, 'No se puede quitar a ' . $l[$k] . ': tiene ' . implode(', ', $partes) . '. Su histórico es contabilidad real y debe poder consultarse.', 'conflicto', ['uso' => $u]);
        }
        unset($l[$k]);
        $json = [];
        foreach ($l as $kk => $n) $json[] = ['k' => $kk, 'nombre' => $n];
        $this->aj->set('emisores_lista', json_encode($json, JSON_UNESCAPED_UNICODE));
        if ($this->aj->get('emisor_por_defecto') === $k) $this->aj->set('emisor_por_defecto', (string)array_key_first($l));
        return $this->ajustes($acc);
    }

    /** @return string[] claves que aparecen en el histórico */
    private function clavesConHistorico(): array
    {
        $st = $this->pdo->query("SELECT DISTINCT emisor FROM invoices UNION SELECT DISTINCT emisor FROM invoice_schedules
                                 UNION SELECT DISTINCT emisor FROM invoice_uploads UNION SELECT DISTINCT ambito FROM accounting WHERE ambito <> 'empresa'");
        return array_values(array_filter(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN))));
    }

    public static function inicial(string $nombre): string
    {
        $t = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) : $nombre;
        $t = preg_replace('/[^a-zA-Z0-9]/', '', $t === false ? $nombre : $t);
        return $t !== '' ? strtoupper(substr($t, 0, 1)) : 'F';
    }

    /** Clave nueva a partir del nombre (≤15, ASCII, nunca repite una usada). */
    public static function slug(string $nombre, array $usadas): string
    {
        $t = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) : $nombre;
        $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $t === false ? $nombre : $t));
        if ($s === '') $s = 'emisor';
        $s = substr($s, 0, 15);
        $out = $s;
        for ($i = 2; in_array($out, $usadas, true); $i++) $out = substr($s, 0, 15 - strlen((string)$i)) . $i;
        return $out;
    }

    /** IBAN: formato y dígitos de control (mod 97). */
    public static function ibanValido(string $iban): bool
    {
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) return false;
        $r = substr($iban, 4) . substr($iban, 0, 4);
        $num = '';
        foreach (str_split($r) as $ch) $num .= ctype_alpha($ch) ? (string)(ord($ch) - 55) : $ch;
        $resto = 0;
        foreach (str_split($num, 7) as $trozo) $resto = (int)(($resto . $trozo)) % 97;
        return $resto === 1;
    }
}
