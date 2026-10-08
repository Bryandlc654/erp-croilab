<?php
namespace Croilab\Modulos\Finanzas;

use Croilab\Http\HttpError;
use Croilab\Http\Request;
use Croilab\Http\Respuesta;
use Croilab\Seguridad\Acceso;

/* HTTP ⇄ servicios de Finanzas. Sin reglas de negocio aquí. Importes en
   céntimos enteros. Las subidas de documentos llegan en multipart
   ($_POST + $_FILES['archivo']). Documentado en docs/migracion/api/finanzas.md. */
final class FinanzasController
{
    public function __construct(private readonly Modulo $m) {}

    private static function acc(): Acceso
    {
        return Acceso::actual();
    }

    /* ---------- Emisores ---------- */

    public function emisores(Request $req): array { return $this->m->emisores()->listar(self::acc()); }
    public function emisoresAjustes(Request $req): array { return $this->m->emisores()->ajustes(self::acc()); }
    public function guardarEmisores(Request $req): array { return $this->m->emisores()->guardar(self::acc(), $req->json()); }
    public function borrarEmisor(Request $req): array { return $this->m->emisores()->borrar(self::acc(), $req->texto('clave')); }

    /* ---------- Facturas ---------- */

    public function facturas(Request $req): array
    {
        [$limit, $offset] = $req->paginacion(200, 1000);
        $f = [];
        foreach (['emisor', 'estado', 'mes', 'desde', 'hasta', 'q', 'tipo', 'personal'] as $k) $f[$k] = $req->texto($k);
        $f['client_id'] = $req->entero('client_id');
        $f['project_id'] = $req->entero('project_id');
        return $this->m->facturas()->listar(self::acc(), $f, $limit, $offset);
    }

    public function factura(Request $req): array { return ['factura' => $this->m->facturas()->detalle(self::acc(), $req->param('id'))]; }
    public function crearFactura(Request $req): Respuesta { return new Respuesta(['factura' => $this->m->facturas()->crear(self::acc(), $req->json())], 201); }
    public function actualizarFactura(Request $req): array { return ['factura' => $this->m->facturas()->actualizar(self::acc(), $req->param('id'), $req->json())]; }
    public function borrarFactura(Request $req): array { return ['papelera_id' => $this->m->facturas()->borrar(self::acc(), $req->param('id'))]; }
    public function emitir(Request $req): array { return ['factura' => $this->m->facturas()->emitir(self::acc(), $req->param('id'))]; }
    public function estado(Request $req): array { return ['factura' => $this->m->facturas()->cambiarEstado(self::acc(), $req->param('id'), $req->json())]; }
    public function duplicar(Request $req): Respuesta { return new Respuesta(['factura' => $this->m->facturas()->duplicar(self::acc(), $req->param('id'))], 201); }
    public function rectificar(Request $req): Respuesta { return new Respuesta(['factura' => $this->m->facturas()->rectificar(self::acc(), $req->param('id'), $req->json())], 201); }
    public function anular(Request $req): array { return ['factura' => $this->m->facturas()->anular(self::acc(), $req->param('id'), $req->json())]; }
    public function proyectoFactura(Request $req): array { return ['factura' => $this->m->facturas()->asignarProyecto(self::acc(), $req->param('id'), $req->json())]; }
    public function restaurar(Request $req): array
    {
        $acc = self::acc();
        $t = pap_elemento($req->param('id'));
        if (!$t || !in_array($t['tipo'], ['factura', 'documento'], true)) throw HttpError::noEncontrado('Eso ya no está en la papelera.');
        Validar::escribe($acc, $t['tipo'] === 'factura' ? 'finanzas.emitir' : 'conta.editar');
        /* Quien borró puede deshacerlo; lo borrado por otra persona exige el permiso de la papelera. */
        if ((int)$t['admin_id'] !== $acc->adminId) $acc->exigir('papelera.restaurar');
        $r = pap_restaurar($req->param('id'));
        if (empty($r['ok'])) throw new HttpError(409, $r['msg'] ?? 'No se ha podido restaurar.', 'conflicto');
        return ['id' => (int)($r['id'] ?? 0)];
    }

