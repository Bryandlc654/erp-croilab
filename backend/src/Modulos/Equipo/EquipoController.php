<?php
namespace Croilab\Modulos\Equipo;

use Croilab\Http\HttpError;
use Croilab\Http\Request;
use Croilab\Http\Respuesta;
use Croilab\Seguridad\Acceso;

/* HTTP ⇄ servicios del módulo Equipo y ajustes. Sin reglas de negocio aquí.
   Las subidas (foto, logo, JSON de Google) llegan en multipart: se leen de
   $_FILES['archivo']. */
class EquipoController
{
    public function __construct(private readonly Servicios $s) {}

    private static function acc(): Acceso
    {
        return Acceso::actual();
    }

    private static function archivo(): mixed
    {
        return $_FILES['archivo'] ?? null;
    }

    /* ---------- Miembros ---------- */

    public function miembros(Request $req): array
    {
        return $this->s->miembros()->listar(self::acc(), $req->texto('bajas') === '1');
    }

    public function crearMiembro(Request $req): Respuesta
    {
        return new Respuesta($this->s->miembros()->crear(self::acc(), $req->json()), 201);
    }

    public function miembro(Request $req): array
    {
        return ['miembro' => $this->s->miembros()->detalle(self::acc(), $req->param('id'))];
    }

    public function actualizarMiembro(Request $req): array
    {
        return $this->s->miembros()->actualizar(self::acc(), $req->param('id'), $req->json());
    }

    public function rolMiembro(Request $req): array
    {
        return $this->s->miembros()->cambiarRol(self::acc(), $req->param('id'), (string)($req->json()['rol'] ?? ''));
    }

    public function facturacionMiembro(Request $req): array
    {
        return $this->s->miembros()->facturacion(self::acc(), $req->param('id'), $req->json());
    }

    public function passwordMiembro(Request $req): array
    {
        return $this->s->miembros()->password(self::acc(), $req->param('id'), $req->json());
    }

    public function anularEnlaceMiembro(Request $req): array
    {
        return $this->s->miembros()->anularEnlace(self::acc(), $req->param('id'));
    }

    public function bajaMiembro(Request $req): array
    {
        return $this->s->miembros()->baja(self::acc(), $req->param('id'));
    }

    public function reactivarMiembro(Request $req): array
    {
        return $this->s->miembros()->reactivar(self::acc(), $req->param('id'));
    }

    /* ---------- Invitaciones y registro (público) ---------- */

    public function invitaciones(Request $req): array
    {
        return $this->s->invitaciones()->listar(self::acc());
    }

    public function crearInvitacion(Request $req): Respuesta
    {
        return new Respuesta($this->s->invitaciones()->crear(self::acc(), $req->json()), 201);
    }

    public function enlaceInvitacion(Request $req): array
    {
        return $this->s->invitaciones()->enlace(self::acc(), $req->param('id'));
    }

    public function anularInvitacion(Request $req): array
    {
        return $this->s->invitaciones()->anular(self::acc(), $req->param('id'));
    }

    public function comprobarRegistro(Request $req): array
    {
        return $this->s->invitaciones()->comprobar($req->texto('t') ?: $req->texto('token'));
    }

    public function registrar(Request $req): Respuesta
    {
        /* Sin sesión: freno por IP para que nadie pruebe tokens a lo loco. */
        if (function_exists('login_throttle_bloqueo') && ($espera = login_throttle_bloqueo('registro-ip')) > 0) {
            throw new HttpError(429, login_throttle_msg($espera), 'bloqueo', ['espera' => (int)$espera]);
        }
        try {
            $r = $this->s->invitaciones()->registrar($req->json());
        } catch (HttpError $e) {
            if ($e->status === 404 && function_exists('login_throttle_fallo')) login_throttle_fallo('registro-ip');
            throw $e;
        }
        if (function_exists('sesion_auditar')) sesion_auditar('registro por enlace', $r['username']);
        return new Respuesta($r, 201);
    }

