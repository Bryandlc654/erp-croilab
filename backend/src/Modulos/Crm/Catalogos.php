<?php
namespace Croilab\Modulos\Crm;

use PDO;

/* Listas fijas del CRM (las de admin/lib/crm_lib.php) y lectura de las fases
   del embudo, los sectores y las etiquetas. Nomenclatura obligatoria del
   legado: «Embudo de venta», nunca «ciclo de vida». */
final class Catalogos
{
    public const ORIGENES = ['Outreach', 'Referido', 'Google Ads', 'Meta Ads', 'Web/formulario', 'Evento', 'LinkedIn', 'Otro'];
    public const SERVICIOS = ['Web', 'SEO', 'Meta Ads', 'Automatización', 'Mantenimiento', 'Otro'];
    public const SECTORES = ['Restauración', 'Ecommerce', 'Servicios', 'Salud', 'Inmobiliaria', 'Formación', 'Otro'];

    /* clave => [etiqueta, ¿es una interacción? (pone «último contacto» a hoy)] */
    public const TIPOS_COMENTARIO = [
        'nota' => ['Nota', false],
        'llamada' => ['Llamada', true],
        'whatsapp' => ['WhatsApp', true],
        'email' => ['Email', true],
        'reunion' => ['Reunión', true],
    ];

    /* clave => [etiqueta, meses hasta reactivar (null = nunca)] */
    public const MOTIVOS_PERDIDA = [
        'precio' => ['Precio', 3],
        'timing' => ['Timing / no es el momento', 3],
        'competencia' => ['Se ha ido con la competencia', 6],
        'sin_respuesta' => ['Sin respuesta', 6],
        'no_cualificado' => ['No cualificado', null],
        'otro' => ['Otro', 6],
    ];

    public const ESTADOS_PROPUESTA = ['enviada' => 'Enviada', 'vista' => 'Vista', 'aceptada' => 'Aceptada', 'rechazada' => 'Rechazada'];

    /* Canales de seguimiento: slug => [título del resumen, tipo de actividad, color]. */
    public const CANALES = [
        'llamar' => ['LLAMAR HOY', 'llamada', '#3b82f6'],
        'whatsapp' => ['ESCRIBIR WHATSAPP', 'whatsapp', '#1a9d5b'],
        'email' => ['ENVIAR EMAIL', 'email', '#e0872a'],
        'reunion' => ['REUNIONES', 'reunion', '#d1a000'],
    ];

    public const ESTADOS_REUNION = ['agendada' => 'Agendada', 'realizada' => 'Realizada', 'no_show' => 'No se presentó', 'cancelada' => 'Cancelada'];

    /* Fases con lógica cableada: no se pueden borrar (alta de contactos y
       negocios, regla B de seguimientos, métricas de ganado/perdido). */
    public const FASES_ESTRUCTURALES = ['lead_nuevo', 'propuesta', 'ganado', 'perdido', 'pausa'];
    public const TIPOS_FASE = ['abierta', 'ganada', 'perdida', 'pausa'];

    /** Fases del embudo en orden: slug => fila. */
    public static function fases(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query('SELECT id, nombre, slug, orden, probabilidad, tipo, color FROM pipeline_stages ORDER BY orden, id') as $r) {
            $out[(string)$r['slug']] = [
                'id' => (int)$r['id'], 'nombre' => (string)$r['nombre'], 'slug' => (string)$r['slug'], 'orden' => (int)$r['orden'],
                'probabilidad' => (int)$r['probabilidad'], 'tipo' => (string)$r['tipo'], 'color' => (string)($r['color'] ?: '#94a3b8'),
                'estructural' => in_array($r['slug'], self::FASES_ESTRUCTURALES, true),
            ];
        }
        return $out;
    }

    /** Slugs de las fases de un tipo. */
    public static function slugsDeTipo(array $fases, string ...$tipos): array
    {
        return array_keys(array_filter($fases, fn($f) => in_array($f['tipo'], $tipos, true)));
    }

    public static function sectores(PDO $pdo): array
    {
        $st = $pdo->prepare('SELECT valor FROM settings WHERE clave = ?');
        $st->execute(['crm_sectors']);
        $a = json_decode((string)$st->fetchColumn(), true);
        if (is_array($a)) {
            $a = array_values(array_filter(array_map(fn($s) => is_string($s) ? trim($s) : '', $a), fn($s) => $s !== ''));
            if ($a) return $a;
        }
        return self::SECTORES;
    }

    public static function etiquetas(PDO $pdo): array
    {
        return array_map(
            fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre'], 'color' => (string)($r['color'] ?: '#5b8def')],
            $pdo->query('SELECT id, nombre, color FROM crm_tags ORDER BY nombre')->fetchAll()
        );
    }

    /** «Demo agendada» → «demo_agendada» (ASCII, para slugs, usuarios y nombres de fichero). */
    public static function ascii(string $s, string $sep = '_'): string
    {
        $s = strtr(mb_strtolower($s), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'â' => 'a', 'ê' => 'e', 'ô' => 'o']);
        return trim((string)preg_replace('/[^a-z0-9]+/', $sep, $s), $sep);
    }

    /** Todo lo que el front necesita para pintar selects y píldoras. */
    public static function todo(PDO $pdo): array
    {
        $par = fn(array $m) => array_map(fn($k, $v) => ['value' => $k, 'label' => is_array($v) ? $v[0] : $v], array_keys($m), $m);
        return [
            'fases' => array_values(self::fases($pdo)),
            'origenes' => self::ORIGENES,
            'servicios' => self::SERVICIOS,
            'sectores' => self::sectores($pdo),
            'etiquetas' => self::etiquetas($pdo),
            'tipos_comentario' => $par(self::TIPOS_COMENTARIO),
            'motivos_perdida' => array_map(fn($k, $v) => ['value' => $k, 'label' => $v[0], 'meses' => $v[1]], array_keys(self::MOTIVOS_PERDIDA), self::MOTIVOS_PERDIDA),
            'estados_propuesta' => $par(self::ESTADOS_PROPUESTA),
            'canales' => array_map(fn($k, $v) => ['value' => $k, 'label' => $v[0], 'color' => $v[2]], array_keys(self::CANALES), self::CANALES),
            'estados_reunion' => $par(self::ESTADOS_REUNION),
        ];
    }
}