    public function hoja(Request $req): array { return ['hoja' => $this->m->hoja()->paraEquipo(self::acc(), $req->param('id'))]; }

    public function pdf(Request $req): array
    {
        $h = $this->m->hoja()->paraEquipo(self::acc(), $req->param('id'));
        $nombre = ($h['tipo'] === 'rectificativa' ? 'Rectificativa ' : 'Factura ') . ($h['numero'] ?? 'borrador') . '.pdf';
        return ['nombre' => preg_replace('/[^\w .\-]/u', '_', $nombre), 'mime' => 'application/pdf', 'base64' => base64_encode(PdfFactura::generar($h))];
    }

    public function siguienteNumero(Request $req): array
    {
        return ['numero' => $this->m->facturas()->siguienteNumero(self::acc(), $req->texto('emisor'), $req->texto('serie'), $req->texto('fecha'), $req->texto('tipo'))];
    }

    public function desdeNegocio(Request $req): array { return $this->m->facturas()->desdeNegocio(self::acc(), $req->param('id')); }

    /* ---------- Explorador (carpetas) y por cliente ---------- */

    public function hubs(Request $req): array { return $this->m->explorador()->hubs(self::acc()); }
    public function tipos(Request $req): array { return $this->m->explorador()->tipos(self::acc(), $req->texto('emisor')); }
    public function meses(Request $req): array { return $this->m->explorador()->meses(self::acc(), $req->texto('emisor'), $req->texto('tipo')); }
    public function lista(Request $req): array { return $this->m->explorador()->lista(self::acc(), $req->texto('emisor'), $req->texto('tipo'), $req->texto('mes')); }
    public function porCliente(Request $req): array { return $this->m->explorador()->porCliente(self::acc()); }
    public function cliente(Request $req): array { return $this->m->explorador()->cliente(self::acc(), $req->param('id')); }
    public function clientesFacturacion(Request $req): array { return $this->m->clientes()->listar(self::acc()); }
    public function guardarClienteFacturacion(Request $req): array { return ['cliente' => $this->m->clientes()->guardar(self::acc(), $req->param('id'), $req->json())]; }

    /* ---------- Documentos subidos ---------- */

    public function documentos(Request $req): array
    {
        return $this->m->documentos()->listar(self::acc(), ['emisor' => $req->texto('emisor'), 'tipo' => $req->texto('tipo'), 'mes' => $req->texto('mes')]);
    }

    public function crearDocumento(Request $req): Respuesta
    {
        return new Respuesta(['documento' => $this->m->documentos()->guardar(self::acc(), null, self::formulario($req), self::archivo())], 201);
    }

    public function actualizarDocumento(Request $req): array
    {
        return ['documento' => $this->m->documentos()->guardar(self::acc(), $req->param('id'), self::formulario($req), self::archivo())];
    }

    public function borrarDocumento(Request $req): array { return ['papelera_id' => $this->m->documentos()->borrar(self::acc(), $req->param('id'))]; }

    /** Multipart ($_POST) o JSON si no hay archivo. */
    private static function formulario(Request $req): array
    {
        if (str_starts_with(strtolower($req->cabecera('content-type')), 'multipart/form-data')) return $_POST;
        return $req->json();
    }

    private static function archivo(): ?array
    {
        $f = $_FILES['archivo'] ?? null;
        if ($f !== null && !is_array($f)) throw HttpError::validacion('Archivo no válido.', 'archivo');
        return $f;
    }

    /* ---------- Programaciones ---------- */

    public function programaciones(Request $req): array { return $this->m->programaciones()->listar(self::acc()); }
    public function crearProgramacion(Request $req): Respuesta { return new Respuesta(['programacion' => $this->m->programaciones()->crear(self::acc(), $req->json())], 201); }
    public function actualizarProgramacion(Request $req): array { return ['programacion' => $this->m->programaciones()->actualizar(self::acc(), $req->param('id'), $req->json())]; }
    public function pausarProgramacion(Request $req): array { return ['programacion' => $this->m->programaciones()->activar(self::acc(), $req->param('id'), false)]; }
    public function activarProgramacion(Request $req): array { return ['programacion' => $this->m->programaciones()->activar(self::acc(), $req->param('id'), true)]; }

