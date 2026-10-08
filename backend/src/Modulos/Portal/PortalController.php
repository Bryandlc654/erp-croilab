<?php
namespace Croilab\Modulos\Portal;

use Croilab\Http\HttpError;
use Croilab\Http\Request;
use Croilab\Http\Respuesta;
use Croilab\Seguridad\Acceso;

/* Rutas del portal. Dos mundos que no se mezclan:
     · /v1/portal/…            sesión del CLIENTE (rutas públicas para el Kernel:
                               aquí se exige la sesión del portal en cada acción
                               y el cliente sale siempre de la sesión).
     · /v1/portal/equipo/…     sesión del EQUIPO (el Kernel exige current_admin):
                               vista previa y editor en vivo de un cliente. */
class PortalController
{
    /** @param \Closure(): PortalAuthServicio $auth */
    public function __construct(
        private readonly PortalSesion $sesion,
        private readonly \Closure $auth,
        private readonly PortalServicio $portal,
        private readonly EditorServicio $editor
    ) {}

    private function auth(): PortalAuthServicio
    {
        return ($this->auth)();
    }

    private function cli(): int
    {
        return (int)$this->sesion->exigir()['id'];
    }

    /* ---------- Sesión del cliente ---------- */

    /** Estado de la sesión para la pantalla de acceso y el arranque del portal. */
    public function sesion(Request $req): array
    {
        $c = $this->sesion->cliente();
        $equipo = null;
        if (PortalSesion::hayEquipo() && ($a = current_admin())) $equipo = ['username' => (string)$a['username']];
        $m = $req->entero('m');
        return [
            'csrf' => csrf_token(),
            'cliente' => $c ? PortalAuthServicio::resumen($c) : null,
            'equipo' => $equipo,
            /* En el acceso, la marca de la agencia del enlace (?m=); con sesión, la del cliente. */
            'marca' => PortalServicio::marcaAcceso($c ? (int)($c['partner_id'] ?? 0) : $m),
            'google' => $this->auth()->googleDisponible(),
        ];
    }

    public function entrar(Request $req): array
    {
        $d = $req->json();
        $ident = is_string($d['usuario'] ?? null) ? $d['usuario'] : (is_string($d['username'] ?? null) ? $d['username'] : '');
        return $this->auth()->entrar($ident, is_string($d['password'] ?? null) ? $d['password'] : '');
    }

    public function salir(Request $req): array
    {
        return ['csrf' => $this->auth()->salir()];
    }

    public function google(Request $req): array
    {
        return ['url' => $this->auth()->urlGoogle($req->entero('m'))];
    }

    public function recuperar(Request $req): array
    {
        $ident = trim((string)($req->json()['identificador'] ?? ''));
        foreach (['pwreq-cli:' . mb_strtolower($ident), 'pwreq-cli-ip'] as $clave) {
            $espera = login_throttle_bloqueo($clave);
            if ($espera > 0) throw new HttpError(429, login_throttle_msg($espera), 'bloqueo', ['espera' => (int)$espera]);
        }
        login_throttle_fallo('pwreq-cli:' . mb_strtolower($ident));
        login_throttle_fallo('pwreq-cli-ip');
        $this->auth()->pedirEnlace($ident, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return ['msg' => 'Si hay una cuenta con ese dato y tiene correo, te hemos enviado un enlace para elegir una contraseña nueva. Revisa también el correo no deseado.'];
    }

    public function comprobarEnlace(Request $req): array
    {
        return ['usuario' => $this->auth()->comprobarEnlace($req->texto('token'))];
    }

    public function restablecer(Request $req): array
    {
        $d = $req->json();
        $this->auth()->restablecer(trim((string)($d['token'] ?? '')), is_string($d['password'] ?? null) ? $d['password'] : '');
        return [];
    }

    /* ---------- Portal del cliente ---------- */

    public function datos(Request $req): array
    {
        return $this->portal->datos($this->cli());
    }

    public function factura(Request $req): array
    {
        return ['factura' => $this->portal->factura($this->cli(), $req->param('id'))];
    }

    public function pdf(Request $req): array
    {
        return $this->portal->pdf($this->cli(), $req->param('id'));
    }

    public function ticket(Request $req): array
    {
        return ['ticket' => $this->portal->ticket($this->cli(), $req->param('id'))];
    }

    public function escribir(Request $req): Respuesta
    {
        return new Respuesta(['ticket' => $this->portal->escribir($this->cli(), $req->json())], 201);
    }

    public function solicitarReunion(Request $req): Respuesta
    {
        return new Respuesta(['solicitud' => $this->portal->solicitarReunion($this->cli(), $req->json())], 201);
    }

    public function secreto(Request $req): array
    {
        return ['secreto' => $this->portal->secreto($this->cli(), $req->param('id'))];
    }

    /* ---------- Equipo: vista previa y editor ---------- */

    public function clientesEquipo(Request $req): array
    {
        return ['items' => $this->editor->clientes(Acceso::actual())];
    }

    public function vistaPrevia(Request $req): array
    {
        $id = $req->param('id');
        $this->editor->visible(Acceso::actual(), $id);
        return $this->portal->datos($id, true);
    }

    public function facturaVistaPrevia(Request $req): array
    {
        $id = $req->param('id');
        $this->editor->visible(Acceso::actual(), $id);
        return ['factura' => $this->portal->factura($id, $req->param('factura'))];
    }

    public function pdfVistaPrevia(Request $req): array
    {
        $id = $req->param('id');
        $this->editor->visible(Acceso::actual(), $id);
        return $this->portal->pdf($id, $req->param('factura'));
    }

    public function ticketVistaPrevia(Request $req): array
    {
        $id = $req->param('id');
        $this->editor->visible(Acceso::actual(), $id);
        return ['ticket' => $this->portal->ticket($id, $req->param('ticket'))];
    }

    public function editor(Request $req): array
    {
        return $this->editor->leer(Acceso::actual(), $req->param('id'));
    }

    public function guardarEditor(Request $req): array
    {
        return $this->editor->guardar(Acceso::actual(), $req->param('id'), $req->json());
    }
}
