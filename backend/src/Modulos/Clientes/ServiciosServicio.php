<?php
namespace Croilab\Modulos\Clientes;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;

/* Catálogo de servicios (servicios.php) sobre admin/lib/servicios_cat.php,
   que sigue siendo quien lo guarda en settings.servicios_catalogo (también lo
   leen Vídeos y el portal). Renombrar o quitar un servicio se arrastra a la
   ficha de los clientes que lo tienen. */
class ServiciosServicio
{
    public function listar(Acceso $acc): array
    {
        if (!$acc->puede('ver.ajustes') && !$acc->puede('ver.clientes')) throw HttpError::permiso();
        $uso = svc_uso();
        return [
            'servicios' => array_map(fn($s) => [
                'nombre' => $s['nombre'], 'desc' => $s['desc'], 'video' => svc_video_ok($s['video']),
                'uso' => svc_uso_de($uso, $s['nombre']),
            ], svc_catalogo()),
            'abiertos' => (int)$uso['abiertos'],
            'total' => (int)$uso['total'],
        ];
    }

    /**
     * Guarda el catálogo entero: [{nombre, desc, orig}]. `orig` es el nombre que
     * tenía al cargar la pantalla ('' en las filas nuevas).
     * @return array{renombrados:int, clientes:int}
     */
    public function guardar(Acceso $acc, mixed $filas): array
    {
        $acc->exigir('general.editar', 'servicios.editar');
        if (!is_array($filas) || !array_is_list($filas)) throw HttpError::validacion('Formato no válido.', 'servicios');
        if (count($filas) > 100) throw HttpError::validacion('Demasiados servicios.', 'servicios');

        $previo = [];
        foreach (svc_catalogo() as $s) $previo[$s['nombre']] = $s;

        $nuevas = [];
        $vistos = [];
        $renombres = [];
        foreach ($filas as $f) {
            if (!is_array($f)) throw HttpError::validacion('Formato no válido.', 'servicios');
            $nombre = ContenidoPortal::texto($f['nombre'] ?? '', 80, 'servicios');
            if ($nombre === '') continue;
            $clave = mb_strtolower($nombre);
            if (isset($vistos[$clave])) throw HttpError::validacion("«{$nombre}» está repetido en el catálogo.", 'servicios');
            $vistos[$clave] = true;
            $orig = ContenidoPortal::texto($f['orig'] ?? '', 80, 'servicios');
            $fila = ['nombre' => $nombre, 'desc' => ContenidoPortal::texto($f['desc'] ?? '', 200, 'servicios')];
            /* Al renombrar, el vídeo se busca por el nombre de antes: svc_guardar lo
               buscaba por el nuevo y el servicio renombrado perdía su vídeo. */
            $ref = $orig !== '' && isset($previo[$orig]) ? $previo[$orig] : ($previo[$nombre] ?? null);
            if ($ref) $fila['video'] = $ref['video'];
            if ($orig !== '' && $orig !== $nombre && isset($previo[$orig])) $renombres[$orig] = $nombre;
            $nuevas[] = $fila;
        }
        if (!$nuevas) throw HttpError::validacion('El catálogo necesita al menos un servicio.', 'servicios');

        $quedan = array_fill_keys(array_column($nuevas, 'nombre'), true);
        $origenes = array_fill_keys(array_keys($renombres), true);
        $clientes = 0;
        /* En dos pasos, por si se intercambian dos nombres (A→B y B→A). */
        $tmp = [];
        foreach (array_keys($renombres) as $i => $de) {
            $tmp[$de] = "\u{1}renombrando-$i";
            $clientes += svc_renombrar_en_clientes($de, $tmp[$de]);
        }
        foreach ($renombres as $de => $a) svc_renombrar_en_clientes($tmp[$de], $a);
        foreach ($previo as $nombre => $_) {
            /* Lo que ya no está (ni con su nombre ni renombrado) sale de la ficha de los clientes. */
            if (!isset($quedan[$nombre]) && !isset($origenes[$nombre])) $clientes += svc_renombrar_en_clientes($nombre, '');
        }
        svc_guardar($nuevas);
        if (function_exists('audit_log')) audit_log('ajuste.servicios_catalogo', count($nuevas) . ' servicios, ' . count($renombres) . ' renombrados');
        return ['renombrados' => count($renombres), 'clientes' => $clientes];
    }
}