    public function borrarProgramacion(Request $req): array
    {
        $this->m->programaciones()->borrar(self::acc(), $req->param('id'));
        return [];
    }

    public function generar(Request $req): array { return $this->m->programaciones()->generar(self::acc()); }

    /* ---------- Contabilidad y resumen ---------- */

    public function movimientos(Request $req): array
    {
        return $this->m->contabilidad()->movimientos(self::acc(), $req->texto('ambito', 'empresa'), $req->entero('anio', (int)date('Y')));
    }

    public function analisis(Request $req): array
    {
        return $this->m->contabilidad()->analisis(self::acc(), $req->texto('ambito', 'empresa'), $req->entero('anio', (int)date('Y')));
    }

    public function resumen(Request $req): array
    {
        return $this->m->resumen()->mensual(self::acc(), $req->texto('ambito', 'empresa') ?: 'empresa', $req->texto('mes', date('Y-m')) ?: date('Y-m'));
    }

    /* ---------- Horas ---------- */

    public function personasHoras(Request $req): array { return $this->m->horas()->personas(self::acc()); }

    public function horas(Request $req): array
    {
        return $this->m->horas()->ver(self::acc(), $req->entero('admin_id') ?: null, $req->texto('mes', date('Y-m')) ?: date('Y-m'));
    }

    public function anadirExtra(Request $req): Respuesta { return new Respuesta($this->m->horas()->anadirExtra(self::acc(), $req->json()), 201); }

    public function borrarHora(Request $req): array
    {
        $this->m->horas()->borrar(self::acc(), $req->param('id'));
        return [];
    }

    public function tarifa(Request $req): array { return ['persona' => $this->m->horas()->tarifa(self::acc(), $req->param('id'), $req->json())]; }

    public function volcar(Request $req): array
    {
        $d = $req->json();
        return $this->m->horas()->volcar(self::acc(), (int)($d['admin_id'] ?? 0) ?: self::acc()->adminId, (string)($d['mes'] ?? ''));
    }

    /* ---------- Proyectos ---------- */

    public function proyectos(Request $req): array { return $this->m->proyectos()->listar(self::acc(), $req->texto('anio', date('Y'))); }
    public function proyecto(Request $req): array { return $this->m->proyectos()->ficha(self::acc(), $req->param('id'), $req->texto('anio', date('Y'))); }
    public function crearProyecto(Request $req): Respuesta { return new Respuesta(['proyecto' => $this->m->proyectos()->crear(self::acc(), $req->json())], 201); }
    public function actualizarProyecto(Request $req): array { return ['proyecto' => $this->m->proyectos()->actualizar(self::acc(), $req->param('id'), $req->json())]; }

    public function borrarProyecto(Request $req): array
    {
        $this->m->proyectos()->borrar(self::acc(), $req->param('id'));
        return [];
    }

    public function buscarProyectos(Request $req): array
    {
        $cli = $req->entero('client_id');
        return ['items' => $this->m->proyectos()->buscar(self::acc(), $req->texto('q'), $cli ?: null, $req->entero('limit', 12))];
    }

    public function anadirMovimiento(Request $req): Respuesta { return new Respuesta($this->m->proyectos()->anadirMovimiento(self::acc(), $req->param('id'), $req->json()), 201); }

    public function desvincularMovimiento(Request $req): array
    {
        $this->m->proyectos()->desvincularMovimiento(self::acc(), $req->param('id'), $req->param('acc'));
        return [];
    }

    public function vinculables(Request $req): array { return ['items' => $this->m->proyectos()->vinculables(self::acc(), $req->param('id'), $req->texto('q'))]; }

    public function vincularFactura(Request $req): array
    {
        $this->m->proyectos()->vincularFactura(self::acc(), $req->param('id'), $req->param('inv'), true);
        return [];
    }

    public function desvincularFactura(Request $req): array
    {
        $this->m->proyectos()->vincularFactura(self::acc(), $req->param('id'), $req->param('inv'), false);
        return [];
    }
}
