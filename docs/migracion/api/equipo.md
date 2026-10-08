# API · Equipo y ajustes

Rutas en `backend/api/rutas/equipo.php`, código en `backend/src/Modulos/Equipo/` y `backend/src/Google/`.
Convenciones de `backend/API.md`: respuestas `{ok:true, …}`; errores `{ok:false, msg, error, campo?}` con
`400 datos · 401 sesion · 403 permiso · 403 reauth (+zona) · 404 no_encontrado · 409 conflicto · 419 csrf · 422 validacion`.
Todo POST/PATCH/DELETE lleva `X-CSRF-Token`. Las subidas son `multipart/form-data` con el campo `archivo`.

El router no tiene PUT ni parámetros de texto: los cambios que la spec proponía con PUT van con POST/PATCH,
y las claves de rol (texto) van en el cuerpo o en `?rol=`.

## Reautenticación (zonas sensibles)

| Método | Ruta | Cuerpo | Respuesta |
|---|---|---|---|
| POST | `/v1/auth/reconfirmar` | `{password, zona}` (`boveda`·`integraciones`·`datos`) | `{hasta}` (ISO, 30 min) · 422 `campo:password` si no es correcta (con freno por intentos) |

Los endpoints protegidos responden `403 {error:"reauth", zona}` si no se ha confirmado en los últimos 30 min.
Desde otro módulo: `Croilab\Modulos\Equipo\Reautenticacion::exigir('datos')` (zona `datos` ya admitida para
«Datos avanzados» de Clientes).

## Mi equipo — `equipo.gestionar`

| Método | Ruta | Cuerpo / query | Respuesta |
|---|---|---|---|
| GET | `/v1/equipo/miembros` | `?bajas=1` para los dados de baja | `{items: Miembro[], total, clientes_alta, bajas, roles: [{clave, nombre, descripcion, total, asignable}]}` |
| POST | `/v1/equipo/miembros` | `{username, email?, role, password?, enviar_enlace?, enviar_correo?}` | 201 `{miembro: Ficha, enlace?: {url, caduca, enviado}}` |
| GET | `/v1/equipo/miembros/{id}` | | `{miembro: Ficha}` |
| PATCH | `/v1/equipo/miembros/{id}` | `{username?, email?, role?}` | `{miembro, recargar}` |
| POST | `/v1/equipo/miembros/{id}/rol` | `{rol}` | `{miembro, recargar}` (recargar = te lo has cambiado a ti) |
| PATCH | `/v1/equipo/miembros/{id}/facturacion` | `{es_autonomo, tarifa_hora, iva_pct, irpf_pct}` (coma o punto, 2 decimales) | `{miembro}` |
| POST | `/v1/equipo/miembros/{id}/password` | `{modo:'fijar', password}` · `{modo:'generar'}` → `{password}` (una vez) · `{modo:'enlace', enviar_correo?}` → `{enlace}` | |
| DELETE | `/v1/equipo/miembros/{id}/password-enlace` | | `{}` |
| DELETE | `/v1/equipo/miembros/{id}` | | `{}` baja lógica · 409 `uno_mismo` / `ultimo_dueno` |
| POST | `/v1/equipo/miembros/{id}/reactivar` | | `{miembro}` |

`Miembro = {id, username, email|null, role, role_nombre, role_descripcion, es_admin_total, activo, desde (YYYY-MM), cumple, cargo, foto, yo}`;
`Ficha = Miembro + {es_autonomo, tarifa_hora, iva_pct, irpf_pct (texto "12.50"), enlace_password: {caduca}|null}`.

Reglas: dar/quitar un rol con `admin.total`, o tocar a alguien que lo tiene, exige `admin.total` (403).
No puede quedar ninguna persona **activa** con acceso total (409). La baja: `activo=0`, `cred_ver++`, fuera de
las salas de chat, sin tokens de Google ni preferencias de avisos, enlaces de contraseña anulados, y sus tareas
**abiertas** sin responsable (las completadas conservan autor). Fijar/generar contraseña pasa por
`credenciales_cambiar()` (`cred_ver++`, historial). El enlace es un `password_resets` de 48 h que se usa en
`/restablecer?token=` del front; el correo se manda con `Diferidas` tras responder.

