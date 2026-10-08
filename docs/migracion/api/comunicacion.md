# API · Comunicación (chat, soporte, calendario, reuniones, MCP)

Rutas en `backend/api/rutas/comunicacion.php`, código en `backend/src/Modulos/Comunicacion/`.
Convenciones de `backend/API.md`: respuestas `{ok:true, …}`; errores `{ok:false, msg, error, campo?}` con
`400 datos · 401 sesion · 403 permiso · 404 no_encontrado · 409 conflicto · 419 csrf · 422 validacion · 502 google`.
Todo POST/PATCH/DELETE lleva `X-CSRF-Token`. El router no tiene PUT ni parámetros de texto: los ids de Google
(texto) van en el cuerpo o en `?id=`.

`Persona = {id, username, foto|null}`. Fechas y horas: `'AAAA-MM-DD HH:MM:SS'` (hora de Madrid).

## Chat — `ver.chat`

Todos los roles con `ver.chat` (también Solo lectura) leen, escriben y abren directos (decisión del antiguo).
Crear grupos: `general.editar`. Sacar a OTRA persona de un grupo: quien lo creó o `equipo.gestionar`
(antes cualquiera). Toda acción sobre una sala exige ser miembro: si no, **404**.

| Método | Ruta | Cuerpo / query | Respuesta |
|---|---|---|---|
| GET | `/v1/chat/salas` | `?activo=0\|1` (latido) | `{salas: Sala[], personas: Persona[] (equipo activo sin mí), presencia: {id: Presencia}, yo, puede_crear_grupo, puede_gestionar}` |
| POST | `/v1/chat/salas` | `{tipo:'dm', con}` · `{tipo:'grupo', nombre, miembros:[id]}` | 201 `{sala}` (el directo se reutiliza si ya existe) |
| GET | `/v1/chat/salas/{id}` | | `{sala}` |
| PATCH | `/v1/chat/salas/{id}` | `{nombre}` (solo grupos) | `{sala}` |
| POST | `/v1/chat/salas/{id}/miembros` | `{admin_id}` | `{sala}` |
| DELETE | `/v1/chat/salas/{id}/miembros/{adminId}` | (mi id = salir) | `{sala}` o `{sala:null}` si he salido |
| GET | `/v1/chat/salas/{id}/mensajes` | `?antes=<id>&limit=50` | `{mensajes: Mensaje[] (orden de llegada), hay_mas, leido_hasta, cursor}`. Sin `antes` = abrir la sala: marca leído y borra mi aviso `chat:<sala>:<yo>` |
| GET | `/v1/chat/salas/{id}/novedades` | `?despues=<último id>&cursor=<cursor>&activo=0\|1&leer=0\|1` | `{mensajes (id>despues), cambios (editados/borrados/reacciones desde el cursor), escribiendo: Persona[], leido_hasta, cursor, no_leidos: {sala: n}, presencia: {id: Presencia}}` |
| POST | `/v1/chat/salas/{id}/mensajes` | JSON `{texto, responde_a?}` o multipart `texto, responde_a, adjuntos[]` | 201 `{mensaje}` |
| PATCH | `/v1/chat/mensajes/{id}` | `{texto}` (autor) | `{mensaje}` |
| DELETE | `/v1/chat/mensajes/{id}` | (autor; borra también los ficheros) | `{mensaje}` (borrado) |
| POST | `/v1/chat/mensajes/{id}/reacciones` | `{emoji}` (alterna la mía) | `{mensaje}` |
| POST | `/v1/chat/salas/{id}/escribiendo` | | `{}` (marca 6 s) |
| POST | `/v1/chat/salas/{id}/leido` | `{hasta}` | `{}` |
| GET | `/v1/chat/adjuntos/{mensajeId}/{indice}` | `?dl=1` fuerza descarga | **el fichero** (no JSON), solo a miembros de la sala; 404 si no |
| GET | `/v1/chat/avisos` | `?despues=<id>&activo=0\|1` (sin `despues`: línea base) | `{mensajes: [{id, sala_id, autor_id, autor, foto, texto, grupo, sala}], max, no_leidos, silenciado}` |
| GET | `/v1/presencia` | `?activo=` | `{presencia: {id: Presencia}}` del equipo activo |

