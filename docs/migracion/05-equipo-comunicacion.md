# 05 · Equipo, ajustes y comunicación — especificación del legado

Ámbito: `copia-erp/admin/` → pantallas de equipo (team, team-edit, team-delete, perfil, permisos), ajustes (settings + `lib/ajustes_nav.php`, integraciones), bóveda de credenciales, chat, soporte, reuniones, calendario, agendar, actas, IA, MCP, registro por enlace, reset de contraseña, login con Google y sus callbacks, `lib/reauth.php`, `lib/signup.php`, `lib/gcal.php`, `lib/google_metrics.php` (parte OAuth).

Fuentes: `copia-erp/admin/*.php` (legado), `backend/admin/lib/*` (las mismas librerías ya endurecidas: `boveda.php`, `credenciales.php`, `gcal.php` con bóveda), `backend/API.md`, `backend/database/migrations/*`, `front/src/app/App.tsx`.

Convenciones del legado que aplican a todas las pantallas:

- Todas exigen sesión (`require_admin()`) salvo `registro.php`, `reset.php`, `google_login.php`, la rama de login de `gcal_callback.php` y `mcp.php` (token).
- `require_admin()` aplica `perm_de_pagina(basename)` (permiso de pantalla) y `perm_de_accion(basename, $_POST['action'|'section'])` (permiso de acción). Además las páginas usan `can_edit()` (= `can('general.editar')`) e `is_owner()` (= `can('admin.total')`).
- CSRF: `auth.php` valida `_csrf` en todo POST; `erp_nav.php` lo inyecta en formularios (MutationObserver) y en `fetch` (cabecera). En la API nueva: cabecera `X-CSRF-Token`.
- UI común: `toast(msg[, 'err'])`, `erpConfirm(msg,{titulo,ok,danger})`, `erpAsk(msg,{titulo,ok,post,data,danger})` (confirma y hace POST de un formulario), `erpSubmitAsk(form,msg,opts)`, `erpPrompt(titulo,valor,{msg,placeholder,ok})`, `erpEmojiPicker(btn, cb, textarea)`, `avatar_color(nombre)` (color estable por nombre), `ic(nombre,px)` iconos, `svc_logo('google'|'gcal'|'meet'|'gemini'|'n8n'|'claude', px)` logos reales.
- Ficheros subidos: `uploads/{avatars,chat,...}`, servidos solo por `archivo.php?d=<carpeta>&f=<fichero>[&dl=1]` (exige sesión de admin; no comprueba pertenencia).

Permisos relevantes (catálogo `perm_catalogo()`):

| Clave | Etiqueta | Uso en este ámbito |
|---|---|---|
| `ver.chat` | Chat de equipo | pantalla chat.php |
| `ver.soporte` | Soporte | support.php |
| `soporte.responder` | Responder tickets | existe en el catálogo pero **support.php no lo usa** (usa `can_edit()`) |
| `ver.agenda` | Calendario | calendar.php, reuniones.php, agendar.php |
| `ver.actas` | Ver Actas | actas.php (escribir: `general.editar`) |
| `ver.ia` | Asistente IA | ia.php |
| `ver.ajustes` | Gestión y ajustes | settings.php, integraciones.php, servicios, tipos, agencias, papelera, metricas |
| `ver.credenciales` | Bóveda de credenciales | credenciales.php |
| `ajustes.editar` | Ajustes de la agencia | POST de settings.php (salvo emisores) |
| `finanzas.emisores` | Datos fiscales propios | settings.php `section=emisores|em_del` |
| `integraciones.editar` | Integraciones | todo POST de integraciones.php |
| `equipo.gestionar` | Gestionar el equipo | team.php, team-edit.php, team-delete.php |
| `roles.gestionar` | Gestionar los roles | permisos.php |
| `datos.avanzado` | Datos avanzados | data.php (+ reauth) |
| `general.editar` | Guardar cambios | `can_edit()` en casi todos los POST |
| `admin.total` | Dueño · acceso total | `is_owner()`; puede todo |

---

## 1. Modelo de datos

### 1.1 `admins` (equipo)
Migración nueva `0001`: `id, username VARCHAR(80) UNIQUE, password_hash, email VARCHAR(190) NULL, role VARCHAR(30) DEFAULT 'editor', activo TINYINT DEFAULT 1, cred_ver INT DEFAULT 1, password_changed_at DATETIME NULL, es_autonomo TINYINT, tarifa_hora DECIMAL(10,2), iva_pct DECIMAL(5,2), irpf_pct DECIMAL(5,2), created_at`.
- `email` = identidad de «Entrar con Google» (se compara `LOWER(email)`); debe ser único (validado en código, no hay índice único).
- `role` = clave de `roles.clave`.
- `activo` y `cred_ver` solo existen en el esquema nuevo (el legado borra físicamente; ver §3.3).

### 1.2 `admin_profiles` (ficha social)
`admin_id PK, cargo VARCHAR(120), departamento VARCHAR(80), telefono VARCHAR(60), ubicacion VARCHAR(120), web VARCHAR(160), skills VARCHAR(300) (CSV), cumple DATE NULL, bio TEXT, foto VARCHAR(160) (nombre de fichero en uploads/avatars), updated_at`.

### 1.3 `roles`
`clave VARCHAR(30) PK, nombre VARCHAR(60), descripcion VARCHAR(255), permisos TEXT (lista), sistema TINYINT, orden INT, created_at`. Sistema: `owner` (Dueño), `editor` (Editor), `viewer` (Solo lectura); no se pueden borrar. Ya modelado en `backend/admin/lib/permisos.php`.

### 1.4 `settings` (clave/valor, `clave VARCHAR(60) PK, valor TEXT`)
Claves de este ámbito:

| Clave | Contenido | Dónde se escribe |
|---|---|---|
| `agency_name, agency_email, agency_phone, agency_web, agency_address, agency_cif, agency_logo (URL), agency_color (#hex)` | Identidad de la agencia | settings `agency` (solo Dueño) |
| `emisor_<k>_{name,nif,dir,email,phone,banco,iban,iva,irpf,venc}`, `emisor_<k>_serie`, `emisor_por_defecto` | Emisores de facturas (la lista la gestiona `lib/fin_prog.php`) | settings `emisores` (Dueño). Detalle en la spec de finanzas |
| `meeting_url, whatsapp, email` | Contacto del portal del cliente | settings `portal` |
| `video_id` | Vídeo YouTube de presentación del portal (los vídeos por servicio viven en el catálogo de servicios, `svc_guardar`) | settings `videos` |
| `auto_<clave>` (`'0'` = apagada; ausente/otro = encendida) | Reglas de cron: `lead_reminder, invoice_due, monthly_report, invoice_recurring, followups, daily_digest, trash_purge, uploads_sweep` | settings `auto_toggle` |
| `notifmute_<adminId>` | CSV con `chat` y/o `avisos` | perfil.php `notif` |
| `api_token` | 40 hex. Cabecera `X-API-Token` para `/api.php` (n8n) | integraciones `api` |
| `mcp_enabled` (`'1'`/`'0'`), `mcp_token` (40 hex) | Servidor MCP | integraciones `mcp` |
| `gcal_client_id`, `gcal_client_secret` | Cliente OAuth del proyecto (Calendar **y** login con Google) | integraciones `gcal` |
| `gcal_tok_<adminId>` | JSON `{access_token, refresh_token, expiry, email}` cifrado | gcal_callback |
| `gcal_revoked_<adminId>` | `'1'` si Google devolvió `invalid_grant` | `gcal_access_token()` |
| `gcal_key` | (solo legado) clave AES generada si `APP_SECRET` es el de ejemplo | gcal.php legado |
| `google_oauth_client_id`, `google_oauth_client_secret`, `google_oauth_refresh_token` | OAuth de «Google · Métricas» (Search Console + GA4). **El refresh token va en claro**, también en `backend/admin/lib/google_metrics.php` | integraciones `gmet`, gmet_callback |
| `signup_<token48hex>` | `<rol>|<caducaUnix>` (48 h, un uso) | team.php `gen_signup` |
| `pwreset_<token48hex>` | `<adminId>|<caducaUnix>` (48 h) — **eliminado** por la migración `0004` (sustituido por `password_resets`) | team-edit `link_pass` |

### 1.5 Chat
```
chat_rooms    (id PK, name VARCHAR(120) '', type VARCHAR(10) 'group'|'dm', created_by INT, created_at)
chat_members  (room_id, admin_id, last_read INT DEFAULT 0  -- id del último mensaje leído; PK(room_id,admin_id))
chat_messages (id PK, room_id, admin_id, body TEXT, created_at, reply_to INT NULL, edited TINYINT, deleted TINYINT,
               attach TEXT NULL -- JSON [{fn, orig, img:0|1}], INDEX(room_id))
chat_typing   (room_id, admin_id, until_ts INT -- unix; PK(room_id,admin_id))
chat_reactions(message_id, admin_id, emoji VARCHAR(16), created_at; PK(message_id,admin_id,emoji))
chat_presence (admin_id PK, last_seen DATETIME, last_active DATETIME)   -- ya en migración 0001
```
Las tablas `chat_*` (salvo presence) las crea `chat.php` en cada petición con `CREATE TABLE IF NOT EXISTS`; **no están en las migraciones nuevas** → crear migración.

### 1.6 `notifications` (compartida, ya en migración 0001)
`id, admin_id, tipo ('info','chat','ticket','factura','bell','file','inbox'…), titulo, cuerpo, url, ref (dedupe, UNIQUE(admin_id,ref)), tarea, actor, leido, snooze_until, borrado, bandeja ('principal'|'otras'), created_at`.
`notif_add()` respeta `notifmute_<id>`: tipo `chat` → categoría `chat`; tipos `bell,file,inbox,info` → categoría `avisos`; el resto (p. ej. `ticket`) siempre llega. La campana cuenta `tipo<>'chat'`.

Refs usados aquí: `chat:<roomId>:<adminId>` (uno vivo por sala y persona), `tkassign:<ticketId>:<adminId>`, `meetreq:<reqId>`, `bday:<adminId>:<fecha>`, `pwreq_<adminId>_<Ymd>`, `report:<Y-m>`.

### 1.7 Soporte
```
support_tickets (id, asunto VARCHAR(200) NOT NULL, cuerpo TEXT, client_id NULL, prioridad INT DEFAULT 2 (1 Baja·2 Normal·3 Alta·4 Urgente),
                 estado VARCHAR(20) 'abierto'|'en_curso'|'esperando'|'resuelto'|'cerrado', assignee_id NULL,
                 created_by NULL (NULL = lo creó el cliente desde el portal), created_at, updated_at ON UPDATE)
support_replies (id, ticket_id, admin_id, cuerpo TEXT, created_at, INDEX(ticket_id))
```
El portal del cliente (`copia-erp/index.php`) crea tickets (`portal_action=ticket`) y lista los suyos con nº de respuestas, pero **no ve ni escribe respuestas**.

### 1.8 Reuniones
```
reunion_cliente (event_id VARCHAR(255) PK -- id de evento de Google, contact_id INT NULL, created_at)   -- asignación manual evento→contacto CRM
portal_meeting_requests (id, client_id, fecha_deseada DATE NULL, franja VARCHAR(30), motivo TEXT,
                         estado 'pendiente'|'aprobada'|'rechazada', meeting_id INT NULL -> crm_meetings.id, created_at)
crm_meetings (id, contact_id NOT NULL, fecha, hora, titulo, estado 'agendada', notas, notas_doc, created_at)  -- del CRM
```
Enlace evento Google ↔ reunión CRM: `extendedProperties.private.erp_meeting = <crm_meetings.id>`.

### 1.9 Bóveda de credenciales de clientes
```
client_credentials (id, client_id, titulo VARCHAR(160), categoria 'web'|'correo'|'hosting'|'database'|'api'|'cms'|'domain'|'social'|'other',
                    usuario VARCHAR(255), secreto TEXT, url VARCHAR(500), nota VARCHAR(500), visible_cliente TINYINT, orden INT, created_at)
```
**`secreto` se guarda en claro** en el legado. `backend/admin/lib/boveda.php` ofrece cifrado (`boveda_guardar/leer/valor($clave,$proposito)`, AES con HKDF por propósito, clave en fichero `.croilab-boveda` fuera del docroot o env `BOVEDA_CLAVE`), pero hoy solo lo usan los tokens de Google Calendar.

### 1.10 Otras
- `partner_agencies` (marca blanca): `id, nombre, logo_url, color, web, email, whatsapp, meeting_url, telefono, created_at`; `clients.partner_id`. Gestionada en `agencias.php` (fuera de este ámbito); `marca_agencia()` da `{name, logo, color…}` usado en registro/reset/IA.
- `actas`: `id, titulo VARCHAR(220), contenido MEDIUMTEXT (formato rt_editor), admin_id, pinned TINYINT, created_at, updated_at`.
- Nuevas (backend): `password_resets` (token SHA-256, 60 min), `password_history`, `sessions`, `login_attempts`, `audit_log`.

---

## 2. Marco de Ajustes (`lib/ajustes_nav.php`)

Propósito: un único menú lateral de configuración para settings.php y las pantallas de ajustes con página propia.