## Registro por enlace

| Método | Ruta | Permiso | Cuerpo / respuesta |
|---|---|---|---|
| GET | `/v1/equipo/invitaciones` | `equipo.gestionar` | `{items: [{id, rol, rol_nombre, email, caduca, creado_por, copiable}], roles: [{clave, nombre}]}` (sin acceso total) |
| POST | `/v1/equipo/invitaciones` | `equipo.gestionar` + `general.editar` | `{rol, email?}` → 201 `{invitacion: {id, url, caduca, enviado}}` (si hay email se le manda) |
| GET | `/v1/equipo/invitaciones/{id}/enlace` | `equipo.gestionar` | `{url}` para «Copiar enlace» |
| DELETE | `/v1/equipo/invitaciones/{id}` | `equipo.gestionar` + `general.editar` | `{}` |
| GET | `/v1/registro?t=` | **pública** | `{rol_nombre, email, marca: {nombre, inicial, logo, color}, horas}` · 404 si no vale |
| POST | `/v1/registro` | **pública** (CSRF de `/v1/auth/csrf`) | `{token, username, email?, password}` → 201 `{username}` · 422 con `campo` · 404 |

Tabla `team_invitations` (migración 0050): token de 32 bytes buscado por SHA-256, un solo uso (se gasta con un
UPDATE atómico), 48 h; copia cifrada con la bóveda (propósito `invitacion`) solo para volver a copiarlo.
Freno por IP a los intentos con token inválido. Al registrarse se avisa (`notif_add`, `info`) a quien tiene
`admin.total` o `equipo.gestionar` → `/ajustes/equipo/<id>`.

## Perfil y cuenta propia — con sesión

| Método | Ruta | Cuerpo | Respuesta |
|---|---|---|---|
| GET | `/v1/perfiles/{id}` | | `{perfil, departamentos}` (perfil: datos, `skills[]`, `presencia {estado, texto}`, `puede_editar`, `tareas {items, total}`) |
| PATCH | `/v1/perfiles/{id}` | `{cargo, departamento, telefono, ubicacion, web, skills, cumple, bio}` | igual que GET |
| POST | `/v1/perfiles/{id}/foto` | multipart `archivo` (JPG/PNG/GIF/WebP, ≤8 MB) | `{foto}` |
| DELETE | `/v1/perfiles/{id}/foto` | | `{foto: null}` |
| GET / PATCH | `/v1/me/cuenta` | `{username, email}` | `{cuenta: {username, email, password_changed_at}}` |
| POST | `/v1/me/password` | `{actual, nueva}` | `{csrf}` (cierra las demás sesiones; la actual sigue) |
| GET / PATCH | `/v1/me/avisos` | `{silenciar: ('chat'\|'avisos')[]}` | igual |

Editar un perfil: uno mismo o `equipo.gestionar` (a un Dueño solo quien tiene `admin.total`). Las tareas del
perfil cuentan responsable **y** asignadas, dentro del alcance de quien mira (y solo con `ver.tareas`).

## Roles — `roles.gestionar`

| Método | Ruta | Cuerpo | Respuesta |
|---|---|---|---|
| GET | `/v1/roles` | | `{roles: [{clave, nombre, descripcion, sistema, permisos, total, uso}], catalogo: [{grupo, permisos: [{clave, etiqueta, descripcion}]}], requisitos, config, personas}` |
| POST | `/v1/roles` | `{nombre}` | 201 `{clave}` |
| PATCH | `/v1/roles` | `{rol, nombre}` | `{nombre}` |
| DELETE | `/v1/roles?rol=` | | `{}` · 409 sistema / en uso |
| POST | `/v1/roles/permisos` | `{rol, perm, on}` | `{permisos, recargar}` (apagar quita también lo que depende de él) |
| POST | `/v1/roles/grupos` | `{rol, grupo, on}` | `{permisos, recargar}` (nunca toca `admin.total`) |

Catálogo, requisitos y reglas: `admin/lib/permisos.php` (`perm_catalogo`, `perm_requisitos`, `rol_guardar`,
`rol_borrar`). Dar o quitar `admin.total` exige tenerlo; al rol Dueño no se le quita (409).

## Ajustes