```
Sala = {id, tipo:'dm'|'grupo', nombre, miembros: Persona[], otro_id|null, creado_por|null,
        ultimo: {id, texto, autor_id, autor, creado, borrado, adjunto}|null, no_leidos}
Mensaje = {id, sala_id, autor_id, autor, foto, texto (crudo: el front lo pinta sin HTML), creado, editado, borrado,
           responde_a: {id, autor, extracto}|null,
           adjuntos: [{indice, nombre, imagen, mime, tamano|null, url ('/api/v1/chat/adjuntos/…')}],
           reacciones: [{emoji, total, mio, personas: string[]}]}
Presencia = {estado:'online'|'idle'|'offline', texto}
```

- **Sondeo eficiente**: la sala abierta pide `novedades` (2,5 s) con el último id y el `cursor` de la respuesta
  anterior; `chat_messages.cambiado_en` (migración 0060) hace que solo lleguen los mensajes cambiados (el antiguo
  reenviaba 150 en cada sondeo). El latido de presencia solo escribe si el anterior tiene más de 15 s.
- **Avisos**: cada mensaje deja (y sustituye) un aviso `chat` por sala y persona (`/chat/<sala>`), silenciable;
  una `@mención` en un grupo deja además un aviso `mencion` («te ha mencionado en «Sala»», no silenciable).
  `silenciado` del avisador = la persona silenció el chat (`notifmute_<id>`): el avisador no suena ni salta.
- **Adjuntos**: tipo comprobado en el contenido (finfo), nombre aleatorio de 96 bits, 25 MB por fichero y 10 por
  mensaje (422 con el nombre si alguno no vale: imágenes, PDF, Office, txt/csv, zip/rar, audio y vídeo; nunca
  svg/html/php), imágenes reducidas y sin EXIF, en `uploads/chat/` (con `.htaccess` que lo niega todo) y servidos
  solo por la API. Los adjuntos antiguos (`{fn, orig, img}`) siguen funcionando.

## Soporte — `ver.soporte` (+ alcance)

Alcance: sin `alcance.todos`, los tickets de tus clientes **y** los que tienes asignados o has abierto tú
(el antiguo escondía todos los que no tenían cliente). Fuera de alcance = 404.
Responder y cambiar el **estado**: `soporte.responder`. Crear, prioridad, asignar, cliente y borrar: `general.editar`.

| Método | Ruta | Cuerpo / query | Respuesta |
|---|---|---|---|
| GET | `/v1/soporte/tickets` | `?estado=abierto\|en_curso\|esperando\|resuelto\|cerrado&cliente=N` (`resuelto` = resuelto+cerrado) | `{items: Ticket[], contadores: {abierto, en_curso, esperando, resuelto, total}, cliente: {id, nombre}\|null, puede_editar, puede_responder}` |
| POST | `/v1/soporte/tickets` | `{asunto*, cuerpo, prioridad 1-4, assignee_id, client_id}` | 201 `{ticket}` (avisa al asignado: `notif_ticket_assigned`) |
| GET | `/v1/soporte/tickets/{id}` | | `{ticket, respuestas: [{id, autor: Persona\|null, cuerpo, creado}], puede_editar, puede_responder}` |
| PATCH | `/v1/soporte/tickets/{id}` | `{estado?, prioridad?, assignee_id?, client_id?}` | `{ticket}` (avisa si cambia el asignado) |
| POST | `/v1/soporte/tickets/{id}/respuestas` | `{cuerpo}` | 201 `{respuesta, ticket}` (avisa a quien lo lleva) |
| DELETE | `/v1/soporte/tickets/{id}` | | `{papelera_id}` (con sus respuestas) |
| POST | `/v1/soporte/papelera/{id}/restaurar` | | `{id}` («Deshacer») |
| GET | `/v1/soporte/clientes` | | `{items: [{id, nombre, activo}]}` (dentro del alcance) |