`aj_menu()` (zonas → entradas `[clave, icono, etiqueta, destino]`), filtrado por `perm_de_pagina` del destino:
- **Organización**: Agencia (`settings.php?tab=agency`), Facturación (`?tab=facturacion`), Mi equipo (`team.php`), Roles y permisos (`permisos.php`), Servicios (`servicios.php`).
- **Clientes**: Clientes en alta (`index.php?ctx=aj`), Tipos de cliente (`types.php`), Bóveda de credenciales (`credenciales.php`).
- **Portal de clientes**: Contacto (`?tab=contacto`), Vídeos (`?tab=videos`), Métricas de Google (`metricas.php`), Marca blanca (`agencias.php`).
- **Sistema**: Reglas automáticas (`?tab=reglas`), Integraciones (`integraciones.php`), Papelera (`papelera.php`), Datos (avanzado) (`data.php`, solo con `datos.avanzado`).

`aj_head($clave, $titulo, $sub, $accionHtml)`: cabecera `h1` (título = etiqueta del menú si no se pasa), párrafo descriptivo, buscador «Buscar un ajuste…» y zona de acción a la derecha.

`aj_foot()` emite JS del marco:
1. **Cambios sin guardar**: cada `form` dentro de `.set-main` con campos visibles guarda una «foto» (name=valor, sin hidden/disabled); si cambia, añade clase `aj-dirty` y un rótulo «Sin guardar» junto al botón; `beforeunload` avisa.
2. **Guardar sin recargar**: formularios con clase `aj-save` se envían por `fetch` (FormData); éxito → toast «Guardado»; 403 → «No tienes permiso para guardar esto.»; otro error → «El servidor ha respondido N».
3. **Buscador**: índice `aj_indice()` (≈45 entradas «palabras → apartado → campo»), sin tildes ni mayúsculas, ≥2 caracteres, máx. 10 resultados, flechas/Enter/Escape; «Nada con «q».». Al elegir: si es un panel de settings.php cambia de panel sin recargar (`setTab`) y resalta el campo (`aj-ping` 2,4 s); si es otra página navega con `?ir=<campo>`.
4. `setTab(t)`: `history.pushState`, alterna `.set-panel.on`, mueve la marca del menú; `popstate` recarga.

React: componente `AjustesLayout` con el menú (zonas filtradas por permisos de `me.permisos`), buscador con índice estático en el front y un hook `useFormularioSucio`.

---

## 3. Equipo

### 3.1 `team.php` — Mi equipo
- **URL**: `team.php` (`#registro` ancla el bloque de enlaces). Permiso `equipo.gestionar`.
- **Cabecera**: h1 «Mi equipo»; lead «Quién entra al panel y qué puede hacer · N persona(s) con acceso · M cliente(s) de alta.»; botones «Roles y permisos» (ghost, → permisos.php) y «+ Nuevo miembro» (→ team-edit.php).
- **Lista** (tarjeta blanca, borde, radio 16): filas en rejilla `40px | 1fr | 220px | 132px`, padding 15/20, hover `#fafbfc`. Orden `ORDER BY role, username`.
  - Col 1: avatar circular 38 px con inicial y `avatar_color(username)`, enlace a `perfil.php?id=`; si el rol tiene `admin.total`, insignia ámbar `#e8a33d` abajo-derecha (icono usercheck) title «Administrador · acceso total».
  - Col 2: nombre (600, 14.5px) + «tú» si es uno mismo + etiqueta «Admin» (pastilla ámbar) si acceso total; subtítulo: correo o «Sin correo · no puede entrar con Google», «· desde YYYY-MM», «· 🎂 d mmm» si tiene cumpleaños (de `admin_profiles.cumple`).
  - Col 3: `<select>` de rol (todos los roles, nombre visible), title = descripción del rol. **Guarda al cambiar**.
  - Col 4: acciones (visibles en hover; siempre en móvil): Ver su perfil, Escribirle por el chat (`chat.php?u=<id>` — **bug: chat.php espera `?dm=`**), Editar sus datos y su contraseña (team-edit), Quitar del equipo (no para uno mismo; confirm «¿Eliminar a X del equipo? Perderá el acceso al panel.», título «Eliminar del equipo», POST a team-delete.php `{id}`).
  - Pie: «¿Quieres cambiar **qué puede hacer** un rol, o crear uno nuevo? Se hace en Roles y permisos.»
  - Responsive: ≤860 apila; ≤640 compacta (avatar 34, rol+acciones en una línea).
- **Bloque «Registro por enlace»** (`#registro`): h2 «Registro por enlace»; texto «Genera un enlace temporal para que alguien cree su propia cuenta. **Solo funciona con el enlace que generes tú**, es de un solo uso y caduca a las 48 h.»; formulario «Entrará como» [select de roles sin `admin.total`, por defecto `viewer`] + «Generar enlace». Lista de enlaces vivos: icono, «Enlace de registro [ROL]», «Un solo uso · caduca en ~N h», botón «Copiar enlace» (clipboard → toast «Enlace copiado»), papelera (confirm «¿Anular el enlace?» / «Quien tenga este enlace ya no podrá registrarse.»). Vacío: «No hay ningún enlace activo. Genera uno cuando quieras dar de alta a alguien.»
- **Acciones POST**:
  - `action=set_rol, uid, rol` → JSON `{ok:0|1, msg, recargar:0|1}` (400 si falla). Usa `rol_asignar()`: rol inexistente → «Ese rol no existe.»; quitar el último con acceso total → «Es la única persona con acceso total. Dale ese rol a alguien más antes de quitárselo.»; `recargar=1` si te lo cambias a ti mismo. JS: revierte el select si falla, toast «Rol actualizado» / msg de error, actualiza insignia y etiqueta Admin sin recargar.
  - `action=gen_signup, rol` → si el rol no existe o tiene `admin.total` se fuerza `viewer`; `signup_crear()` → settings `signup_<token>`; redirige `team.php#registro`.
  - `action=del_signup, token` → borra; redirige.
- URL del enlace: `<esquema>://<host><dir admin>/registro.php?t=<token>`.

### 3.2 `team-edit.php` — Ficha / alta de miembro
- **URL**: `team-edit.php` (alta) · `team-edit.php?id=N[&ok=1|horas|setpass|nolink|err|link|pass]`. Permiso `equipo.gestionar`. Id inexistente → redirige a team.php.
- **Modo edición — cabecera**: migas «← Mi equipo / nombre»; avatar grande con inicial (+ corona si acceso total); nombre; meta: pastilla de rol (ámbar si total), correo o «Sin correo de Google»; botón «Ver su perfil» (`perfil.php?id=N&from=roles`).
- **Avisos**: errores en lista `• …`; ok: `1` «Datos guardados.», `horas` «Datos de facturación guardados.», `setpass` «Contraseña cambiada. Es la que acabas de escribir.», `nolink` «Enlace anulado.», `err` «No se ha podido crear el enlace.».
- **Contraseña generada** (una vez, vía `$_SESSION['team_newpass']`): «Contraseña nueva de X», `<code>`, «Cópiala y pásasela. **No se vuelve a mostrar**: a partir de ahora solo está guardada cifrada.»
- **Enlace vivo**: «Enlace activo para que X elija su contraseña» · «Pásaselo por chat o WhatsApp. Caduca el dd/mm/aaaa a las HH:MM y solo sirve una vez.» · `<code>` clic-copia · botón «Anular el enlace».
- **Bloque 1 «Datos de acceso»** (form `guardar`): «Con esto entra al panel. Puedes cambiárselo cuando quieras, sin que tenga que hacer nada.» Campos: Usuario (req.), Correo de Google (hint «Con él puede entrar con «Entrar con Google», sin escribir contraseña.»), Rol (select; hint con descripción + «Lo que hace cada rol se decide en Roles y permisos.»). Pie «Guardar cambios» + «Hay cambios sin guardar» (detector de cambios por bloque).
  - Validación: usuario obligatorio «El usuario es obligatorio.»; email formato «El correo de Google no tiene un formato válido.»; usuario único «Ese usuario ya lo tiene otra persona.»; email único (LOWER) «Ese correo de Google ya está asignado a otra persona.»; cambio de rol vía `rol_asignar()`. Guarda `username`, `email` (NULL si vacío, minúsculas).
- **Bloque 2 «Su contraseña»** — «Tres formas de dejarle el acceso listo. Cualquiera de las tres anula un enlace pendiente, si lo hubiera.» Tres tarjetas:
  1. «Ponérsela tú» — input `nueva` (≥6, «La contraseña debe tener al menos 6 caracteres.») → `set_pass`: `password_hash`, `pwreset_limpiar`.
  2. «Generar una» — confirm «¿Generar una contraseña?» → `gen_pass`: `pu_password()` (contraseña legible, `lib/puentes.php`; fallback 8 hex), muestra una vez.
  3. «Que la elija X» — confirm «¿Crear el enlace?» → `link_pass`: `pwreset_crear` (48 h); texto botón «Crear enlace» / «Rehacer enlace». `quitar_link` anula.
  - Nota fija: «¿Y ver la contraseña que tiene ahora? No se puede…»
  - **Ninguna de estas vías sube `cred_ver`** en el legado (las sesiones abiertas siguen vivas); el backend nuevo tiene `credenciales_cambiar()` para esto.
- **Bloque 3 «Si factura sus horas»** (form `guardar_horas`): interruptor «Es autónomo y factura sus horas» («Las horas que se apunte pasan a la contabilidad con esta tarifa.»); campos Tarifa por hora (€), IVA (%), IRPF · retención (%), parseados con `num_es()` (coma decimal). Los campos se atenúan si no es autónomo pero se envían igual. Guarda `es_autonomo, tarifa_hora, iva_pct, irpf_pct`.
- **Bloque 4 «Dar de baja»** (no para uno mismo): «Quitarle el acceso a X» / «Deja de poder entrar al panel. Sus tareas y todo lo que haya hecho se queda donde está.» → mismo erpAsk que team.php.
- **Modo alta** («Nuevo miembro del equipo»): Usuario (req., autofocus), Correo de Google · opcional, Rol (por defecto `editor`), Contraseña, interruptor «Que se la ponga esta persona» (deshabilita contraseña; «Se crea un enlace de un solo uso para pasárselo. La cuenta no queda abierta mientras tanto.»). Botones «Crear acceso» / «Cancelar».
  - Validación: usuario obligatorio; sin enlace: «Pon una contraseña, o marca que se la ponga esa persona.» / ≥6; email formato; «Ese usuario ya existe.»; «Ese correo de Google ya está asignado a otro miembro.» (nótese: el alta **no** pasa por `rol_asignar` y permite asignar cualquier rol, incluido Dueño).
  - Con enlace: contraseña inicial aleatoria de 32 hex, crea pwreset y redirige a la ficha `?ok=link`; sin enlace → team.php.
- **Correo**: el legado **no envía ningún correo** (los enlaces se copian a mano). El backend nuevo ya tiene SMTP (`src/Correo`) → la versión React puede enviar el enlace por correo.

### 3.3 `team-delete.php` — Baja
- POST `id`. Permiso `equipo.gestionar`. No te puedes borrar (`msg=no-puedes-borrarte`). No borra al último con `role='owner'` (**compara el nombre `owner`, no `admin.total`**, inconsistente con `rol_asignar`) → `msg=ultimo-dueno`.
- Efectos (borrado físico): borra foto de `uploads/avatars`, fila `admin_profiles`, `settings.notifmute_<id>`, `chat_members` del usuario, `tasks.responsable_id=NULL`, `task_assignees`, y `admins`. Redirige `team.php?msg=miembro-eliminado` (**team.php no muestra `msg`**).
- No toca: `chat_messages` (quedan con autor «?»), `gcal_tok_<id>`, `gcal_revoked_<id>`, `pwreset_*`, notificaciones, tickets asignados, actas.
- Recomendación: en la API nueva usar `activo=0` + `cred_ver++` (baja lógica; `EquipoRepositorio::activos()` ya filtra `activo=1`) y limpiar tokens de Google.

### 3.4 `perfil.php` — Perfil social + centro de cuenta
- **URL**: `perfil.php?id=N` (sin id = yo) · `&edit=1` · `&modo=cuenta&tab=perfil|cuenta|notif` (solo si es uno mismo) · `&from=<sección>` (menú lateral a mantener) · `&f=perfil|cuenta|pass|notif` (aviso ok). Permiso: cualquiera con sesión.
- `canEditProfile` = es uno mismo, o `admin.total`, o `equipo.gestionar`.
- **Diseño** «minimal estilo Apple», columna centrada:
  - Enlace volver: a uno mismo → «Mi equipo» (team.php) si Dueño, si no «Inicio» (dashboard.php); a otro → «Volver» (chat.php).
  - Aviso ok «✓ Contraseña actualizada.» / «Cuenta actualizada.» / «Preferencias guardadas.» / «Perfil guardado.»; error en rojo.
  - **Hero**: avatar grande (foto o 2 iniciales) con punto de presencia (verde/naranja/gris) y title con el texto; nombre + «· Tú»; meta: punto + «En línea|Ausente|últ. vez hace X» · nombre del rol (**mapa fijo owner/editor/viewer; roles propios salen como «Miembro»**); tagline «cargo · departamento».
  - CTAs: «Mensaje» (`chat.php?dm=id`, no para uno mismo), «Email» (mailto si tiene), para uno mismo «Ajustes» (`&modo=cuenta`) o «Ver perfil» (en modo cuenta) y «Editar»; para un tercero editable «Editar · admin».