| Método | Ruta | Permiso | Cuerpo / respuesta |
|---|---|---|---|
| GET / PATCH | `/v1/ajustes/agencia` | `ver.ajustes` / `ajustes.editar` | `{agencia: {nombre, cif, email, telefono, web, direccion, logo, color}, puede_editar}` |
| POST / DELETE | `/v1/ajustes/agencia/logo` | `ajustes.editar` | multipart `archivo` (≤2 MB) → igual que GET |
| GET / PATCH | `/v1/ajustes/portal/contacto` | `ver.ajustes` / `ajustes.editar` | `{contacto: {email, whatsapp (solo cifras), meeting_url}}` |
| GET / PATCH | `/v1/ajustes/portal/videos` | `ver.ajustes` / `ajustes.editar` | `{video_id, servicios: [{nombre, video}]}` (URL o id de YouTube → id) |
| GET | `/v1/ajustes/automatizaciones` | `ver.ajustes` | `{reglas: [{clave, titulo, descripcion, on}]}` |
| PATCH | `/v1/ajustes/automatizaciones` | `ajustes.editar` | `{clave, on}` |
| POST | `/v1/ajustes/automatizaciones/ejecutar` | `ajustes.editar` | `{msg}` |
| GET | `/v1/ajustes/metricas` | `ver.ajustes` | `{conectado, revocado, configurado, ultima, eventos: {ll, wa, fo}, clientes: [{id, nombre, web, analytics, conversiones, sync}], con_web}` (con alcance) |
| PATCH | `/v1/ajustes/metricas` | `ajustes.editar` | `{ll?, wa?, fo?}` eventos GA4 por defecto |
| POST | `/v1/ajustes/metricas/sync` | `ver.ajustes` + `general.editar` | `{ok, avisos, detalle[], msg}` (mes actual y anterior) |
| POST | `/v1/ajustes/metricas/sync/{id}` | ídem + alcance | `{msg}` · 502 con el aviso de Google |

Cada cambio de `settings` queda en `audit_log` (`audit_setting`). El logo subido se guarda en
`backend/uploads/marca/logo_<16hex>.<ext>` y se sirve como fichero estático (lo ve también la pantalla de
acceso del portal, sin sesión); `agency_logo` guarda `APP_URL/uploads/marca/…`.

## Integraciones

| Método | Ruta | Permiso | Respuesta |
|---|---|---|---|
| GET | `/v1/integraciones` | `ver.ajustes` | `{puede_editar, redirect_uri, api: {activa}, calendar: {client_id, secreto_guardado, secreto_ilegible, configurado, conectado, revocado, email, cuentas}, metricas: {…, ultima_sync}, mcp: {activo}}` — nunca secretos |
| GET | `/v1/integraciones/api/token` | `integraciones.editar` + reauth `integraciones` | `{token}` |
| POST | `/v1/integraciones/api/token` | `integraciones.editar` | `{token}` (regenera) |
| PATCH | `/v1/integraciones/google-calendar/credenciales` | `integraciones.editar` | `{client_id, client_secret?}` (vacío = no cambiar) |
| GET | `/v1/integraciones/google-calendar/conectar?volver=` | `ver.agenda` | `{url}` de Google para TU cuenta |
| DELETE | `/v1/integraciones/google-calendar/conexion` | sesión | `{}` |
| PATCH | `/v1/integraciones/metricas/credenciales` | `integraciones.editar` | `{client_id, client_secret?}` |
| POST | `/v1/integraciones/metricas/credenciales` | `integraciones.editar` | multipart `archivo` = el `.json` de Google |
| GET | `/v1/integraciones/metricas/conectar` | `integraciones.editar` | `{url}` |
| DELETE | `/v1/integraciones/metricas/conexion` | `integraciones.editar` | `{}` (conserva id/secreto) |
| GET | `/v1/integraciones/google/callback` | **pública** | 302 a `FRONT_URL<volver>?google=ok\|cancelado\|state\|error[&msg=]` |
| PATCH | `/v1/integraciones/mcp` | `integraciones.editar` | `{enabled}` → `{activo, url?}` |
| GET | `/v1/integraciones/mcp/url` | `integraciones.editar` + reauth | `{url}` |
| POST | `/v1/integraciones/mcp/token` | `integraciones.editar` | `{url}` |