`Ticket = {id, asunto, cuerpo, client_id, cliente, prioridad, estado, asignado: Persona|null, creador: Persona|null,
desde_portal, creado, actualizado, respuestas}`. Orden: estado (abierto→cerrado), prioridad desc, actualizado desc.

Para el **Portal**: `(new SoporteServicio(new SoporteRepositorio($pdo), $c->equipo()))->crearDesdePortal($clientId,
$asunto, $cuerpo, $nombreCliente)` crea el ticket (`created_by NULL`) y avisa a quien tiene acceso total
(el antiguo no avisaba).

## Calendario — `ver.agenda`

Los eventos son de **tu** Google Calendar: crear, editar, mover y borrar no exige `general.editar` (como el
antiguo). Las tareas salen dentro de tu alcance y solo con `ver.tareas` (el antiguo las enseñaba todas).
Conectar/desconectar Google: endpoints de Equipo (`GET /v1/integraciones/google-calendar/conectar?volver=/calendario`,
`DELETE /v1/integraciones/google-calendar/conexion`).

| Método | Ruta | Cuerpo / query | Respuesta |
|---|---|---|---|
| GET | `/v1/calendario` | `?desde=&hasta=` (≤100 días) `&equipo=2,3` | `{desde, hasta, tareas: [{id, titulo, estado, fecha, client_id, cliente, asignados: Persona[]}], eventos: Evento[], festivos: {fecha: nombre}, google: {configurado, conectado, revocado, email}, companeros: [{id, username, color}], puede_configurar, avisos: string[]}` |
| POST | `/v1/calendario/eventos` | `{titulo*, fecha*, hora? (''=todo el día), hora_fin?, duracion? (min), fecha_fin?, invitados (texto o lista), ubicacion, descripcion, recur ''\|DAILY\|WEEKLY\|MONTHLY, recordar (''=Google\|'no'\|minutos), meet, gemini, notificar}` | 201 `{msg, evento}` |
| PATCH | `/v1/calendario/eventos` | `{id, …lo que cambie}` · mover/redimensionar: `{id, solo_fechas:true, fecha, hora, hora_fin, fecha_fin?}` · mover toda la serie desde una ocurrencia: lo mismo con `id = serie_id` y `origen: {fecha, hora, hora_fin, fecha_fin}` (dónde estaba la ocurrencia: la serie se desplaza lo mismo) | `{msg, evento}` |
| DELETE | `/v1/calendario/eventos?id=&avisar=1` | | `{msg}` |
| GET | `/v1/calendario/correos` | | `{items: [{email, nombre, tipo:'equipo'\|'contacto'\|'cliente'}]}` (autocompletado de invitados, con alcance) |

`Evento = {id, titulo, dia, dia_fin, hora, hora_fin, todo_el_dia, editable, invitados: string[], ubicacion,
descripcion, meet, meet_url, recurrente, serie_id, link, docs: [{titulo, url}], recordar, erp_meeting, color, mio,
owner: {id, username}|null}`. «Toda la serie» = usar `serie_id` como `id`. Errores de Google: `409
google_sin_conectar|google_sin_configurar|google_revocado`, `502 google|google_red`.

## Reuniones — `ver.agenda` (escribir: `general.editar`)