    public function reconfirmar(Request $req): array
    {
        $me = current_admin() ?? throw HttpError::sesion();
        $d = $req->json();
        $hasta = Reautenticacion::confirmar($me, (string)($d['password'] ?? ''), (string)($d['zona'] ?? ''));
        return ['hasta' => date('c', $hasta)];
    }

    /* ---------- Perfil y cuenta ---------- */

    public function perfil(Request $req): array
    {
        return $this->s->perfil()->ver(self::acc(), $req->param('id'));
    }

    public function actualizarPerfil(Request $req): array
    {
        return $this->s->perfil()->actualizar(self::acc(), $req->param('id'), $req->json());
    }

    public function subirFoto(Request $req): array
    {
        return $this->s->perfil()->subirFoto(self::acc(), $req->param('id'), self::archivo());
    }

    public function quitarFoto(Request $req): array
    {
        return $this->s->perfil()->quitarFoto(self::acc(), $req->param('id'));
    }

    public function cuenta(Request $req): array
    {
        return $this->s->perfil()->cuenta(self::acc());
    }

    public function actualizarCuenta(Request $req): array
    {
        return $this->s->perfil()->actualizarCuenta(self::acc(), $req->json());
    }

    public function cambiarPassword(Request $req): array
    {
        return $this->s->perfil()->cambiarPassword(self::acc(), $req->json());
    }

    public function avisos(Request $req): array
    {
        return $this->s->perfil()->avisos(self::acc());
    }

    public function guardarAvisos(Request $req): array
    {
        return $this->s->perfil()->guardarAvisos(self::acc(), $req->json());
    }

    /* ---------- Roles ---------- */

    public function roles(Request $req): array
    {
        return $this->s->roles()->listar(self::acc());
    }

    public function crearRol(Request $req): Respuesta
    {
        return new Respuesta($this->s->roles()->crear(self::acc(), $req->json()), 201);
    }

    public function renombrarRol(Request $req): array
    {
        return $this->s->roles()->renombrar(self::acc(), $req->json());
    }

    public function borrarRol(Request $req): array
    {
        return $this->s->roles()->borrar(self::acc(), $req->texto('rol'));
    }

    public function permisoRol(Request $req): array
    {
        return $this->s->roles()->permiso(self::acc(), $req->json());
    }

    public function grupoRol(Request $req): array
    {
        return $this->s->roles()->grupo(self::acc(), $req->json());
    }

    /* ---------- Ajustes ---------- */

    public function agencia(Request $req): array
    {
        return $this->s->ajustes()->agencia(self::acc());
    }

    public function guardarAgencia(Request $req): array
    {
        return $this->s->ajustes()->guardarAgencia(self::acc(), $req->json());
    }

    public function subirLogo(Request $req): array
    {
        return $this->s->ajustes()->subirLogo(self::acc(), self::archivo());
    }

    public function quitarLogo(Request $req): array
    {
        return $this->s->ajustes()->quitarLogo(self::acc());
    }

    public function contacto(Request $req): array
    {
        return $this->s->ajustes()->contacto(self::acc());
    }

    public function guardarContacto(Request $req): array
    {
        return $this->s->ajustes()->guardarContacto(self::acc(), $req->json());
    }

    public function videos(Request $req): array
    {
        return $this->s->ajustes()->videos(self::acc());
    }

    public function guardarVideos(Request $req): array
    {
        return $this->s->ajustes()->guardarVideos(self::acc(), $req->json());
    }

    public function reglas(Request $req): array
    {
        return $this->s->ajustes()->reglas(self::acc());
    }

    public function regla(Request $req): array
    {
        return $this->s->ajustes()->regla(self::acc(), $req->json());
    }

    public function ejecutarReglas(Request $req): array
    {
        return $this->s->ajustes()->ejecutarReglas(self::acc());
    }