- **Modo edición** (`edit=1`, multipart, `sec=profile`): foto (clic en avatar o «Cambiar foto»; «Quitar» → `sec=photo_del`), vista previa local; secciones «Información»: Cargo / puesto (placeholder «Ej: Especialista SEO»), Departamento (select: Sin departamento, Dirección, Cuentas, SEO, Contenidos, Diseño, Desarrollo, Publicidad, Administración, Soporte; conserva un valor fuera de lista), Teléfono, Ubicación, Web / enlace, Cumpleaños (date); «Habilidades» (CSV, «Sepáralas por comas. Se muestran como etiquetas.»); «Sobre mí» (textarea). Botones Cancelar / Guardar cambios.
  - Guardado: upsert `admin_profiles` con recortes (120/80/60/120/160/300/2000). Foto: jpg/jpeg/png/gif/webp, ≤8 MB, valida con `getimagesize` y tipo real, nombre `a<id>_<8hex>.<ext>`, reduce a 800 px (`img_optimizar`), borra la anterior.
- **Vista** (sin modo cuenta solo pestaña Perfil):
  - «Contacto»: Email, Teléfono (`tel:`), Ubicación, Web (añade https://), Cumpleaños («d de mes»).
  - «Habilidades»: chips.
  - «Sobre mí/esta persona»: bio con saltos o «Aún no has escrito nada. Pulsa «Editar».» / «Todavía no ha rellenado su perfil.»
  - «Tareas que tienes / asignadas · N» + «Ver todas» (`workspace.php?view=mine` o `view=emp&emp=id`): tareas `responsable_id=id` no completadas, orden estado (en proceso, pendiente, atemporal), cliente, id; cada una con círculo de estado, título, «cliente · lista», fecha dd/mm/yy (roja si vencida). Vacío: «No tienes tareas pendientes. Todo al día.» / «No tiene tareas pendientes.» (usa solo `responsable_id`, ignora `task_assignees`).
- **Modo cuenta** (segmentado Perfil · Cuenta · Avisos; cambia `?tab` con `replaceState`):
  - **Cuenta** (`sec=account`): Nombre, Email → validación «El nombre no puede quedar vacío.», «El correo no tiene un formato válido.», «Ese nombre de usuario ya existe.», «Ese correo ya está asignado a otro miembro.». (Único sitio donde cada uno cambia su usuario/correo.)
  - **Contraseña** (`sec=password`): Actual / Nueva / Repetir → «La contraseña actual no es correcta.», «La nueva contraseña debe tener al menos 6 caracteres.», «Las dos contraseñas no coinciden.».
  - **Avisos** (`sec=notif`, autosubmit al tocar): «Silenciar» — «Chat de equipo» (Sonido y notificación del navegador) → `chat`; «Avisos y resúmenes» (Facturas, informes, resumen diario) → `avisos`. Pie «Las asignaciones y menciones de tareas llegan siempre.» Guarda `settings.notifmute_<id>`.
  - **Inconsistencia**: silenciar chat solo evita las filas de notificación; el avisador global (popup + bip + notificación del navegador, §5.4) **no consulta** la preferencia.

### 3.5 `permisos.php` — Roles y permisos (matriz)
- **URL**: `permisos.php`. Permiso `roles.gestionar`. Usa `aj_head('permisos', '', 'Marca lo que puede hacer cada rol. Se guarda solo, al momento.', botón «+ Nuevo rol»)`.
- **Layout**: matriz con columnas por rol (`--pm-n` = nº de roles). Fila de cabecera: «Permiso» + por rol un input con el nombre (editable en sitio: Enter/blur → renombrar; vacío o igual → revierte), etiqueta «Todo» (si `admin.total`) o «N pers.», papelera (solo roles no-sistema sin personas).
- Por cada grupo del catálogo («Qué módulos ve», «Clientes», «Trabajo del día», «CRM y ventas», «Dinero», «Configuración», «Hasta dónde ve», «Administración»): fila-cabecera con el nombre del grupo, etiqueta «Configuración» si la mayoría de sus permisos están en `perm_config()`, y por rol un botón «Todo»/«Quitar» (no en roles con acceso total). Debajo una tarjeta con filas: etiqueta en negrita + descripción, y por rol un interruptor. Roles con `admin.total` muestran todo marcado y deshabilitado; `admin.total` del rol `owner` fijo.
- Pie: «Para darle un rol a alguien, ve a Mi equipo · N personas con acceso.»
- **Acciones POST (JSON)**:
  - `toggle {rol, perm, on:'1'|'0'}` → `{ok, permisos:[…], recargar}`. Errores 400: «Ese rol ya no existe.», «Ese permiso no existe.», «Al rol Dueño no se le puede quitar el acceso total: …». Al apagar quita también `perm_dependientes(perm)`. `rol_guardar()` completa requisitos (`perm_completar`).
  - `grupo {rol, grupo, on}` → igual; excluye siempre `admin.total`. «Esa sección no existe.»
  - `crear {nombre}` → `{ok, clave}` (nace sin permisos; clave = slug ≤30 sin repetir).
  - `renombrar {rol, nombre}` → `{ok}`.
  - `borrar {rol}` → errores «Los roles Dueño, Editor y Solo lectura no se pueden borrar. Sí puedes cambiarles los permisos.», «No se puede borrar: hay N persona(s) con este rol. Cámbiales el rol antes.».
- **JS**: optimista; antes de encender comprueba `PM_REQ` (requisitos) y pregunta «Hacen falta otros permisos» / ««X» no sirve de nada sin «A» y «B». Se activarán también.» [Activar los N]; al apagar «Se apagarán otros permisos» / «Sin «X» no se puede usar … Se apagarán también.»; `admin.total` siempre pregunta «¿Darle acceso total?» con texto largo. Tras la respuesta `pmSync(rol, permisos)` repinta la columna con la verdad del servidor; toasts «Activados N permisos», «Rol renombrado», etc.; `recargar` → reload. Crear: `erpPrompt('¿Cómo se llama el rol nuevo?', {msg:'Nacerá sin permisos: se los marcas en su columna.', placeholder:'Ej: Comercial, Contable, Becario'})`.

### 3.6 Registro por enlace — `registro.php` + `lib/signup.php`
- Público. `GET registro.php?t=<token>`; POST con `t, username, email, p1, p2` (+CSRF).
- Token inválido/caducado: «Este enlace ya no vale» / «Los enlaces para crear una cuenta caducan a las 48 horas y solo se pueden usar una vez. Pídele otro a quien lleve el panel.»
- Formulario: logo/inicial de la marca, h1 «Crea tu cuenta», «Te unes al panel de <agencia> como [Rol]. Elige con qué entrar.», distintivo «Compatible con Entrar con Google»; Nombre de usuario («Con el que entrarás al panel»), Correo (opcional; «Pon tu correo de Google y podrás entrar con un clic con Entrar con Google.»), Contraseña / Repite la contraseña (ojo para ver; comprobación en vivo «Las dos coinciden» / «Las dos contraseñas no coinciden»); botón «Crear mi cuenta»; pie «Este enlace caduca a las 48 horas y solo sirve una vez.»
- Validación: usuario 2–80 («Escribe tu nombre de usuario (al menos 2 letras).», «El nombre de usuario es demasiado largo.»), correo válido («Ese correo no parece válido.»), ≥6, coinciden, usuario único («Ya hay alguien con ese nombre de usuario. Elige otro.»), correo único («Ya hay una cuenta con ese correo.»).
- Efecto: INSERT `admins(username, password_hash, role=<rol del token>, email)`; borra el token. Éxito: «Cuenta creada» / «Ya puedes entrar al panel de X con tu usuario Y…» / «Ir a la pantalla de acceso →».
- Sin throttle; tokens en claro en `settings`.

### 3.7 Reset por enlace — `reset.php` + `lib/pwreset.php` (legado)
- Público, `reset.php?t=<token>`: «Elige tu contraseña» / «Hola X. Pon la contraseña con la que quieras entrar al panel de <agencia>.»; p1/p2 ≥6; éxito «Contraseña guardada». Caducado: «Este enlace ya no vale».
- `pwreset_pedir($quien)` (desde «He olvidado mi contraseña» del login legado): busca por usuario o correo, crea notificación `info` «ha olvidado su contraseña» / «Entra en su ficha y ponle una nueva, o créale un enlace para que la elija.» → `team-edit.php?id=` a todos los que tienen `admin.total` o `equipo.gestionar`, una por día (`ref pwreq_<id>_<Ymd>`); respuesta siempre igual.
- **Ya sustituido** en la app nueva por `/auth/recuperar` + `/auth/restablecer` (SMTP, `password_resets`, 60 min, SHA-256). Lo que sigue faltando es el «enlace generado por el gestor» de team-edit (vía 3), que puede reutilizar `password_resets` con caducidad 48 h o enviar el correo directamente.

### 3.8 Re-autenticación — `lib/reauth.php`
- `reauth($zona, $titulo)`: si `$_SESSION['reauth'][$zona]` tiene menos de 30 min, sigue; si no, pinta cabecera + tarjeta «Confirma que eres tú» / «Que la sesión esté abierta no prueba que estés tú delante. Escribe tu contraseña para entrar.», chip con el usuario, campo contraseña con ojo, «Desbloquear», «← Volver a Ajustes», «Se queda desbloqueado 30 minutos.» y **termina la petición** (no genera el contenido). POST `__reauth`: `password_verify`; fallo → `usleep(400ms)` + «La contraseña no es correcta.» + log; éxito → redirect a la misma URL. Escape → settings.php.
- Único uso: `data.php` (`reauth('datos','Datos avanzados')`). `reauth_cerrar($zona)` para re-bloquear.
- API nueva: `POST /auth/reconfirmar {password, zona}` → marca en sesión (con TTL); los endpoints sensibles devuelven `403 {error:"reauth", zona}` si falta.

---

## 4. Ajustes

### 4.1 `settings.php`
- **URL**: `settings.php?tab=agency|facturacion|contacto|videos|reglas[&ok=…][&ir=campo]`. Alias: `emisores→facturacion`, `avanzado→api`, `portal→contacto`; `tab=cuenta` → `perfil.php?id=yo`; `tab=integr|api|gcal|mcp` → `integraciones.php[?i=…]`. Permiso `ver.ajustes`; POST: `ajustes.editar` (`finanzas.emisores` para `emisores`/`em_del`).
- Subtítulos: agency «Quién eres tú como agencia. Aparece en documentos internos y en la cabecera del portal del cliente.»; facturacion «Quién factura, con qué datos y con qué numeración. Cada autónomo lleva su contabilidad aparte.»; contacto «Por dónde te escriben los clientes desde su portal.»; videos «Qué vídeo ve el cliente en cada servicio de su portal.»; reglas «Lo que el ERP hace solo mientras trabajas: avisos, facturas recurrentes y seguimientos.»
- Tras POST: `settings.php?tab=<panel>&ok=<sección>` (o se queda en el panel si hay error).

**Panel Agencia** (form `aj-save`, `section=agency`, **solo Dueño**; el resto ve los campos deshabilitados y «Solo el Dueño puede cambiar la identidad de la agencia.»): título «Datos de la agencia». Zonas: «Identidad» (Nombre de la agencia [def. «Croilab»], CIF / NIF), «Cómo te localizan» (Email, Teléfono, Web, Dirección), «Tu marca» (Logo = URL de imagen «Si se deja vacío se usa la inicial del nombre.», Color de marca texto + `input type=color` sincronizados, «Solo lo usa el portal del cliente. El panel del equipo se queda en blanco y negro.»). Vista previa en vivo de dos maquetas: «Su pantalla de acceso» (marca grande, nombre, «Área de cliente», 2 campos falsos, botón «Entrar» del color) y «La cabecera de su portal» (marca chica, nombre, «Hola, María 👋»). Botón «Guardar datos». Ok: «Datos de la agencia guardados.»

**Panel Facturación** (emisores; detalle funcional en la spec de finanzas): segmentado por emisor (nombre + nº de facturas) + «+ Añadir» (Dueño; `erpPrompt('¿Quién va a facturar?')` → `nuevo[]` y submit). Por emisor: Nombre en el ERP (`ren[k]`), Prefijo (`emisor_k_serie`, ≤10), info «N facturas · Nº PFX-AAAA-001», quitar (solo sin histórico y si hay >1; `section=em_del, k`). Zonas «Datos fiscales · salen impresos en la factura» (Nombre/razón social, NIF/DNI, Dirección fiscal, Email, Teléfono), «Dónde te pagan» (IBAN, Banco), «Se rellena solo al crear una factura» (IVA [21], IRPF [0], Vencimiento [Contado]). Pie: «Guardar facturación», select «Por defecto» (si >1), nota «Cambiar el nombre no toca ninguna factura ya emitida. El prefijo tampoco: no lo cambies a mitad de año.» Tarjeta «Datos fiscales de los clientes» con badge «N sin datos» y botón a fin-ajustes.php. Mensajes ok `emisores|em_add|em_del|em_ren`; error de baja: «No se puede quitar a X: tiene N facturas, … Su histórico es contabilidad real y debe poder consultarse.», «Tiene que quedar al menos alguien que facture.».

**Panel Contacto** (`section=portal`, `can_edit`): «Contacto del portal» / «… Los clientes de marca blanca usan el contacto de su agencia si lo tiene configurado.» Campos: Email de contacto, WhatsApp («Sin el «+», con el prefijo del país.»), Enlace de reservas (Google Calendar · Horarios de citas) con ayuda («En Google Calendar → Crear → Horario de citas…»). Botón «Guardar contacto».

**Panel Vídeos** (`section=videos`): explicación del identificador de YouTube; «Vídeo de presentación» (`video_id`); zona «Un vídeo por servicio»: un campo `vid[i]` por servicio del catálogo (`svc_catalogo()`), en rejilla de 4. Guarda `video_id` y `svc_guardar([{nombre, video}])`. «¿Falta un servicio en esta lista? Créalo primero en Servicios…». Botón «Guardar vídeos».

**Panel Reglas automáticas**: tarjeta con «Ejecutar ahora» (`section=reglas`: `notif_sync_leads()`, `notif_sync_invoices()` y, si `auto_monthly_report≠0` y día ≤5, notificación «Prepara los informes del mes» a todos, ref `report:Y-m`; ok «Reglas ejecutadas. Revisa tus notificaciones.»). Rejilla de 8 reglas (icono, título, descripción, interruptor) — títulos: Recordar leads, Facturas vencidas, Aviso de informe mensual, Facturas recurrentes, Seguimientos del CRM, Resumen diario, Vaciar la papelera, Borrar archivos sin uso. Interruptor → `fetch section=auto_toggle&key=&on=` → JSON `{ok}` / 403 «No tienes permiso para cambiar las automatizaciones.»; toast «Regla activada|desactivada». Segunda tarjeta «Seguimientos del CRM» con enlace «Abrir Reporting del CRM» (automatizaciones.php).

### 4.2 `integraciones.php`
- **URL**: `integraciones.php[?i=api|gcal|gmet|mcp][&ok=…]`. Permiso `ver.ajustes`; todo POST `integraciones.editar` **y además** cada sección comprueba `is_owner()` (en la práctica solo Dueño).
- **Hub** (`aj_head('integr','Integraciones','Conecta el ERP con tus herramientas. Cada una se configura por separado.')`): 4 tarjetas con logo, título + insignia de estado, descripción y chevron:
  - n8n / API — «Activa» | «Sin token» — «Vuelca métricas, tareas o informes a n8n u otras herramientas con un token.»
  - Google Calendar — «Conectado» | «Configurado» | «Sin configurar» — «Ver tus reuniones de Google y crear/editar eventos desde el calendario del ERP.»
  - Google · Métricas — «Conectado» | «Falta autorizar» | «Sin conectar» — «Lee de Google las visitas, apariciones (Search Console) y conversiones (Analytics) para el portal de cada cliente.»
  - MCP · Claude — «Activo» | «Desactivado» — «Conecta Claude para que gestione tus tareas: crear, etiquetar, cambiar estado y comentar.»
- **Detalle API** (`?i=api`): «Token de API» — «Manda la cabecera `X-API-Token` al llamar a `/api.php`…»; token en caja clic-copia; «Regenerar token» (confirm «¿Regenerar el token? El anterior dejará de funcionar.») → `section=api` → `api_token = bin2hex(random_bytes(20))`; ok «Nuevo token generado.». Para Dueño, tarjeta «Datos avanzados» → data.php.
- **Detalle Google Calendar** (`?i=gcal`): hero «Conectado: <email>» o descripción. Form (Dueño) «Credenciales del proyecto» — «Cada usuario conecta su propia cuenta desde el Calendario; aquí solo van las credenciales del proyecto (una vez).» Campos Client ID, Client Secret (**se muestra el valor guardado en claro en el input**), «URI de redirección autorizada (cópiala en Google Cloud, tal cual)» readonly = `gcal_redirect_uri()`. `section=gcal` guarda `gcal_client_id/secret`. Tarjeta de instrucciones en 5 pasos (habilitar Google Calendar API, pantalla de consentimiento Externo + usuarios de prueba, ID de cliente Aplicación web, pegar URI…).
- **Detalle Google · Métricas** (`?i=gmet`): mensajes `gmet` «Credenciales guardadas.», `gmet_off` «Se ha desconectado Google.», `gmet_bad` «Ese archivo no parece el de Google (no encuentro el id y el secreto).», `conn` «¡Conectado con Google! Ya puedes traer los números.», `connerr` «No se ha podido conectar con Google. Inténtalo de nuevo.».
  - Conectado: «Estado» + «Conectado con Google», enlace a metricas.php («Actualizar ahora»), dirección de retorno readonly (`gm_redirect_uri()`), «Desconectar» (confirm) → borra `google_oauth_refresh_token` (conserva id/secreto).
  - No conectado (Dueño): «Conectar con Google — Se hace una sola vez…», dirección de retorno, subida de `.json` de Google (`oauth_json`, lee `web|installed.client_id/client_secret`) o `<details>` «…o pega los dos códigos a mano» (ID de cliente, Secreto de cliente; el secreto solo se sobrescribe si se escribe uno nuevo; placeholder «(ya guardado)»). Si hay id+secreto: botón «Conectar con Google» → `gm_oauth_url()`. Instrucciones (habilitar Search Console API y Google Analytics Data API…).
- **Detalle MCP** (`?i=mcp`): form Dueño «Conectar Claude al ERP» — «Claude podrá listar, crear, cambiar estado/prioridad/fecha, etiquetar y comentar tareas, y consultar clientes y equipo. **Solo tareas** — no toca facturación, credenciales ni ajustes, y no borra nada.»; checkbox «Activar el servidor MCP»; «Guardar»; «Regenerar token» (erpConfirm, añade `regen=1`). Si activo: «Conéctalo en Claude» — «En Claude: Ajustes → Conectores → Añadir conector personalizado. Nombre: «Croilab ERP». URL…» + URL `<base admin>/mcp.php/<token>` clic-copia + aviso rojo «⚠️ Esa URL con el token es como una contraseña. Si se filtra, pulsa «Regenerar token».» Guardado: genera token si falta o si `regen`; `mcp_enabled`.

### 4.3 `credenciales.php` — Bóveda de credenciales de clientes
- **URL**: `credenciales.php` (selector) · `credenciales.php?cli=N` · `&ctx=ops` conserva el menú de Tareas. Permiso `ver.credenciales`; POST requiere `can_edit()`.
- **Selector**: h1 «Bóveda de credenciales» (icono vault), «Elige un cliente para ver todos sus accesos (web, correo, hosting, API…).»; buscador «Buscar cliente…» (filtro local); rejilla de tarjetas: avatar 2 iniciales, nombre, «N credencial(es)».
- **Cliente**: migas «Bóveda / Cliente», h1 con nombre, «N credencial(es) guardadas.», botón «+ Registrar credencial». Barra: buscador «Buscar credenciales…» (título+usuario+url) y chips por categoría presentes con contador («Todas N», Web, Correo, Hosting, Base de datos, API / Token, CMS, Dominio, Redes, Otro). Rejilla de tarjetas:
  - Cabecera: icono de categoría, título, pastilla categoría, pastilla verde «👁 Visible» si `visible_cliente` (title «El cliente ve este acceso en su portal»), acciones Editar / Borrar (confirm «¿Borrar credencial?»).
  - Campo Usuario (`<code>` + copiar), Contraseña enmascarada «••••••••••••» con Ver/Copiar (**el secreto va en el HTML** como `data-v`), nota, enlace «Acceder al servicio» (`target=_blank`).
  - Vacío: «Sin credenciales todavía.» + «Registrar la primera».
- **Modal** «Registrar credencial» / «Editar credencial»: Título * («Ej: WordPress de la web»), Categoría, Usuario / email, Contraseña / token, URL de acceso, Nota, casilla «**Visible para el cliente** en su portal (Accesos). Deja sin marcar los accesos internos (FTP, base de datos, tokens…).». Guardar / Cancelar.
- **POST**: `add_cred {cli, id?, titulo, categoria, usuario, secreto, url, nota, visible_cliente}` (sin título no hace nada; insert/update con `client_id`) · `del_cred {cli, id}` (borrado físico, sin papelera). Redirige `credenciales.php?cli=`.
- Portal del cliente: `index.php` lista `visible_cliente=1` **con el secreto** en `PORTAL.accesos_vault`.
- Sin auditoría de lecturas; sin alcance por cliente (`alcance_sql` no se aplica).

---

## 5. Chat de equipo (`chat.php`)

- **URL**: `chat.php` (abre la sala con mensaje más reciente) · `?room=N` · `?dm=<adminId>` (busca/crea el directo y redirige a `?room=`) · `?ping=1&act=0|1&after=N` (endpoint JSON global). Permiso `ver.chat`. Enviar, abrir directo y leer: **todos los roles, incluido Solo lectura** (decisión explícita); crear grupo: `can_edit()`. Todas las acciones de sala comprueban `chat_is_member`.

### 5.1 Layout
Dos columnas (en móvil una sola: `ch-show-list` muestra la lista; botón «‹» vuelve).
- **Columna lista** (`.ch-list`): cabecera «Chat» + botón lápiz «Nuevo mensaje»; buscador «Buscar» (filtra por nombre + vista previa); lista de salas ordenada por último mensaje desc (salas vacías al final):
  - Grupo: avatar cuadrado-redondeado con 2 iniciales y `avatar_color('g'+id+nombre)`. Directo: avatar redondo con iniciales del otro y punto de presencia.
  - Fila 1: nombre en negrita + hora corta (`HH:MM` hoy, «ayer», día abreviado «lun…dom» hasta 6 días, si no `dd/mm`).
  - Fila 2: vista previa («Tú: …», en grupos «Nombre: …», «Mensaje eliminado», «📎 Adjunto»; grupo vacío «N miembros») + globo de no leídos.
  - Vacío: «Aún no tienes conversaciones» + «Empezar una».
- **Columna conversación** (`.ch-main`):
  - Barra superior: avatar (con punto si DM), nombre, subtítulo (DM: punto + «en línea» (verde) / «ausente» / «últ. vez hace N min|h|d» / «sin conexión» / «Mensaje directo»; grupo: «N miembros»; mientras alguien escribe: «escribiendo…»), botón «⋮» (en grupos abre «Info del grupo»).
  - Feed: separadores de día (`dd/mm/aaaa`), burbujas (propias a la derecha); mensajes consecutivos del mismo autor y día se agrupan (`cont`, sin repetir avatar/nombre); en grupos, nombre del autor coloreado sobre la primera burbuja. Dentro: cita de respuesta (autor + extracto 80, clic → scroll y resalta), texto HTML, adjuntos (imágenes en miniatura con lightbox; ficheros con icono y nombre, descarga `&dl=1`), hora + «editado» + tics (1 tic enviado, 2 tics «leído» cuando `id <= min(last_read de los demás miembros)`). Debajo, reacciones agregadas (emoji + contador, resaltadas si son mías; clic alterna). Al pasar: botones «Reaccionar» y «Más». Borrado: «🚫 Este mensaje fue eliminado».
  - Botón flotante «↓» con contador de mensajes nuevos cuando no estás abajo (umbral 120 px).
  - Barra «X está escribiendo…» / «N escribiendo…» con tres puntos animados.
  - Barra de respuesta/edición («Tú»/autor + extracto; «Editando mensaje»; ✕ cancela).
  - Adjuntos pendientes (chips con miniatura y ✕).
  - Compositor: Emoji (picker global), Adjuntar (input file múltiple), Micrófono (dictado), textarea autoextensible hasta 120 px «Escribe un mensaje…», Enviar. Enter envía, Shift+Enter salto; pegar imágenes las añade como adjunto.
  - Vacío: «Selecciona una conversación» / «o empieza un grupo / mensaje directo.»
- **Modal «Nuevo mensaje»**: buscador «Buscar persona…», texto «Elige **una persona** para un chat directo, o **varias** para crear un grupo.», lista de personas (avatar + presencia + check). Botón: «Elige a alguien» (deshabilitado) → «Enviar mensaje» (1) → «Crear grupo · N» (≥2, aparece «Nombre del grupo…» obligatorio: toast «Ponle un nombre al grupo»).
- **Modal «Nuevo grupo»** (legado, mismo efecto): nombre «Nombre del grupo (ej: Equipo SEO)», checkboxes de miembros.
- **Modal «Info del grupo»**: Nombre + Guardar; «Miembros (N)» con «Quitar» (no a uno mismo, «(tú)»); «Añadir» con quienes no están; «Salir del grupo» (confirm «¿Salir de este grupo?»); «Cerrar». Cada acción recarga la página.
- **Menú contextual de mensaje** (clic derecho o «Más»): fila de reacciones rápidas `👍 ❤️ 😂 😮 😢 🙏 🔥 👏` + «＋» (picker completo); «Responder», «Copiar» (texto sin HTML, toast «Copiado»), «Ver perfil de X» (ajenos), «Editar» y «Eliminar» (propios; confirm «¿Eliminar este mensaje para todos?»).

### 5.2 Acciones (POST `chat.php`, respuesta JSON)

| `action` | Parámetros | Validación / efecto | Respuesta |
|---|---|---|---|
| `send` | `room_id, body, reply_to?, files[]` (multipart) | miembro; cuerpo o adjunto obligatorio; `reply_to` debe ser de la misma sala; inserta mensaje; `last_read` del autor = nuevo id; borra su `chat_typing`; notifica (§5.5) | `{ok:1,id}` / `{ok:0}` |
| `react` | `mid, emoji` (≤16) | miembro de la sala del mensaje; alterna (insert/delete) | `{ok}` |
| `edit_msg` | `mid, body` | solo autor, no borrado; `edited=1` | `{ok:1}` siempre |
| `del_msg` | `mid` | solo autor; `deleted=1, body='', attach=NULL`; borra reacciones (los ficheros quedan en disco) | `{ok:1}` |
| `grp_rename` | `room_id, name` | miembro; solo `type='group'`; ≤120 | `{ok:1}` |
| `grp_add` | `room_id, uid` | miembro; uid existente | `{ok:1}` |
| `grp_remove` | `room_id, uid` | miembro; uid≠yo (cualquier miembro puede expulsar a otro) | `{ok:1}` |
| `grp_leave` | `room_id` | | `{ok:1}` |
| `create_group` | `name, members[]` | `can_edit()` («Sin permiso para crear grupos.»); nombre obligatorio; añade al creador | `{ok:1, room}` |
| `open_dm` | `other` | busca sala `dm` con ambos o la crea | `{ok:1, room}` |
| `typing` | `room_id` | miembro; `until_ts = now+6` | `{ok}` |
| `poll` | `room_id, after, act` | ver §5.3 | ver §5.3 |

Adjuntos: extensiones `jpg jpeg png gif webp avif bmp pdf doc docx xls xlsx ppt pptx txt csv zip rar mp4 mov webm mp3 ogg wav m4a`; ≤25 MB c/u (los que no cumplen se ignoran en silencio); nombre `<12hex>_<original saneado>` (≤120); imágenes optimizadas; `attach` JSON `[{fn, orig, img}]`; URL `../archivo.php?d=chat&f=<fn>` (**sin control de pertenencia a la sala**).

Formato del texto (`chat_body_html`): escape HTML, URLs `https?://` → enlaces `target=_blank`, `@nombre` (2–40 caracteres) resaltado si coincide (sin distinguir mayúsculas) con un usuario del equipo — **solo visual, no notifica**; saltos de línea `<br>`. Autocompletado de menciones al escribir `@` (máx. 6, flechas/Enter/Tab/Escape).

Dictado por voz: Web Speech API (`SpeechRecognition`, `lang='es-ES'`, continuo, resultados intermedios). Errores: «Tu navegador no permite el dictado por voz (prueba con Chrome)», «Da permiso al micrófono para dictar».

### 5.3 Sondeo de la sala abierta (polling)
- Cliente: `setInterval(chPoll, 2500)` solo si hay sala abierta; también tras enviar/reaccionar/editar/borrar.
- Petición: `POST action=poll, room_id, after=<último id conocido>`.
- Servidor: `chat_presence_touch(yo, act)`; mensajes `id > after`; actualiza `last_read` al último recibido.
- Respuesta:
```json
{
  "ok": 1,
  "messages": [Mensaje],
  "states":   [{"id":1,"html":"…","react":[Reaccion],"edited":0,"deleted":0}],
  "typing":   ["nombre", "..."],
  "read":     123,
  "unread":   {"<roomId>": 3},
  "presmap":  {"<adminId>": {"s":"online|idle|offline","t":"en línea","c":"#12a150"}}
}
Mensaje = {id, aid, mine:0|1, author, color, ini, html, time:"HH:MM", day:"YYYY-MM-DD", datel:"dd/mm/YYYY",
           reply:{id, author, ex}|null, attach:[{orig, img:0|1, url}], react:[Reaccion], edited:0|1, deleted:0|1}
Reaccion = {emoji, count, mine:0|1}
```
- `states` = hasta 150 mensajes más recientes de la sala con edición, borrado o reacciones, **reenviados en cada sondeo** (coste alto); el cliente reconstruye la burbuja si cambia algo.
- `read` = `MIN(last_read)` de los demás miembros (para los dobles tics).
- La carga inicial del HTML incluye todos los mensajes de la sala (sin paginar) en `CH_MSGS`.

### 5.4 Avisador global (todas las páginas, `erp_nav.php`)
- `GET chat.php?ping=1&act=<1 si activo>&after=<seen>` cada **5 s** (primer sondeo a 1,5 s) y al volver a la pestaña (`visibilitychange`).
- `act=1` si la pestaña está visible y hubo interacción (mousemove, mousedown, keydown, touchstart, scroll, focus) en los últimos 60 s.
- Respuesta `{ok, messages:[{id, room, author, body(120), label (nombre del grupo o del autor), group:0|1}], max, unread}` (mensajes de otros en mis salas con `id > after`).
- Primer sondeo: fija la línea base (`localStorage.chatSeen_<miId>`) sin avisar. Después, si hay mensajes que no sean de la sala abierta con la pestaña visible: popup apilado (máx. 6, 6,5 s, se pausa al pasar; título «Grupo · Autor» o «Autor», cuerpo + «· +N más»; clic → `chat.php?room=`), notificación del navegador (`tag chat-<room>`; permiso pedido al primer clic/tecla) y bip WebAudio (620→880 Hz, 0,34 s).
- Menú lateral: contador de no leídos del chat (`chat_unread`: mensajes de otros con `id > last_read` en todas mis salas).
- Notificaciones de la campana: sondeo aparte `notifications.php?poll=1` cada 5 s (fuera de ámbito).

### 5.5 Presencia y «escribiendo»
- `chat_presence.last_seen` se actualiza en cada `poll`/`ping`/apertura de sala; `last_active` solo si `act=1`.
- Estado: `offline` si no hay registro o `now - last_seen > 65 s` (texto «últ. vez hace N min|h|d» o «sin conexión»); `idle` si `now - last_active > 300 s` («ausente»); si no `online` («en línea»). Colores: online `#12a150`, idle `#f0872a`, offline `#c0c4cb`. Lo usan chat, perfil y los avatares de todo el ERP.
- Escribiendo: el cliente manda `typing` como mucho cada 2,5 s al teclear; el servidor guarda `until_ts = now+6`; el sondeo devuelve los nombres con `until_ts > now` (excepto yo); enviar borra la marca.
- Notificación por mensaje: para cada otro miembro borra y recrea `notifications` con `ref chat:<room>:<uid>`, `tipo 'chat'`, título «ha escrito en «Sala»» (grupo con nombre) o «te ha escrito por chat», cuerpo = primeros 120 caracteres o «📎 Adjunto», url `chat.php?room=`, `tarea`=nombre de sala, `actor`=autor. Abrir la sala borra la mía. Respeta `notifmute` (`chat`).

---

## 6. Soporte (`support.php`)

- **URL**: `support.php[?fe=<estado>][&cli=<id>][&new=1]` (lista) · `support.php?t=<id>` (detalle). Permiso `ver.soporte`; todo POST exige `can_edit()` (no `soporte.responder`).
- Estados: abierto «Abierto» `#3b82f6`, en_curso «En curso» `#7b68ee`, esperando «Esperando» `#e0a000`, resuelto «Resuelto» `#12a150`, cerrado «Cerrado» `#9aa0a8`. Prioridades: 1 Baja `#94a3b8`, 2 Normal `#3b82f6`, 3 Alta `#f59e0b`, 4 Urgente `#ef4444`.
- **Lista**: h1 «Tickets de Soporte»; chip «Cliente: X ×» si `cli`; select «Todos los estados»; «+ Nuevo ticket». KPIs: Abiertos, En curso, Esperando, Resueltos (resuelto+cerrado), filtrados por cliente si `cli`. Tabla (filas clicables, menú contextual): Asunto (+ «#id · cliente»), Estado (pastilla con punto), Prioridad (punto + texto coloreado), Asignado (avatar o «—»), Actualizado (relativo: ahora / N min / N h / dd/mm/aaaa). Orden: estado (abierto→cerrado), prioridad desc, `updated_at` desc. Alcance: `alcance_sql('client_id')` (los tickets sin cliente desaparecen para quien no ve todo). Vacío: «No hay tickets todavía» / «Cuando un cliente escriba desde su portal o crees un ticket, aparecerá aquí.» + «Crear ticket»; con filtro: «Sin tickets con este estado» / «Prueba a quitar el filtro de estado.». Menú contextual: Abrir ticket, Marcar en curso, Marcar resuelto, Eliminar («¿Eliminar el ticket?» / «Se borra el ticket con toda su conversación.»).
- **Modal nuevo**: Asunto (req., «Resumen del problema»), Descripción («Detalla la incidencia…»), Prioridad (Normal), Asignar a (Sin asignar), Cliente (opcional; preseleccionado con `cli`). «Cancelar» / «Crear ticket».
- **Detalle**: «← Volver a tickets»; tarjeta: asunto h2, «#id · Abierto por <usuario|—> · <relativo> · Cliente: X», cuerpo, respuestas (avatar, nombre, hora relativa, texto) o «Sin respuestas todavía.», caja «Escribe una respuesta…» + «Responder». Lateral «Propiedades»: selects Estado, Prioridad, Asignado a, Cliente (guardan al cambiar, sin feedback); «Eliminar ticket».
- **POST**:
  - `create {asunto, cuerpo, prioridad, assignee_id, client_id, ajax?}` → estado `abierto`, `created_by`=yo; si nace asignado → `notif_ticket_assigned` (tipo `ticket`, «te ha asignado este ticket», cuerpo = cliente o «Sin cliente», `tarea`=asunto, ref `tkassign:<id>:<uid>`, no a uno mismo). `ajax=1` → `{ok,id}` / `{ok:0,msg:'Escribe un asunto.'}`; si no redirige a `?t=`.
  - `reply {ticket_id, cuerpo}` → inserta, `updated_at=NOW()`; redirige.
  - `set {id, field ∈ estado|prioridad|assignee_id|client_id, val}` → JSON `{ok:1}`; notifica si cambia el asignado.
  - `del {id}` → papelera (`pap_borrar_flash('support_tickets', id, 'ticket', asunto, [support_replies por ticket_id], 'Ticket «X» eliminado')`) y borrado.
- **Portal del cliente** (`index.php`, `portal_action=ticket`, JSON): `asunto` opcional (por defecto «Mensaje de <cliente>», ≤200), `cuerpo` obligatorio («Escribe tu mensaje.»); prioridad 2, estado abierto, `created_by NULL`. Error «No se pudo enviar. Inténtalo de nuevo.». **No avisa al equipo** ni hay correo.

---

## 7. Calendario, reuniones y agendar

### 7.1 Google Calendar (`lib/gcal.php`)
- Cliente OAuth global (`gcal_client_id`, `gcal_client_secret`); tokens por usuario (`gcal_tok_<id>`).
- Scopes: `https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.readonly`, `access_type=offline`, `include_granted_scopes=true`, `prompt=consent`, `state` aleatorio en sesión (`gcal_state`).
- Canje: `POST https://oauth2.googleapis.com/token` (`authorization_code`); guarda `{access_token, refresh_token (conserva el anterior si no viene), expiry = now + expires_in − 60, email}`; email vía `GET https://www.googleapis.com/oauth2/v2/userinfo` (**con solo los scopes de calendario normalmente no devuelve email** → el «Conectado: <email>» puede salir vacío).
- Refresco: si `expiry` pasó → `grant_type=refresh_token`; `invalid_grant` → `gcal_revoked_<id>='1'` (UI pide reconectar); éxito → `'0'`.
- Cifrado: legado AES-256-CBC (`enc:` + base64(iv+ct)), clave = SHA-256(APP_SECRET|gcal) o `settings.gcal_key`, con claves heredadas; migra en caliente los tokens en claro. **Backend nuevo** (`backend/admin/lib/gcal.php`): `gcal_client_secret` y `gcal_tok_*` pasan por `boveda_guardar/boveda_valor(…, BOVEDA_GCAL='gcal')`.
- API Calendar v3 (`calendars/primary/events`):
  - `gcal_events(id, desde, hasta)`: `timeMin/timeMax` (Z), `singleEvents=true`, `orderBy=startTime`, `maxResults=250`; normaliza `{id, titulo ('(sin título)'), dia, hora, hora_fin, ini, fin, allday, editable (organizer.self|creator.self), invitados (CSV sin self), location, descripcion, meet, recurring, masterId, link}`; omite cancelados.
  - `gcal_meetings_range`: igual pero solo eventos con invitados, Meet o adjuntos; añade `emails[]`, `docs[{title,url}]` (adjuntos = notas de Gemini), `recordar` ('' predeterminado | 'no' | minutos), `erp_meeting`.
  - `gcal_meeting_notes(id, mid)`: busca `privateExtendedProperty=erp_meeting=<mid>` → `{found, docs, descripcion, link, titulo}`.
  - `gcal_build_body(o)`: `summary`; con hora → `start/end.dateTime` en la zona del servidor (por defecto Europe/Madrid; fin = hora_fin o +60 min); sin hora → todo el día (`end = fecha+1`); `location`, `description`, `extendedProperties.private.erp_meeting`; `gemini` → añade a la descripción «📝 En la reunión de Meet, pulsa «Tomar notas por mí» (Gemini) para el resumen automático.» (Google no permite activarlo por API); `recur` DAILY|WEEKLY|MONTHLY → `RRULE:FREQ=`; `recordar` ('' no toca, 'no' sin aviso, n → popup n min); invitados válidos → `attendees`.
  - Crear: `POST events[?sendUpdates=all|none][&conferenceDataVersion=1]`; Meet → `conferenceData.createRequest{requestId, conferenceSolutionKey.type='hangoutsMeet'}`. Mensajes «Reunión creada con Google Meet.», «Evento creado e invitaciones enviadas.», «Evento creado.»; errores «No conectado a Google.», «Falta el título.», mensaje de Google o «Error al crear el evento.».
  - Editar: `PATCH events/{id}[?sendUpdates=…]` → «Evento actualizado.». Mover: `PATCH` solo fechas → «Evento movido.». Borrar: `DELETE events/{id}?sendUpdates=all` (410 cuenta como éxito) → «Evento eliminado.».
  - `sendUpdates`: si viene `notificar` → `all|none`; si no, `all` cuando hay invitados.

### 7.2 `calendar.php` — Calendario
- **URL**: `calendar.php?view=mes|semana|dia|agenda&d=YYYY-MM-DD[&ym=YYYY-MM][&team=2,3][&gc=ok|off|err]`. Permiso `ver.agenda`.
- Datos: tareas con `due_date` en el rango (todas, **sin alcance**), asignados (`task_assignees` o responsable); eventos de Google míos (color `#4285F4`) y de compañeros seleccionados en `team` (paleta `#8e44ad #e67e22 #16a085 #d35400 #2980b9 #c0392b #0f9d58`, solo lectura); festivos nacionales de España (1 ene Año Nuevo, 6 ene Reyes, 1 may Día del Trabajo, 15 ago Asunción, 12 oct Fiesta Nacional, 1 nov Todos los Santos, 6 dic Constitución, 8 dic Inmaculada, 25 dic Navidad, Viernes Santo).
- Rango: mes (1–último), semana (lun–dom), día, agenda (30 días desde `d`; navegación ±30).
- **Sin Google conectado**: tarjeta de bienvenida «Tu calendario, conectado a Google» + texto; botón «Conectar Google Calendar» (`gcal_callback.php?start=1`) si configurado; «Configurar Google Calendar» (Dueño) o «Pídele al Dueño que configure Google Calendar en Ajustes…»; enlace «Ver solo las fechas de mis tareas del ERP →» (muestra el calendario).
- Aviso revocado: «Tu conexión con Google ha caducado.» + «Reconectar».
- **Cabecera**: logo GCal (title con email), título (p. ej. «Octubre 2026», «5 Octubre – 11 Octubre 2026», «Mié 8 de Octubre 2026», «Agenda · …»), flechas ‹ ›, «Hoy», segmentado Mes/Semana/Día/Agenda, leyenda de estados (En espera `#b0b4bb`, En proceso `#3b82f6`, Atemporal `#e0a000`, Completada `#12a150`, Google `#4285F4`), «+ Nuevo evento».
- **«Ver también:»** chips de compañeros con Google conectado (alternan `team`).
- **Mes**: rejilla 7 columnas Lun…Dom; celda: número, festivo, hasta 4 tareas (3 si hay eventos) con borde de color de estado y avatares de asignados (2), hasta 2 eventos Google (hora + título), «+N más». Clic en celda → nuevo evento ese día; clic derecho → «Nuevo evento» / «Nueva reunión (Meet)»; eventos propios arrastrables a otro día (`gcal_move`).
- **Semana/Día**: cabecera de días (enlace a vista día), franja «Todo el día» (tareas + eventos de día completo), rejilla 24 h × 46 px, eventos posicionados con columnas para solapes; clic en hueco → nuevo evento a esa hora; eventos propios se arrastran (mover) y redimensionan (bordes arriba/abajo) con paso de 15 min y tooltip «HH:MM – HH:MM»; línea de «ahora».
- **Agenda**: lista por día con fecha grande, festivo, «Entrega» (tareas), «Todo el día»/hora (eventos), dueño si es de otro; vacío «Nada en los próximos 30 días».
- **Popover de evento**: título, 🗓️ fecha, 🕒 hora o «Todo el día», 👤 «Agenda de X», 👥 invitados; editable: «Editar», «Google», «Eliminar evento»; si no: «Ver en Google». Menú contextual: Editar, Duplicar, Eliminar, Ver en Google. Eventos repetidos: diálogo «Este evento se repite. ¿A qué quieres aplicarlo?» → «Solo este evento» / «Toda la serie» (usa `masterId`).
- **Modal evento** («Nuevo evento» / edición; «Se guarda en tu Google Calendar»): Título («Ej: Reunión de equipo»), Fecha + hora opcional (añadir/quitar hora; por defecto 09:00–10:00), Repetición (No se repite, Cada día, Cada semana, Cada mes), Invitados (CSV con autocompletado de correos de admins, contactos, clientes y compañeros con Google), Ubicación, Descripción, Recordatorio (Predeterminado de Google, 10/30 (def.)/60/120 min, 1 día, Sin recordatorio), interruptores Google Meet, Avisar a los invitados (on), Gemini (visible solo con Meet). Validación «Escribe un título», «Elige una fecha»; toasts «Evento creado ✓», «Evento actualizado ✓», «Evento eliminado», «Evento movido», «Error de red». Tras guardar recarga la página.
- **POST JSON** `action=gcal_new|gcal_edit|gcal_move|gcal_del` con `id, titulo, fecha, hora, hora_fin, invitados, meet, gemini, location, descripcion, recur, recordar, notificar, erp_meeting` → `{ok, msg}`; «No estás conectado a Google Calendar.». **No exige `can_edit()`** (cada uno gestiona su propio calendario).
- Toasts de `?gc=`: «Google Calendar conectado ✓», «Google Calendar desconectado», «No se pudo conectar con Google».

### 7.3 `reuniones.php` — Reuniones
- **URL**: `reuniones.php[?u=me|all|<adminId>][&ctx=ops][&flash=…&fok=0|1]`. Permiso `ver.agenda`; todo POST `require_can_edit()`.
- Rango: −90 / +60 días. Selector «Ver reuniones de…» (Todo el equipo / Solo yo / cada compañero) solo si hay >1 cuenta conectada; vista equipo deduplica por id y muestra el dueño.
- **Hero**: logo GCal, h1 «Reuniones», «Tus reuniones de Google en un sitio, con las notas de Gemini y para crear nuevas.», selector, «+ Crear reunión» (si conectado y `can_edit`).
- **Solicitudes de reunión** (pendientes del portal): «Solicitudes de reunión · N pendiente(s)»; tarjeta con día/mes deseado (o «–/día»), título = motivo o «Reunión solicitada por el cliente», cliente, «dd/mm/aaaa · franja» o «Sin día preferido»; «Rechazar» (confirm) y «Aprobar» (abre el modal pre-rellenado: título = motivo, invitado = `clients.fact_email`, fecha deseada; título del modal «Aprobar y agendar reunión», botón «Crear y avisar al cliente», sin segmentado).
- Sin Google: «Conecta Google Calendar para ver y crear reuniones aquí. Ve a Integraciones.»; revocado: «Google pide volver a conectar la cuenta. Reconéctala en Integraciones.»
- **Pestañas**: Próximas (asc), Pasadas (desc), Notas (eventos con adjuntos, desc) con contadores; desplegable de meses («Todos los meses», solo si hay >1 mes).
- **Tarjeta de reunión**: día/mes, título, cliente asignado (enlace a `crm.php?open=` o `client.php?id=`) o «Sin cliente» + botón «asignar/cambiar», insignia Meet, dueño (vista equipo), fecha «d mmm aaaa · HH:MM»; documentos (notas de Gemini) y «Ver en Google». Emparejado automático: asignación manual (`reunion_cliente`) > correo de invitado = `contacts.email` > `clients.fact_email`.
- Vacíos: «No tienes reuniones próximas» / «Cuando agendes una o apruebes una solicitud, aparecerá aquí.»; «Sin reuniones pasadas» / «No hay reuniones en los últimos 90 días.»; «Aún no hay notas» / «Activa «Tomar notas por mí» (Gemini) en una reunión de Meet y aquí aparecerá su documento.»
- **Popup asignar**: buscador «Buscar contacto…», «✕ Sin cliente», grupos por tipo de fase CRM (Clientes activos, Potenciales, En pausa, Cerrados perdidos, Otros). POST sin `action` `{event_id, contact_id}` (0 = quitar) → upsert/delete `reunion_cliente` → `{ok:1}`; toast «Reunión asignada ✓» / «Asignación quitada» y recarga.
- **Modal crear/editar**: cabecera con logo Meet «Crear reunión» / «Se crea en tu Google Calendar»; segmentado «La agendo yo» / «Que elija el cliente» (este muestra el `meeting_url` de ajustes con «Copiar»; si falta: «Aún no tienes enlace de reservas…» y oculta el botón de guardar). Campos: Título (req., «Ej: Llamada con Cliente X»), Fecha (datepicker), Hora (10:00), Duración (30 min, 1 hora, 1 h 30, 2 horas), Invitados (autocompletado), Recordatorio (30 min por defecto), interruptores «Añadir videollamada de Google Meet» (on), «Avisar a los invitados por correo» (on), «Tomar notas con Gemini» (off). Botones «Borrar» (edición), «Cancelar», «Crear en Google Calendar» / «Guardar cambios». Clic derecho en tarjeta: Editar reunión, Asignar cliente, Abrir en Google Calendar, Borrar reunión («Se elimina «X» de Google Calendar y se avisa a los invitados.»).
- **POST** (todas redirigen con `flash`, salvo asignar):
  - `create|update {titulo, fecha, hora, hora_fin|dur, invitados, meet, gemini, descripcion, notificar, recordar, event_id?, req_id?}`; `hora_fin = hora + dur`.
  - `create` con `req_id`: si el cliente tiene `contact_id` → INSERT `crm_meetings` (agendada) y `erp_meeting`; si no hay invitados usa `fact_email` y fuerza aviso; crea evento; marca la solicitud `aprobada` (+`meeting_id`). Mensajes «Reunión agendada y avisado el cliente.», «Reunión agendada (el aviso de Google falló, pero el cliente ya la ve en su portal).», «Solicitud aprobada. Ese cliente no tiene contacto en el CRM: agenda la reunión a mano en su ficha.».
  - `delete {event_id}`.
  - `meetreq_reject {req_id}` → `rechazada` («Solicitud rechazada.»); `meetreq_approve {req_id}` (aprobar sin Google: crea `crm_meetings` con la fecha deseada, «Reunión aprobada y agendada.»).
- Aviso al equipo de solicitudes nuevas: `notif_sync_meeting_requests()` (campana a los dueños, «Nueva solicitud de reunión» / «X ha pedido una reunión desde su portal», ref `meetreq:<id>`).
- Portal (`index.php`, `portal_action=meetreq`): `motivo` obligatorio («Cuéntanos brevemente el motivo.»), `fecha` ISO opcional, `franja` ≤30.

### 7.4 `agendar.php` — Endpoint compartido
- POST JSON (ficha de cliente, CRM…). Permiso `ver.agenda` + `require_can_edit()`.
- Entrada: `titulo, fecha (dd/mm/aaaa | aaaa-mm-dd), hora, invitados, meet ('1' por defecto), contact_id, gemini, notificar, recordar`. «Falta el título o la fecha.».
- Con `contact_id`: INSERT `crm_meetings (contact_id, fecha, hora, estado 'agendada')`, `crm_activity(contact, null, 'reunion', 'Reunión agendada para dd/mm/aaaa HH:MM')`, `contacts.fecha_ultimo_contacto = CURDATE()`.
- Si Google conectado y no revocado: crea evento con `erp_meeting`. Respuesta `{ok:1, mid, msg}` con «Reunión agendada.», «Reunión guardada, pero Google dio un aviso: …», «Reunión guardada. (Conecta Google Calendar en Integraciones para crear el evento.)».

---

## 8. Actas (`actas.php`)
Módulo propio (no son tareas), solo comparte el editor enriquecido `lib/rt_editor.php` (formato de texto con marcadores; `rt_blocks()` para pintar, `rtSerialize()` para guardar, `rt_excerpt()`).
- **URL**: `actas.php` (lista) · `?id=N` (lectura) · `?id=N&edit=1` · `?nueva=1` · `&guardada=1`. Permiso `ver.actas`; escribir/fijar/borrar `require_can_edit()`.
- **Lista**: h1 «Actas», «+ Nueva acta», «Notas y actas internas del equipo. No se ven en el portal del cliente.»; buscador «Buscar en las actas…» (título + extracto, local); chips de autor (si >1; «Todos»); tarjetas ordenadas fijadas → `updated_at` desc: «Fijada», título o «(Sin título)», extracto 200, avatar + autor («Equipo» si sin autor) + fecha relativa (title exacta «23 sep 2026, 18:40»); acciones fijar/borrar. Vacío: «Aún no hay actas» + texto + botón. Sin resultados: «No hay actas que coincidan con la búsqueda.».
- **Lectura**: migas «Todas las actas», h1, filas Autor / Última edición (relativa · exacta) / Creada; acciones Editar, Fijar/Fijada, Borrar («¿Borrar esta acta? Se puede recuperar desde la papelera.»); cuerpo renderizado o «Esta acta está vacía.».
- **Edición**: título grande («Título del acta…», ≤220), editor con barra de herramientas (títulos, listas, checklist, cita, código, tabla, pegar con formato), barra inferior «Guardar acta», «Cancelar/Volver», «Última edición …».
- **POST**: `guardar {id?, titulo, contenido}` → insert (autor = yo) o update → `?id=N&guardada=1`; `pin {id, ret?}` (alterna sin tocar `updated_at`); `borrar {id}` → papelera.
- Fechas relativas: «hace un momento», «hace N minutos/horas», «hoy», «ayer», «hace N días/semanas/meses/años».

---

## 9. Asistente IA (`ia.php`) y MCP (`mcp.php`)

### 9.1 `ia.php` — maqueta
- Permiso `ver.ia`. **No llama a ningún proveedor ni usa claves**: es una demo visual («Próximamente»).
- Hero: orbe con destellos, h1 «Asistente IA» + insignia «Próximamente», «Tu copiloto para redactar informes, resumir clientes y crear tareas — así se verá.»
- 3 tarjetas-sugerencia: «Redactar informes» («Redáctame el informe mensual de SEO para un cliente»), «Resumir tareas» («Resume el estado de todas mis tareas de esta semana»), «Ideas y estrategia» («Sugiere una estrategia de contenidos para un cliente de dentistas»).
- Chat: saludo «¡Hola <usuario>! Soy el asistente de <agencia>. Cuando esté activo podré…»; input «Escribe algo para ver la demo…»; al enviar, burbuja propia + «escribiendo» 900 ms + respuesta fija «Buena pregunta. Cuando la IA esté conectada te respondería esto usando tus datos reales…». Nota inferior «Esta es una vista previa. La IA todavía no está conectada — pronto podrás usarla de verdad.»
- React: mantener como pantalla «Próximamente» o implementar de verdad (decisión de producto: proveedor, coste, qué datos puede leer, respetar alcance/permisos).

### 9.2 `mcp.php` — Servidor MCP (Claude)
- JSON-RPC 2.0 sobre HTTP POST, **sin sesión ni CSRF** (no incluye auth.php). Requiere `mcp_enabled='1'` y token igual a `mcp_token` (`hash_equals`), leído de: `Authorization: Bearer`, `?k=`, `?token=` o PATH_INFO (`/admin/mcp.php/<token>`). Fallo → 401 `{error:{code:-32001, message:'No autorizado. Revisa el token del MCP en Ajustes.'}}`.
- Métodos: `initialize` → `{protocolVersion:'2024-11-05', capabilities:{tools:{listChanged:false}}, serverInfo:{name:'Croilab ERP', version:'1.0.0'}}`; `ping`; `tools/list`; `tools/call`; notificaciones `notifications/*` → 202 sin cuerpo; otro → −32601. JSON inválido → 400 −32700.
- Herramientas (resultado `content:[{type:'text', text:<JSON>}]`, errores `isError:true` con «Error: …»):
  - `listar_clientes` → `[{id, name, activo}]`; `listar_equipo` → `[{id, username}]` (incluye inactivos).
  - `listar_listas {cliente}` (cliente = id o parte del nombre, primer LIKE).
  - `listar_tareas {cliente?, estado?, responsable?, texto?, limite≤200 (50)}` → id, titulo, estado, prioridad, due_date, etiquetas, cliente, lista, responsable; orden `updated_at desc`.
  - `crear_tarea {cliente*, titulo*, lista?, estado?, due_date?, responsable?, prioridad 0–4, etiquetas?}` — lista por nombre (crea si no existe; si no, la primera; si no hay, crea «Tareas») → `{ok, id, mensaje:'Tarea creada', url}`.
  - `actualizar_tarea {id*, titulo?, estado?, prioridad?, due_date? (vacío = quitar), responsable?, etiquetas?}`.
  - `etiquetar_tarea {id*, etiquetas*}` (une sin duplicados).
  - `comentar_tarea {id*, texto*}` → `task_comments` con `admin_id` = **primer admin por id** (`mcp_actor()`).
- Riesgos: sin alcance ni permisos (actúa como el Dueño), sin notificaciones/auditoría, no actualiza `task_assignees`, token en la URL (logs). En la arquitectura nueva conviene servirlo como `POST /api/v1/mcp` (o `/mcp`) con el mismo contrato, actor configurable, auditoría y `TareasServicio` (para reutilizar validaciones y notificaciones).

---

## 10. Flujos OAuth de Google y rutas de redirección

| Flujo | Inicio | Scopes | Redirect URI registrada | Estado anti-CSRF | Almacenamiento | Vuelta |
|---|---|---|---|---|---|---|
| Calendar por usuario | `gcal_callback.php?start=1` (requiere sesión) | `calendar.events calendar.readonly`, offline, consent | `gcal_redirect_uri()` = `<esquema>://<host><dir del script>/gcal_callback.php` → hoy `/admin/gcal_callback.php` | `$_SESSION['gcal_state']` | `settings.gcal_tok_<id>` cifrado (legado AES; backend bóveda) | `calendar.php?gc=ok|err` |
| Desconectar Calendar | `GET gcal_callback.php?disconnect=1` (**GET sin CSRF**) | — | — | — | borra `gcal_tok_<id>` | `calendar.php?gc=off` |
| Login equipo con Google | `google_login.php` (público; si ya hay sesión → dashboard; sin config → `login.php?ge=nocfg`) | `openid email profile`, `prompt=select_account` | **la misma** `gcal_callback.php` (mismo cliente OAuth) | `$_SESSION['glogin_state']` + marca `glogin` | no guarda tokens | email verificado → `admins.email` (LOWER) → `session_regenerate_id`, `$_SESSION['admin_id']` → dashboard. Errores `login.php?ge=denied|err|cancel` («Ese correo de Google no está dado de alta en el equipo. Pídele al Dueño que te invite.», «No se ha podido entrar con Google. Inténtalo de nuevo.», «Has cancelado el acceso con Google.», «El acceso con Google todavía no está configurado.»). ≥5 fallos → `sleep(2)` |
| Login portal cliente con Google | `client_google_login.php` | ídem | ídem | `gclilogin_state` | — | `clients.login_email` → `$_SESSION['client_id']` → `../index.php` |
| Google · Métricas (global) | botón en integraciones (`gm_oauth_url()`) | `webmasters.readonly analytics.readonly`, offline, consent | `gm_redirect_uri()` = `<esquema>://<host>/admin/gmet_callback.php` (**ruta fija `/admin/`**) | **ninguno** (sin `state`) | `settings.google_oauth_refresh_token` **en claro**; id/secreto en `google_oauth_client_*` (secreto en claro) | `integraciones.php?i=gmet&ok=conn|connerr` (requiere `can_edit()`) |

Notas:
- Login con Google (equipo) en el legado **no comprueba `activo` ni fija `cred_ver`** en sesión; en el backend nuevo el login debe pasar por el mismo servicio que el login por contraseña (freno de intentos, `cred_ver`, auditoría).
- Mismo cliente OAuth para identidad y calendario: si se cambian las URIs hay que actualizar ambos usos a la vez.

---

## 11. Recomendaciones

### (a) Endpoints API propuestos (`/api/v1`, convenciones de `backend/API.md`)

**Equipo y cuentas**

| Método | Ruta | Propósito |
|---|---|---|
| GET | `/equipo/miembros` | Lista para «Mi equipo»: `{id, username, email, role, role_nombre, es_admin_total, created_at, cumple, foto, activo}` (`equipo.gestionar`) |
| POST | `/equipo/miembros` | Alta `{username, email?, role, password? , enviar_enlace?:bool}` → 201 (si `enviar_enlace`, correo SMTP con enlace) |
| GET | `/equipo/miembros/{id}` | Ficha (acceso + facturación + `enlace_password_activo:{caduca}`) |
| PATCH | `/equipo/miembros/{id}` | `{username?, email?, role?}` (role vía `rol_asignar`) |
| PUT | `/equipo/miembros/{id}/rol` | Cambio rápido de rol `{rol}` → `{recargar}` |
| PATCH | `/equipo/miembros/{id}/facturacion` | `{es_autonomo, tarifa_hora, iva_pct, irpf_pct}` |
| POST | `/equipo/miembros/{id}/password` | `{modo:'fijar', password}` · `{modo:'generar'}` → `{password}` una vez · `{modo:'enlace', enviar_correo?}` → `{url, caduca}`; siempre `cred_ver++` y anula enlaces previos |
| DELETE | `/equipo/miembros/{id}/password-enlace` | Anular enlace pendiente |
| DELETE | `/equipo/miembros/{id}` | Baja lógica (`activo=0`, `cred_ver++`, fuera de salas de chat, tokens Google borrados, tareas liberadas); 409 si es el último con acceso total o uno mismo |
| GET / POST / DELETE | `/equipo/invitaciones` · `/equipo/invitaciones/{token}` | Enlaces de registro (rol sin `admin.total`); opcional enviar por correo |
| GET | `/auth/registro?token=` (público) | `{rol_nombre, marca}` · 404 si no vale |
| POST | `/auth/registro` (público) | `{token, username, email?, password}` → crea cuenta; 422 con `campo` |
| GET | `/auth/google/iniciar` (público) | Devuelve/redirige a la URL de Google (login) |
| GET | `/auth/google/callback` | Canje; redirige a `FRONT_URL/…` con sesión o `?ge=` |
| POST | `/auth/reconfirmar` | `{password, zona}` → desbloqueo 30 min (reauth) |

**Perfil y cuenta propia**

| Método | Ruta | Propósito |
|---|---|---|
| GET | `/perfiles/{id}` | Perfil social: datos, `presencia {estado, texto, color}`, `rol_nombre`, `puede_editar`, tareas pendientes (resumen) |
| PATCH | `/perfiles/{id}` | `{cargo, departamento, telefono, ubicacion, web, skills, cumple, bio}` (yo, `equipo.gestionar`, `admin.total`) |
| POST / DELETE | `/perfiles/{id}/foto` | Subir (multipart, ≤8 MB, jpg/png/gif/webp, 800 px) / quitar |
| PATCH | `/me/cuenta` | `{username, email}` |
| POST | `/me/password` | `{actual, nueva}` (≥6 o la política del backend; `cred_ver++` salvo la sesión actual) |
| GET / PUT | `/me/avisos` | `{silenciar: ('chat'|'avisos')[]}` |

**Roles**

| Método | Ruta | Propósito |
|---|---|---|
| GET | `/roles` | `{roles:[{clave, nombre, descripcion, sistema, permisos, uso}], catalogo, requisitos, config}` |
| POST | `/roles` | `{nombre}` → `{clave}` |
| PATCH | `/roles/{clave}` | `{nombre}` |
| PUT | `/roles/{clave}/permisos/{perm}` | `{on}` → `{permisos, recargar}` (con dependientes) |
| PUT | `/roles/{clave}/grupos/{grupo}` | `{on}` → `{permisos, recargar}` |
| DELETE | `/roles/{clave}` | 409 si sistema o en uso |

**Ajustes e integraciones**

| Método | Ruta | Propósito |
|---|---|---|
| GET / PATCH | `/ajustes/agencia` | Identidad (PATCH solo `admin.total`) |
| GET / PATCH | `/ajustes/portal/contacto` | `meeting_url, whatsapp, email` |
| GET / PUT | `/ajustes/portal/videos` | `{video_id, servicios:[{nombre, video}]}` |
| GET | `/ajustes/automatizaciones` | Las 8 reglas con `on` |
| PUT | `/ajustes/automatizaciones/{clave}` | `{on}` |
| POST | `/ajustes/automatizaciones/ejecutar` | «Ejecutar ahora» |
| (spec finanzas) | `/ajustes/emisores` | Emisores |
| GET | `/integraciones` | Estado de API, Calendar (configurado/conectado/email/revocado), Métricas, MCP (sin secretos) |
| POST | `/integraciones/api/token` | Regenerar → devuelve el token una vez |
| PUT | `/integraciones/google-calendar/credenciales` | `{client_id, client_secret?}` (secreto solo escritura, a la bóveda) |
| GET | `/integraciones/google-calendar/conectar` | `{url}` con `state` |
| GET | `/integraciones/google/callback` | Callback único (calendar/login/métricas distinguidos por `state` firmado) → redirige al front |
| DELETE | `/integraciones/google-calendar/conexion` | Desconectar mi cuenta (DELETE con CSRF) |
| PUT | `/integraciones/metricas/credenciales` | JSON de Google (multipart) o `{client_id, client_secret}` |
| GET | `/integraciones/metricas/conectar` | `{url}` con `state` |
| DELETE | `/integraciones/metricas/conexion` | Borra refresh token |
| PUT | `/integraciones/mcp` | `{enabled}` |
| POST | `/integraciones/mcp/token` | Regenerar → `{url}` |
| POST | `/mcp` | Servidor MCP JSON-RPC (token) |

**Bóveda**

| Método | Ruta | Propósito |
|---|---|---|
| GET | `/credenciales/clientes` | Selector `{id, name, n}` (con alcance) |
| GET | `/clientes/{id}/credenciales` | Lista **sin** `secreto` (`tiene_secreto: bool`) |
| GET | `/credenciales/{id}/secreto` | Revelar/copiar bajo demanda (auditado; opcional reconfirmar) |
| POST | `/clientes/{id}/credenciales` | Crear (secreto cifrado en bóveda) |
| PATCH | `/credenciales/{id}` | Editar (secreto vacío = no cambiar) |
| DELETE | `/credenciales/{id}` | Borrar (a papelera) |

**Chat**

| Método | Ruta | Propósito |
|---|---|---|
| GET | `/chat/salas` | Salas con `{id, tipo, nombre, miembros:[Persona], otro?:Persona, ultimo:{texto, autor_id, created_at, borrado, adjunto}, no_leidos}` |
| POST | `/chat/salas` | `{tipo:'dm', con}` → sala existente o nueva · `{tipo:'grupo', nombre, miembros[]}` (`general.editar`) |
| PATCH | `/chat/salas/{id}` | `{nombre}` |
| POST | `/chat/salas/{id}/miembros` | `{admin_id}` |
| DELETE | `/chat/salas/{id}/miembros/{adminId}` | Quitar (o `me` para salir) |
| GET | `/chat/salas/{id}/mensajes?antes=&limit=` | Historial paginado (Mensaje como §5.3, `html` o mejor `texto` + entidades) |
| POST | `/chat/salas/{id}/mensajes` | multipart `{texto, responde_a?, adjuntos[]}` → `{mensaje}` |
| PATCH / DELETE | `/chat/mensajes/{id}` | Editar / borrar (autor) |
| PUT / DELETE | `/chat/mensajes/{id}/reacciones/{emoji}` | Añadir / quitar mi reacción |
| POST | `/chat/salas/{id}/leido` | `{hasta}` |
| POST | `/chat/salas/{id}/escribiendo` | Marca 6 s |
| GET | `/chat/salas/{id}/novedades?despues=&cambios_desde=` | Sondeo: `{mensajes, cambios (solo los modificados desde el cursor), escribiendo, leido_hasta}` |
| GET | `/chat/avisos?despues=` | Avisador global `{mensajes, max, no_leidos}` |
| POST | `/presencia/latido` | `{activo}` |
| GET | `/presencia` | `{<id>: {estado, texto, color}}` |
| GET | `/chat/adjuntos/{mensajeId}/{indice}` | Descarga con comprobación de pertenencia |

**Soporte, calendario, reuniones, actas**

| Método | Ruta | Propósito |
|---|---|---|
| GET | `/soporte/tickets?estado=&cliente=` | `{items, contadores}` (con alcance) |
| POST | `/soporte/tickets` | Crear (notifica asignado) |
| GET | `/soporte/tickets/{id}` | Detalle + respuestas |
| PATCH | `/soporte/tickets/{id}` | `{estado?, prioridad?, assignee_id?, client_id?}` |
| POST | `/soporte/tickets/{id}/respuestas` | `{cuerpo}` (`soporte.responder`) |
| DELETE | `/soporte/tickets/{id}` | A papelera |
| GET | `/calendario?desde=&hasta=&equipo=1,2` | `{tareas:[…], eventos:[EventoGoogle+color+owner+mine], festivos:{fecha:nombre}, conectado, revocado, configurado, companeros_conectados}` |
| POST | `/calendario/eventos` | Crear (Google) |
| PATCH | `/calendario/eventos/{id}` | Editar o mover (`{fecha, hora, hora_fin}`) |
| DELETE | `/calendario/eventos/{id}` | Borrar |
| GET | `/calendario/correos-sugeridos` | Autocompletado de invitados |
| GET | `/reuniones?vista=me|all|<id>` | `{proximas, pasadas, notas, cuentas_conectadas}` con cliente emparejado |
| PUT | `/reuniones/{eventId}/contacto` | `{contact_id|null}` |
| POST | `/reuniones` | Agendar (sustituye agendar.php: CRM + Google) |
| GET | `/reuniones/solicitudes?estado=pendiente` | Solicitudes del portal |
| POST | `/reuniones/solicitudes/{id}/aprobar` | `{con_evento?: {...}}` |
| POST | `/reuniones/solicitudes/{id}/rechazar` | |
| GET / POST | `/actas` | Lista (`q`, `autor`) / crear |
| GET / PATCH / DELETE | `/actas/{id}` | Leer / guardar / papelera |
| POST | `/actas/{id}/fijar` | Alternar |

### (b) Pantallas y componentes React

- **Rutas** (sustituyen los «Próximamente» de `App.tsx`): `/ajustes/*` (layout de ajustes) con `/ajustes/agencia`, `/ajustes/facturacion`, `/ajustes/equipo`, `/ajustes/equipo/nuevo`, `/ajustes/equipo/:id`, `/ajustes/roles`, `/ajustes/contacto`, `/ajustes/videos`, `/ajustes/reglas`, `/ajustes/integraciones[/:tipo]`; `/credenciales[/:clienteId]`; `/chat[/:salaId]` (+ `?dm=`); `/soporte`, `/soporte/:id`; `/calendario`; `/reuniones`; `/actas`, `/actas/nueva`, `/actas/:id[/editar]`; `/asistente`; `/perfil/:id`, `/perfil` (modo cuenta con pestañas `cuenta|avisos`); públicas `/registro?token=`, `/google/vuelta`.
- **Ajustes**: `AjustesLayout` (menú por zonas filtrado por permisos + `BuscadorAjustes` + `useFormularioSucio`), `AgenciaForm` + `VistaPreviaMarca`, `ContactoPortalForm`, `VideosPortalForm`, `ReglasAutomaticas` (`ReglaFila` con `Interruptor`), `IntegracionesHub` (`IntegracionCard`), `IntegracionApi`, `IntegracionCalendar`, `IntegracionMetricas` (subida de JSON), `IntegracionMcp` (`TokenCopiable`).
- **Equipo**: `EquipoPage` (`MiembroFila` con `SelectorRol` autoguardado, `InsigniaAdmin`), `InvitacionesPanel`, `MiembroFicha` (`DatosAccesoForm`, `PasswordOpciones` ×3, `ContrasenaUnaVez`, `EnlaceActivo`, `FacturacionAutonomoForm`, `ZonaBaja`), `MiembroAlta`.
- **Roles**: `RolesMatriz` (`RolCabecera` con nombre editable, `GrupoCabecera` con «Todo/Quitar», `PermisoFila`, `Interruptor`), `ConfirmarDependencias` (usa `requisitos`).
- **Perfil**: `PerfilPage` (`PerfilHero` con `PuntoPresencia`, `ContactoLista`, `Skills`, `TareasPendientes`), `PerfilEditar` (`FotoPerfil`), `CuentaPanel`, `PasswordPanel`, `AvisosPanel`.
- **Registro / reauth**: `RegistroPage` (pública), `ReconfirmarDialog` (para zonas sensibles).
- **Bóveda**: `BovedaSelector`, `BovedaCliente` (`FiltroCategorias`, `CredencialCard` con revelar/copiar bajo demanda), `CredencialModal`.
- **Chat**: `ChatPage` (dos columnas / móvil), `ListaSalas` (`SalaItem`), `Conversacion` (`CabeceraSala`, `Feed` con `SeparadorDia`, `Burbuja`, `Cita`, `Adjuntos`, `Reacciones`, `Tics`, `BotonBajar`), `IndicadorEscribiendo`, `BarraRespuesta`, `Compositor` (`EmojiPicker`, `AdjuntosPendientes`, `Dictado`, `Menciones`), `NuevoMensajeModal`, `InfoGrupoModal`, `MenuMensaje`; hooks `useSondeoSala` (2,5 s, pausa en segundo plano), `useAvisadorChat` (global en `AppLayout`: 5 s, popups, `Notification`, sonido, respeta `silenciar`), `usePresencia`/`useLatido`.
- **Soporte**: `SoportePage` (`KpisTickets`, `TablaTickets`, `MenuTicket`), `NuevoTicketModal`, `TicketDetalle` (`Hilo`, `RespuestaForm`, `PropiedadesTicket`).
- **Calendario**: `CalendarioPage` (`CabeceraCalendario`, `SelectorVista`, `ChipsCompaneros`), `VistaMes` (`CeldaDia`), `VistaSemanaDia` (`RejillaHoras`, `EventoPosicionado` arrastrable/redimensionable 15 min, `LineaAhora`), `VistaAgenda`, `EventoPopover`, `EventoModal` (`InvitadosAutocompletar`, `SelectorRecordatorio`), `DialogoSerie`, `ConectarGoogle`.
- **Reuniones**: `ReunionesPage` (`SolicitudesReunion`, `PestanasReuniones`, `FiltroMes`, `ReunionCard`, `AsignarContactoPopover`), `ReunionModal` (modos «La agendo yo» / «Que elija el cliente»), `AgendarReunionDialog` reutilizable (ficha de cliente, CRM).
- **Actas**: `ActasLista` (`BuscadorActas`, `FiltroAutores`, `ActaCard`), `ActaLectura`, `ActaEditor` (reutiliza el editor enriquecido de tareas).
- **IA**: `AsistentePage` (demo o real).
- **Compartidos**: `Avatar` (ya existe; añadir punto de presencia), `Confirmar`, `Prompt`, `Toast` (ya existe), `CopiarBoton`, `FechaRelativa`, `Interruptor`, `Segmentado`, `PastillaEstado`.

### (c) Riesgos y ambigüedades

1. **Tiempo real**: el chat se basa en sondeos (sala 2,5 s, global 5 s, campana 5 s) y cada sondeo de sala reenvía hasta 150 estados y recalcula la presencia de todo el equipo (N consultas). Con React y API separada: (i) mantener polling pero con cursores (`cambios_desde`) y un único endpoint agregado por pestaña, pausado en segundo plano; o (ii) SSE/WebSocket (hosting PHP compartido probablemente no lo soporta bien; valorar un servicio aparte). Decidir antes de diseñar el contrato.
2. **Redirect URIs de Google**: hoy dependen del host y de la carpeta del script (`/admin/gcal_callback.php`, `/admin/gmet_callback.php` fija). Con front y back en subdominios distintos (`COOKIE_DOMAIN`), el callback debe vivir en el backend (`https://<api>/api/v1/integraciones/google/callback`), definirse por configuración (`GOOGLE_REDIRECT_URI`, no por `$_SERVER`) y registrarse en Google Cloud **antes** del corte; después redirigir a `FRONT_URL`. El cliente OAuth es compartido por Calendar, login de equipo y login de clientes: cambiar la URI afecta a los tres. Mantener la URI antigua registrada durante la transición. La cookie de sesión debe viajar en la vuelta de Google (navegación de nivel superior → `SameSite=Lax` sirve).
3. **Seguridad OAuth**: `gmet_callback` no usa `state`; desconectar Calendar es un GET sin CSRF; el refresh token de Métricas y los client secrets se guardan en claro (y el de Calendar se muestra en el formulario). Llevar todo a la bóveda (`boveda.php`, propósito por integración) y hacer los secretos «solo escritura».
4. **Bóveda de clientes en claro**: `client_credentials.secreto` sin cifrar, enviado entero al HTML y al portal del cliente; sin auditoría ni alcance. Migrar con `boveda_guardar` (propósito `cred_cliente`) y revelar bajo demanda.
5. **Bajas físicas**: team-delete borra la fila; mensajes de chat, comentarios y tickets quedan con autor desconocido. Usar `activo=0`. Además la regla «último dueño» usa `role='owner'` en vez de `admin.total`.
6. **Sesiones tras cambios de credenciales**: el legado no sube `cred_ver` al cambiar contraseña (team-edit, perfil, reset, registro) ni al cambiar rol; la API nueva debe hacerlo en todos los caminos (incluido el login con Google, que además debe comprobar `activo`).
7. **Permisos incoherentes**: soporte usa `general.editar` y no `soporte.responder`; integraciones exige `is_owner()` además de `integraciones.editar`; settings `agency` solo Dueño aunque exista `ajustes.editar`; perfil muestra solo nombres de los 3 roles de sistema; el alta de miembro permite crear Dueños sin pasar por `rol_asignar`; cualquier miembro de un grupo puede expulsar a otros. Decidir la regla y aplicarla en el backend.
8. **Enlaces rotos/inconsistencias de UI**: team.php enlaza `chat.php?u=` (no existe, debe ser `dm`); team.php no muestra `?msg=`; el silencio del chat no afecta al avisador global (popup/sonido); las menciones `@` no notifican; el «Conectado: email» de Calendar suele quedar vacío (falta scope `email`); los selects del detalle de ticket guardan sin feedback de error.
9. **Correo**: el legado no envía correos (enlaces copiados a mano, tickets del portal sin aviso). La API nueva ya tiene SMTP: decidir qué se envía (invitaciones, enlaces de contraseña, aviso de ticket nuevo/respondido al cliente) y textos.
10. **Esquema**: `chat_*`, `support_*`, `reunion_cliente`, `portal_meeting_requests`, `actas` se crean en caliente en las páginas y no están en las migraciones nuevas; `signup_*` sigue en `settings` en claro (mover a tabla con hash como `password_resets`).
11. **Adjuntos de chat**: `archivo.php?d=chat` solo exige sesión; cualquier miembro del equipo puede descargar adjuntos de salas ajenas conociendo el nombre. Al borrar un mensaje los ficheros quedan en disco (los recoge `uploads_sweep`, si está activo).
12. **Zona horaria**: los eventos se crean con la zona del servidor (`date_default_timezone_get()`, por defecto Europe/Madrid) y `timeMin/timeMax` en UTC «Z»; fijar zona explícita en la API y en el front.
13. **MCP**: actúa con privilegios totales sin alcance, comenta como el primer admin, sin auditoría; el token viaja en la URL. Si se mueve de ruta, los conectores de Claude ya configurados dejarán de funcionar: mantener alias o avisar para regenerar.
14. **IA**: solo maqueta; no hay proveedor, claves ni contrato. Cualquier implementación real es trabajo nuevo (privacidad de datos de clientes, permisos, coste).
15. **Calendario sin alcance**: muestra tareas de todos los clientes con fecha en el rango, aunque el usuario no tenga `alcance.todos`; la API nueva debe filtrar con `Acceso`.