| Método | Ruta | Cuerpo / query | Respuesta |
|---|---|---|---|
| GET | `/v1/reuniones` | `?vista=me\|all\|<adminId>` (−90/+60 días) | `{google, cuentas: [{id, username}], vista, proximas, pasadas, notas: Reunion[], solicitudes: Solicitud[], meeting_url, puede_editar, avisos}` |
| POST | `/v1/reuniones` | **Agendar** (sustituye a `agendar.php`): `{titulo*, fecha* (ISO o dd/mm/aaaa), hora, duracion\|hora_fin, invitados, meet (def. sí), gemini, notificar, recordar, descripcion, contact_id?, req_id?}` | 201 `{msg, mid\|null, evento\|null, google}` |
| PATCH | `/v1/reuniones/contacto` | `{event_id, contact_id\|null}` | `{}` (asignación manual) |
| GET | `/v1/reuniones/contactos` | `?q=` | `{grupos: [{grupo, items: [{id, nombre, empresa, fase, email}]}]}` (Clientes activos, Potenciales, En pausa, Cerrados perdidos, Otros) |
| GET | `/v1/reuniones/destinatario` | `?cli=&contacto=` (opcionales) | `{destinatario: {nombre, email, whatsapp, contact_id, client_id, meeting_url, google}}` para prellenar «Agendar» |
| GET | `/v1/reuniones/solicitudes` | | `{items: Solicitud[]}` pendientes del portal |
| POST | `/v1/reuniones/solicitudes/{id}/aprobar` | `{evento?: {…como agendar}}` | `{msg, mid, evento, google}` |
| POST | `/v1/reuniones/solicitudes/{id}/rechazar` | | `{msg}` |

`Reunion = Evento + {owner: Persona|null, contacto: {id, nombre, empresa, client_id}|null, cliente: {id, nombre}|null,
emparejado: 'manual'|'contacto'|'cliente'|null}` (asignación manual > correo de un contacto > correo de facturación
de un cliente; solo lo que está en tu alcance). `Solicitud = {id, client_id, cliente, email, contact_id, fecha_deseada,
franja, motivo, creado}`.

Agendar con contacto: `crm_meetings` (ahora **con** su título), actividad `reunion` y «último contacto» = hoy; con
Google conectado, evento enlazado (`extendedProperties.private.erp_meeting`). Sin contacto ni Google: 409 (no se
guardaría nada). Con `req_id`: marca la solicitud `aprobada` y, si no hay invitados, invita al correo de facturación.
Para el CRM (notas de Gemini de una reunión): `GoogleCalendario::deReunionErp($adminId, $meetingId)` → `Evento|null`.

## Servidor MCP

`POST /api/v1/mcp?k=<token>` (también `Authorization: Bearer`). JSON-RPC 2.0, mismo contrato que `admin/mcp.php`
(`initialize`, `ping`, `tools/list`, `tools/call`; herramientas `listar_clientes`, `listar_equipo`, `listar_listas`,
`listar_tareas`, `crear_tarea`, `actualizar_tarea`, `etiquetar_tarea`, `comentar_tarea`). Requiere
`settings.mcp_enabled='1'` y `mcp_token` (comparado con `hash_equals`). Actúa como `settings.mcp_actor` (id) o la
primera persona activa con acceso total, **con sus permisos y su alcance**; crea y cambia tareas con
`TareasServicio` (mismas validaciones y avisos) y deja `audit_log` `mcp.<herramienta>`. `GET` → 405.
**Pendiente del Kernel**: hoy exige CSRF a todo POST (también público) → hay que eximir esta ruta.

## Cron

`Croilab\Modulos\Comunicacion\Cron::ejecutar(PDO $pdo): array` — borra marcas de «escribiendo» caducadas,
sincroniza los avisos de solicitudes de reunión (`notif_sync_meeting_requests`) y avisa una vez de los tickets
abiertos sin asignar desde hace más de 24 h. Devuelve `{escribiendo_borrados, solicitudes, tickets_sin_asignar}`.

## Contador para el menú

`(new ChatServicio(…))->noLeidos($adminId)` / `ChatRepositorio::noLeidosTotal($adminId)`: mensajes de otros sin
leer en todas mis salas (para `chat_no_leidos` en `/v1/nav`).
