<?php
/* CRM: contactos y su ficha, negocios (embudo), listas, vistas, etiquetas,
   fases, dashboard, seguimientos y resumen diario, importación/exportación
   CSV y conversión de un lead en cliente.
   Documentadas en docs/migracion/api/crm.md. */

require_once __DIR__ . '/../../admin/lib/marca.php';

use Croilab\Correo\Fabrica;
use Croilab\Http\Contenedor;
use Croilab\Http\Request;
use Croilab\Http\Router;
use Croilab\Modulos\Clientes\ClientesServicio;
use Croilab\Modulos\Clientes\FichaRepositorio;
use Croilab\Modulos\Crm\ConfigServicio;
use Croilab\Modulos\Crm\ContactosController;
use Croilab\Modulos\Crm\ContactosRepositorio;
use Croilab\Modulos\Crm\ContactosServicio;
use Croilab\Modulos\Crm\ConversionServicio;
use Croilab\Modulos\Crm\CrmController;
use Croilab\Modulos\Crm\DashboardServicio;
use Croilab\Modulos\Crm\FichaServicio;
use Croilab\Modulos\Crm\Historial;
use Croilab\Modulos\Crm\ImportarServicio;
use Croilab\Modulos\Crm\Importes;
use Croilab\Modulos\Crm\ListasServicio;
use Croilab\Modulos\Crm\NegociosServicio;
use Croilab\Modulos\Crm\SeguimientosServicio;
use Croilab\Seguridad\Acceso;

