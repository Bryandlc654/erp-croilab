<?php
/* Finanzas: facturas (borradores, emisión con número, cobros, rectificativas),
   documentos subidos, programaciones, contabilidad, resumen mensual, horas del
   equipo, proyectos y Ajustes › Facturación (emisores).
   Documentadas en docs/migracion/api/finanzas.md. Importes en céntimos. */

require_once __DIR__ . '/../../admin/lib/papelera.php';
require_once __DIR__ . '/../../admin/lib/notificaciones.php';

use Croilab\Http\Contenedor;
use Croilab\Http\Router;
use Croilab\Modulos\Finanzas\FinanzasController;
use Croilab\Modulos\Finanzas\Modulo;

return function (Router $r, Contenedor $c): void {
    /* El módulo queda en el contenedor: el portal del cliente podrá pedir
       $c->unico('finanzas', …)->hoja()->paraCliente($clienteId, $id). */
    $m = $c->unico('finanzas', fn(Contenedor $c) => new Modulo($c->pdo));
    $f = new FinanzasController($m);
    $p = '/v1/finanzas';

    /* Emisores (Ajustes › Facturación). La clave no es numérica: va en ?clave=. */
    $r->get("$p/emisores", [$f, 'emisores']);
    $r->get("$p/emisores/ajustes", [$f, 'emisoresAjustes']);
    $r->post("$p/emisores", [$f, 'guardarEmisores']);
    $r->delete("$p/emisores", [$f, 'borrarEmisor']);

    /* Facturas (rutas fijas antes que /{id}; no chocan porque {id} es numérico). */
    $r->get("$p/facturas", [$f, 'facturas']);
    $r->post("$p/facturas", [$f, 'crearFactura']);
    $r->get("$p/facturas/siguiente-numero", [$f, 'siguienteNumero']);
    $r->get("$p/facturas/desde-negocio/{id}", [$f, 'desdeNegocio']);
    $r->get("$p/facturas/{id}", [$f, 'factura']);
    $r->patch("$p/facturas/{id}", [$f, 'actualizarFactura']);
    $r->delete("$p/facturas/{id}", [$f, 'borrarFactura']);
    $r->post("$p/facturas/{id}/emitir", [$f, 'emitir']);
    $r->post("$p/facturas/{id}/estado", [$f, 'estado']);
    $r->post("$p/facturas/{id}/duplicar", [$f, 'duplicar']);
    $r->post("$p/facturas/{id}/rectificar", [$f, 'rectificar']);
    $r->post("$p/facturas/{id}/anular", [$f, 'anular']);
    $r->patch("$p/facturas/{id}/proyecto", [$f, 'proyectoFactura']);
    $r->get("$p/facturas/{id}/hoja", [$f, 'hoja']);
    $r->get("$p/facturas/{id}/pdf", [$f, 'pdf']);
    $r->post("$p/papelera/{id}/restaurar", [$f, 'restaurar']);

    /* Navegación por carpetas y por cliente. */
    $r->get("$p/explorador/hubs", [$f, 'hubs']);
    $r->get("$p/explorador/tipos", [$f, 'tipos']);
    $r->get("$p/explorador/meses", [$f, 'meses']);
    $r->get("$p/explorador/lista", [$f, 'lista']);
    $r->get("$p/por-cliente", [$f, 'porCliente']);
    $r->get("$p/por-cliente/{id}", [$f, 'cliente']);
    $r->get("$p/clientes-facturacion", [$f, 'clientesFacturacion']);
    $r->patch("$p/clientes-facturacion/{id}", [$f, 'guardarClienteFacturacion']);

    /* Documentos subidos (multipart; editar con archivo nuevo también es POST). */
    $r->get("$p/documentos", [$f, 'documentos']);
    $r->post("$p/documentos", [$f, 'crearDocumento']);
    $r->post("$p/documentos/{id}", [$f, 'actualizarDocumento']);
    $r->delete("$p/documentos/{id}", [$f, 'borrarDocumento']);

    /* Programaciones (recurrentes). */
    $r->get("$p/programaciones", [$f, 'programaciones']);
    $r->post("$p/programaciones", [$f, 'crearProgramacion']);
    $r->post("$p/programaciones/generar", [$f, 'generar']);
    $r->patch("$p/programaciones/{id}", [$f, 'actualizarProgramacion']);
    $r->delete("$p/programaciones/{id}", [$f, 'borrarProgramacion']);
    $r->post("$p/programaciones/{id}/pausar", [$f, 'pausarProgramacion']);
    $r->post("$p/programaciones/{id}/activar", [$f, 'activarProgramacion']);

    /* Contabilidad y resumen. */
    $r->get("$p/contabilidad/movimientos", [$f, 'movimientos']);
    $r->get("$p/contabilidad/analisis", [$f, 'analisis']);
    $r->get("$p/resumen", [$f, 'resumen']);

    /* Horas del equipo. */
    $r->get("$p/horas", [$f, 'horas']);
    $r->get("$p/horas/personas", [$f, 'personasHoras']);
    $r->post("$p/horas/extras", [$f, 'anadirExtra']);
    $r->post("$p/horas/volcar", [$f, 'volcar']);
    $r->delete("$p/horas/{id}", [$f, 'borrarHora']);
    $r->patch("$p/horas/personas/{id}/tarifa", [$f, 'tarifa']);

    /* Proyectos. */
    $r->get("$p/proyectos", [$f, 'proyectos']);
    $r->post("$p/proyectos", [$f, 'crearProyecto']);
    $r->get("$p/proyectos/buscar", [$f, 'buscarProyectos']);
    $r->get("$p/proyectos/{id}", [$f, 'proyecto']);
    $r->patch("$p/proyectos/{id}", [$f, 'actualizarProyecto']);
    $r->delete("$p/proyectos/{id}", [$f, 'borrarProyecto']);
    $r->post("$p/proyectos/{id}/movimientos", [$f, 'anadirMovimiento']);
    $r->delete("$p/proyectos/{id}/movimientos/{acc}", [$f, 'desvincularMovimiento']);
    $r->get("$p/proyectos/{id}/facturas-vinculables", [$f, 'vinculables']);
    $r->post("$p/proyectos/{id}/facturas/{inv}", [$f, 'vincularFactura']);
    $r->delete("$p/proyectos/{id}/facturas/{inv}", [$f, 'desvincularFactura']);
};
