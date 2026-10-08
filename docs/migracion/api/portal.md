# API · Portal del cliente

Prefijo `/api/v1`. Convenciones de `backend/API.md`: JSON `{ok:true, …}`, CSRF (`X-CSRF-Token`) en todo POST/PATCH,
errores `{ok:false, msg, error}`. Importes en **céntimos**. Meses como clave `YYYY-MM` + `etiqueta` («Septiembre 2026»).

Código: `backend/src/Modulos/Portal/*`, rutas en `backend/api/rutas/portal.php`, migración `0070_portal_acceso.php`,
tests en `backend/tests/{Unit,Integracion}/Portal`. Front: `front/src/features/portal/**` (`rutasPortal`, `rutasEditorPortal`).

## Sesión del cliente (separada de la del equipo)

- Misma cookie de PHP (`croilab_portal`) pero **claves propias**: `$_SESSION['portal_cli']` y `$_SESSION['portal_ver']`
  (versión de credenciales). Nunca `admin_id`: un cliente no pasa por `current_admin()` ni por `Acceso`, y una sesión del
  equipo no es una sesión de cliente (las rutas del portal solo miran sus claves).
- Las rutas `/v1/portal/…` son **públicas para el Kernel**; cada acción exige la sesión del portal
  (`PortalSesion::exigir()` → **401 `portal_sesion`**) y toma el cliente de ahí. Nunca se acepta un `client_id` de la petición.
- Cambiar la contraseña (sube `clients.cred_ver`) o dar de baja al cliente (`activo = 0`) cierra su sesión en la petición siguiente.
- Con la sesión del equipo abierta en el navegador no se puede entrar como cliente (409 `equipo`): el acceso pasa a «modo equipo».

## Acceso (públicas)

| Método | Ruta | Cuerpo / query | Respuesta |
|---|---|---|---|
| GET | `/portal/sesion` | `?m=<agencia>` | `{csrf, cliente: {id,name,saludo,iniciales}\|null, equipo: {username}\|null, marca, google}` — `marca` = la del cliente con sesión o la de la agencia `m` |
| POST | `/portal/auth/login` | `{usuario, password}` (usuario o correo de Google) | `{csrf, cliente}` · 401 `credenciales` · 429 `bloqueo` (freno `cli:<usuario>` + IP) · 403 `inactivo` · 409 `equipo` |
| POST | `/portal/auth/logout` | | `{csrf}` (solo cierra lo del portal) |
| GET | `/portal/auth/google` | `?m=` | `{url}` de Google (`Cuenta::login()`) · 409 `google_sin_configurar` |
| POST | `/portal/auth/recuperar` | `{identificador}` | `{msg}` siempre igual (freno `pwreq-cli:` + IP). Enlace de 60 min a `login_email` o `fact_email` |
| GET | `/portal/auth/restablecer` | `?token=` | `{usuario}` · 404 si caducado/usado |
| POST | `/portal/auth/restablecer` | `{token, password}` | `{}` · 422 `password` (política e historial) · cierra sus sesiones |

«Entrar con Google» vuelve por la URI común de Google (Equipo). Para que entre al portal, la vuelta debe llamar a
`PortalAuthServicio::vueltaGoogle($r)` (devuelve `/portal` o `/portal/login?ge=denied|err|cancel|inactivo|equipo[&m=]`, o `null` si no era del portal).

## Portal (sesión del cliente)

| Método | Ruta | Respuesta |
|---|---|---|
| GET | `/portal` | Todo lo que pinta el portal (abajo) |
| GET | `/portal/facturas/{id}` | `{factura}` = `HojaFactura::paraCliente()` de Finanzas · 404 si es borrador o de otro cliente |
| GET | `/portal/facturas/{id}/pdf` | `{nombre, mime, base64}` (`PdfFactura::generar()`) |
| POST | `/portal/tickets` | `{asunto?, cuerpo}` → 201 `{ticket}` (`SoporteServicio::crearDesdePortal`, avisa a quien tiene acceso total) |
| GET | `/portal/tickets/{id}` | `{ticket: {id, asunto, cuerpo, estado, fecha, actualizado, respuestas:[{id, cuerpo, fecha, autor:{nombre,color,foto}\|null}]}}` |
| POST | `/portal/reuniones/solicitudes` | `{motivo, fecha?: YYYY-MM-DD ≥ hoy, franja?: Sin preferencia\|Por la mañana\|Al mediodía\|Por la tarde}` → 201 `{solicitud}`; aviso `meetreq:<id>` a los dueños; máx. 10 pendientes (429) |
| POST | `/portal/credenciales/{id}/secreto` | `{secreto}` (`BovedaServicio::secretoEnClaro`, solo `visible_cliente=1` suyas; auditado) |