return function (Router $r, Contenedor $c): void {
    $pdo = $c->pdo;
    $historial = new Historial($pdo);
    $repo = new ContactosRepositorio($pdo);
    $contactos = new ContactosServicio($pdo, $repo, $c->equipo(), $historial);
    /* El alta de clientes es la del módulo Clientes (misma validación y aviso). */
    $clientes = $c->unico('clientes.servicio', fn(Contenedor $c) => new ClientesServicio($c->pdo, $c->clientes(), new FichaRepositorio($c->pdo), $c->equipo()));
    $conversion = new ConversionServicio($pdo, $contactos, $clientes, $historial);
    $ficha = new FichaServicio($pdo, $contactos, $repo, $c->equipo(), $historial, dirname(__DIR__, 2) . '/uploads/crm');
    $seguimientos = new SeguimientosServicio($pdo, $c->equipo(), $historial, fn() => Fabrica::desdeConfig());

    $ct = new ContactosController($contactos, $ficha, $conversion, new ImportarServicio($pdo, $c->equipo(), $historial));
    $crm = new CrmController(
        new NegociosServicio($pdo, $contactos, $historial),
        new ConfigServicio($pdo, $historial),
        new ListasServicio($pdo, $repo, $contactos),
        new DashboardServicio($pdo, $c->equipo()),
        $seguimientos,
        $contactos,
        $conversion
    );

    /* Sin `ver.importes` no sale ninguna cifra de dinero (Importes::filtrar). */
    $f = fn(array $accion) => fn(Request $req) => Importes::filtrar($accion($req), Acceso::actual());

    /* Catálogos y configuración */
    $r->get('/v1/crm/catalogos', $f([$crm, 'catalogos']));
    $r->post('/v1/crm/fases', $f([$crm, 'crearFase']));
    $r->post('/v1/crm/fases/orden', $f([$crm, 'ordenFases']));
    $r->patch('/v1/crm/fases/{id}', $f([$crm, 'actualizarFase']));
    $r->delete('/v1/crm/fases/{id}', $f([$crm, 'borrarFase']));
    $r->post('/v1/crm/etiquetas', $f([$crm, 'crearEtiqueta']));
    $r->patch('/v1/crm/etiquetas/{id}', $f([$crm, 'actualizarEtiqueta']));
    $r->delete('/v1/crm/etiquetas/{id}', $f([$crm, 'borrarEtiqueta']));
    $r->patch('/v1/crm/sectores', $f([$crm, 'sectores']));
    $r->get('/v1/crm/vistas', $f([$crm, 'vistas']));
    $r->post('/v1/crm/vistas', $f([$crm, 'crearVista']));
    $r->delete('/v1/crm/vistas/{id}', $f([$crm, 'borrarVista']));

    /* Contactos */
    $r->get('/v1/crm/contactos', $f([$ct, 'listar']));
    $r->post('/v1/crm/contactos', $f([$ct, 'crear']));
    $r->post('/v1/crm/contactos/lote', $f([$ct, 'lote']));
    $r->get('/v1/crm/contactos/exportar', $f([$ct, 'exportar']));
    $r->get('/v1/crm/contactos/plantilla', $f([$ct, 'plantilla']));
    $r->post('/v1/crm/contactos/importar', $f([$ct, 'importar']));
    $r->get('/v1/crm/contactos/{id}', $f([$ct, 'ver']));
    $r->patch('/v1/crm/contactos/{id}', $f([$ct, 'actualizar']));
    $r->delete('/v1/crm/contactos/{id}', $f([$ct, 'borrar']));
    $r->patch('/v1/crm/contactos/{id}/facturacion', $f([$ct, 'facturacion']));
    $r->post('/v1/crm/contactos/{id}/etiquetas/{tag}', $f([$ct, 'ponerEtiqueta']));
    $r->delete('/v1/crm/contactos/{id}/etiquetas/{tag}', $f([$ct, 'quitarEtiqueta']));
    $r->post('/v1/crm/contactos/{id}/comentarios', $f([$ct, 'comentar']));
    $r->post('/v1/crm/contactos/{id}/actividad', $f([$ct, 'actividad']));
    $r->post('/v1/crm/contactos/{id}/propuestas', $f([$ct, 'crearPropuesta']));
    $r->post('/v1/crm/contactos/{id}/adjuntos', $f([$ct, 'subirAdjunto']));
    $r->post('/v1/crm/contactos/{id}/convertir', $f([$ct, 'convertir']));
    $r->delete('/v1/crm/comentarios/{id}', $f([$ct, 'borrarComentario']));
    $r->patch('/v1/crm/propuestas/{id}', $f([$ct, 'actualizarPropuesta']));
    $r->delete('/v1/crm/propuestas/{id}', $f([$ct, 'borrarPropuesta']));
    $r->delete('/v1/crm/adjuntos/{id}', $f([$ct, 'borrarAdjunto']));
    /* «Deshacer» del borrado de un contacto o un negocio. */
    $r->post('/v1/crm/papelera/{id}/restaurar', $f([$ct, 'restaurar']));

    /* Negocios */
    $r->get('/v1/crm/negocios', $f([$crm, 'negocios']));
    $r->post('/v1/crm/negocios', $f([$crm, 'crearNegocio']));
    $r->get('/v1/crm/negocios/{id}', $f([$crm, 'negocio']));
    $r->patch('/v1/crm/negocios/{id}', $f([$crm, 'actualizarNegocio']));
    $r->delete('/v1/crm/negocios/{id}', $f([$crm, 'borrarNegocio']));
    $r->post('/v1/crm/negocios/{id}/mover', $f([$crm, 'moverNegocio']));
    $r->post('/v1/crm/negocios/{id}/perder', $f([$crm, 'perderNegocio']));
    $r->post('/v1/crm/negocios/{id}/convertir', $f([$crm, 'convertirNegocio']));
    $r->post('/v1/crm/negocios/{id}/etiquetas/{tag}', $f([$crm, 'etiquetaNegocio']));
    $r->delete('/v1/crm/negocios/{id}/etiquetas/{tag}', $f([$crm, 'quitarEtiquetaNegocio']));

    /* Listas */
    $r->get('/v1/crm/listas', $f([$crm, 'listas']));
    $r->post('/v1/crm/listas', $f([$crm, 'crearLista']));
    $r->post('/v1/crm/listas/orden', $f([$crm, 'ordenListas']));
    $r->get('/v1/crm/listas/{id}', $f([$crm, 'lista']));
    $r->patch('/v1/crm/listas/{id}', $f([$crm, 'actualizarLista']));
    $r->delete('/v1/crm/listas/{id}', $f([$crm, 'borrarLista']));
    $r->post('/v1/crm/listas/{id}/congelar', $f([$crm, 'congelarLista']));
    $r->get('/v1/crm/listas/{id}/exportar', $f([$crm, 'exportarLista']));
    $r->post('/v1/crm/listas/{id}/miembros/{contacto}', $f([$crm, 'ponerMiembro']));
    $r->delete('/v1/crm/listas/{id}/miembros/{contacto}', $f([$crm, 'quitarMiembro']));

    /* Dashboard y seguimientos */
    $r->get('/v1/crm/dashboard', $f([$crm, 'dashboard']));
    $r->get('/v1/crm/seguimientos', $f([$crm, 'seguimientos']));
    $r->post('/v1/crm/seguimientos', $f([$crm, 'crearSeguimiento']));
    $r->post('/v1/crm/seguimientos/generar', $f([$crm, 'generarSeguimientos']));
    $r->post('/v1/crm/seguimientos/{id}/hecho', $f([$crm, 'seguimientoHecho']));
    $r->post('/v1/crm/seguimientos/{id}/posponer', $f([$crm, 'posponerSeguimiento']));
    $r->post('/v1/crm/seguimientos/{id}/omitir', $f([$crm, 'omitirSeguimiento']));
    $r->get('/v1/crm/resumen-diario', $f([$crm, 'resumen']));
    $r->post('/v1/crm/resumen-diario/ejecutar', $f([$crm, 'ejecutarResumen']));
};