    public function metricas(Request $req): array
    {
        return $this->s->metricas()->resumen(self::acc());
    }

    public function eventosMetricas(Request $req): array
    {
        return $this->s->metricas()->guardarEventos(self::acc(), $req->json());
    }

    public function sincronizarMetricas(Request $req): array
    {
        return $this->s->metricas()->sincronizarTodos(self::acc());
    }

    public function sincronizarMetricasCliente(Request $req): array
    {
        return $this->s->metricas()->sincronizarCliente(self::acc(), $req->param('id'));
    }

    /* ---------- Integraciones ---------- */

    public function integraciones(Request $req): array
    {
        return $this->s->integraciones()->estado(self::acc());
    }

    public function verTokenApi(Request $req): array
    {
        return $this->s->integraciones()->verTokenApi(self::acc());
    }

    public function regenerarTokenApi(Request $req): array
    {
        return $this->s->integraciones()->regenerarTokenApi(self::acc());
    }

    public function credencialesCalendar(Request $req): array
    {
        return $this->s->integraciones()->credencialesGoogle(self::acc(), 'calendar', $req->json());
    }

    public function credencialesMetricas(Request $req): array
    {
        return $this->s->integraciones()->credencialesGoogle(self::acc(), 'metricas', $req->json());
    }

    public function credencialesMetricasJson(Request $req): array
    {
        $f = self::archivo();
        if (!is_array($f) || (int)($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw HttpError::validacion('No ha llegado el archivo.', 'archivo');
        if ((int)$f['size'] > 65536) throw HttpError::validacion('Ese archivo es demasiado grande para ser el de Google.', 'archivo');
        return $this->s->integraciones()->credencialesDesdeJson(self::acc(), 'metricas', (string)file_get_contents((string)$f['tmp_name']));
    }

    public function conectarCalendar(Request $req): array
    {
        return $this->s->integraciones()->conectarCalendar(self::acc(), $req->texto('volver'));
    }

    public function desconectarCalendar(Request $req): array
    {
        return $this->s->integraciones()->desconectarCalendar(self::acc());
    }

    public function conectarMetricas(Request $req): array
    {
        return $this->s->integraciones()->conectarMetricas(self::acc());
    }

    public function desconectarMetricas(Request $req): array
    {
        return $this->s->integraciones()->desconectarMetricas(self::acc());
    }

    /* Navegación de nivel superior desde Google: se responde con una redirección al front. */
    public function vueltaGoogle(Request $req): Respuesta
    {
        $destino = $this->s->integraciones()->vuelta($req->query);
        return new Respuesta(['redirigir' => $destino], 302, ['Location' => $destino]);
    }

    public function mcp(Request $req): array
    {
        return $this->s->integraciones()->mcp(self::acc(), $req->json());
    }

    public function verMcp(Request $req): array
    {
        return $this->s->integraciones()->verMcp(self::acc());
    }

    public function regenerarMcp(Request $req): array
    {
        return $this->s->integraciones()->regenerarMcp(self::acc());
    }

    /* ---------- Bóveda ---------- */

    public function bovedaClientes(Request $req): array
    {
        return $this->s->boveda()->clientes(self::acc());
    }

    public function credenciales(Request $req): array
    {
        return $this->s->boveda()->listar(self::acc(), $req->param('id'));
    }

    public function crearCredencial(Request $req): Respuesta
    {
        return new Respuesta($this->s->boveda()->crear(self::acc(), $req->param('id'), $req->json()), 201);
    }

    public function actualizarCredencial(Request $req): array
    {
        return $this->s->boveda()->actualizar(self::acc(), $req->param('id'), $req->json());
    }

    public function borrarCredencial(Request $req): array
    {
        return $this->s->boveda()->borrar(self::acc(), $req->param('id'));
    }

    public function revelarCredencial(Request $req): array
    {
        return $this->s->boveda()->revelar(self::acc(), $req->param('id'));
    }
}