### `GET /portal`

```
cliente: {id, name, saludo, iniciales, username, actual: 'YYYY-MM', actual_etiqueta}
vista_previa, puede_enviar          (false/true para el cliente; true/false en la vista previa del equipo)
secciones: {metricas, progreso, informes, como, accesos, plan}   (tipo de cliente; sin tipo, metricas = conversiones)
marca: {name, initial, logo, color, web, propia}   contacto: {meeting_url, whatsapp, email, telefono}   (marca blanca)
videos: {general, servicios: {nombre: idYouTube}}   catalogo: [{nombre, desc}]   servicios: string[] | null (null = todos)
estado, plan, accesos               (Clientes\ContenidoPortal; enlaces solo http(s))
looker                              (solo https de lookerstudio/datastudio.google.com y si se ven métricas)
metricas: [{clave, etiqueta, mes, ll, wa, fo, vi, ap, ctr, total, src, geo}]   (de más antigua a más nueva)
progreso: [{clave|null, etiqueta, completado:[{t,d}], pendiente:[{t,d}]}]       (de más nuevo a más viejo)
informes: [{clave|null, mes, etiqueta, titulo, texto, url}]
tareas: [{id, titulo, texto (solo explicacion_cliente), estado, prioridad, clave, mes, due, asignados:[{nombre,color,foto(data:)}]}]
facturas: [{id, numero, fecha, venc, estado (enviada|pagada|vencida|anulada), total}]
reuniones: [{id, fecha, hora, titulo, estado}]   solicitudes: [{id, fecha, franja, motivo, estado, creada}]
tickets: [{id, asunto, estado, respuestas, fecha, actualizado}]
credenciales: [{id, titulo, categoria, usuario, url, nota, tiene_secreto}]   (sin secreto)
```

Lo que el tipo oculta no se manda (métricas, progreso, informes, credenciales). Meses: `«Junio»` sin año se interpreta como el
último junio no posterior a la última sincronización (métricas) o a un mes por delante (trabajo e informes); `«Junio 2026»` y
`«2026-06»` se respetan. Si coinciden dos entradas del mismo mes gana la que lleva año.

## Equipo: vista previa y editor en vivo (sesión del equipo)

| Método | Ruta | Permiso | Qué hace |
|---|---|---|---|
| GET | `/portal/equipo/clientes` | `ver.clientes` (con alcance) | `{items:[{id, name, activo}]}` para el «modo equipo» del acceso |
| GET | `/portal/equipo/clientes/{id}` | `ver.clientes` + alcance (fuera = 404) | Lo mismo que `GET /portal` con `vista_previa: true, puede_enviar: false` |
| GET | `/portal/equipo/clientes/{id}/facturas/{factura}[/pdf]` | ídem | Hoja / PDF de la factura del cliente |
| GET | `/portal/equipo/clientes/{id}/tickets/{ticket}` | ídem | Ticket como lo ve el cliente |
| GET | `/portal/equipo/clientes/{id}/editor` | ídem | `{contenido, tipos, servicios_opciones, desde_tareas:{informes, progreso}, puede_guardar}` |
| PATCH | `/portal/equipo/clientes/{id}/editor` | `general.editar` + `clientes.portal` | Guarda lo que llegue → como el GET |

`contenido` / cuerpo del PATCH: `{name, username, password?, iniciales, saludo, actual, tipo_id, conversiones, looker, servicios: string[]|null,
estado, plan, accesos, tareas:[{mes, completado, pendiente}], informes:[{mes, titulo, texto, url}]}`. Identidad y bloques con los validadores
de Clientes (`ClientesServicio::validar`: usuario único, tipo existente, URLs http(s)…); informes, servicios y Looker con `Portal\Contenido`.
Nunca toca `met_json`, `login_email`, datos fiscales ni `activo`. Cambiar la contraseña sube `cred_ver` (el cliente vuelve a entrar).
`desde_tareas` avisa de que `publicar_progreso()` reescribirá informes/progreso al tocar una tarea del cliente.

## Tablas

- `portal_password_resets (client_id, token_hash CHAR(64) único, creado_en, expira_en, usado_en, ip)` — migración 0070.
- Usa: `clients`, `client_types`, `tasks`/`task_lists`/`task_assignees`, `invoices`/`invoice_items`, `crm_meetings`,
  `portal_meeting_requests`, `support_tickets`/`support_replies`, `client_credentials`, `partner_agencies`, `settings`.