## Bóveda de credenciales de clientes — `ver.credenciales` (+ alcance de clientes)

| Método | Ruta | Permiso extra | Cuerpo / respuesta |
|---|---|---|---|
| GET | `/v1/credenciales/clientes` | | `{items: [{id, nombre, iniciales, activo, n}]}` |
| GET | `/v1/credenciales/clientes/{id}` | | `{cliente, items: Credencial[], puede_editar, categorias}` — **sin secretos** (`tiene_secreto`) |
| POST | `/v1/credenciales/clientes/{id}` | `general.editar` | `{titulo, categoria, usuario, secreto, url, nota, visible_cliente}` → 201 `{credencial}` |
| PATCH | `/v1/credenciales/{id}` | `general.editar` | parcial; `secreto` vacío = no cambiar; `quitar_secreto: true` |
| DELETE | `/v1/credenciales/{id}` | `general.editar` | `{papelera_id}` (tipo `credencial` en la papelera) |
| POST | `/v1/credenciales/{id}/revelar` | reauth `boveda` | `{secreto}` + `audit_log('boveda.revelar')` |

Fuera de alcance = 404. El secreto se cifra con `boveda_cifrar(…, 'cred_cliente')`; nunca se guarda en claro
(500 `boveda` si no se puede cifrar). URL solo `http(s)`. Para el portal del cliente:
`(new BovedaServicio($pdo))->secretoEnClaro($credId, $clientId)` (solo `visible_cliente=1` de ese cliente).

## Google (`backend/src/Google/`) — para Comunicación y Clientes

```php
use Croilab\Google\{GoogleOAuth, Cuenta, ErrorGoogle, Metricas};

$g = $c->unico('google', fn() => new GoogleOAuth());     // misma instancia que este módulo
$g->configurado('calendar' | 'metricas');                 // client id + secreto puestos
$g->estado(Cuenta::calendario($adminId));                 // {configurado, conectado, revocado, email}
$url = $g->urlAutorizacion(Cuenta::calendario($adminId), '/calendario');   // para «Conectar»
$token = $g->tokenAcceso(Cuenta::metricas());             // refresca solo; ErrorGoogle sin_conectar|revocado|google|red
$cli = $g->cliente(Cuenta::calendario($adminId));         // ClienteGoogle autenticado
$eventos = $cli->get('https://www.googleapis.com/calendar/v3/calendars/primary/events', ['timeMin' => …]);
$cli->post($url, $json, $query); $cli->patch(…); $cli->delete(…); $cli->bruta('DELETE', $url) // RespuestaHttp (p. ej. 410)
$g->desconectar(Cuenta::calendario($adminId));            // borra y revoca
$g->cuentasCalendario();                                  // ids con calendario conectado
$m = new Metricas($g, $pdo);                              // sincronizarCliente($id, ['2026-10']), sincronizarTodos(), listaEventos($prop)
```

- `Cuenta::calendario($id)` (tokens por persona, `settings.gcal_tok_<id>`, propósito `gcal`, compatible con
  `admin/lib/gcal.php`), `Cuenta::metricas()` (global, `google_oauth_refresh_token` + `gmet_tok`, propósito
  `gmet`), `Cuenta::login()` (solo identidad, no guarda tokens: `procesarVuelta()` devuelve el `email`
  verificado; para «Entrar con Google» de Auth).
- Una sola URI de vuelta: `GOOGLE_REDIRECT_URI` o `APP_URL` + `/api/v1/integraciones/google/callback`.
  `state` firmado con `APP_SECRET`, 10 min, un solo uso y atado a la sesión. La vuelta redirige a `FRONT_URL`.
- Scopes: calendario `calendar.events calendar.readonly openid email` (con `email` sale el «Conectado: …»),
  métricas `webmasters.readonly analytics.readonly openid email`.
- `invalid_grant` marca `gcal_revoked_<id>` / `gmet_revoked` y lanza `ErrorGoogle('revocado')`.
- El transporte es la interfaz `Http` (`HttpCurl` en producción): los tests usan uno falso.
- **Sin credenciales reales**: todo funciona hasta generar la URL de Google; falta poner el client id/secreto en
  Ajustes › Integraciones y registrar la URI de vuelta en Google Cloud.
