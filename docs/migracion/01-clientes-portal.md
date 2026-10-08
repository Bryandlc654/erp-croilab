# Spec funcional 01 — Clientes y Portal del cliente

Fuente: `copia-erp/` (ERP legado en PHP puro). Objetivo: reimplementar 1:1 en la API PHP nueva (`backend/`) + front React (`front/`) sin leer el código antiguo.
Las referencias `archivo:línea` son del legado (`copia-erp/…`) salvo que se diga otra cosa.

---

## 0. Índice

1. Modelo de datos (tablas, columnas, blobs JSON)
2. Permisos y alcance
3. Pantallas del equipo (admin)
   - 3.1 Listado de clientes (`admin/index.php`)
   - 3.2 Ficha / hub del cliente (`admin/client.php`)
   - 3.3 Alta / edición del cliente (`admin/edit.php`)
   - 3.4 Borrar (`admin/delete.php`)
   - 3.5 Duplicar (`admin/duplicate.php`)
   - 3.6 Tipos de cliente (`admin/types.php`, `type-edit.php`, `type-delete.php`)
   - 3.7 Catálogo de servicios (`admin/servicios.php` + `lib/servicios_cat.php`)
   - 3.8 Marca blanca / agencias (`admin/agencias.php` + `lib/marca.php`)
   - 3.9 Calculadora de precios (`admin/pricing.php`)
   - 3.10 Métricas de Google de un cliente (`admin/conversiones.php`)
   - 3.11 Métricas de Google — ajustes globales (`admin/metricas.php`) + cron (`admin/cron_metricas.php`)
   - 3.12 Datos avanzados (`admin/data.php`)
   - 3.13 Editor en vivo del portal (`index.php?cli=&edit=1` + `admin/save-portal.php`)
4. Portal del cliente
   - 4.1 Login (`login.php`) y Google (`admin/client_google_login.php` + `admin/gcal_callback.php`)
   - 4.2 Portal (`index.php`): datos, layout y cada sección
   - 4.3 Factura del portal (`factura.php`)
5. Dependencias externas y cron
6. (a) Endpoints recomendados · (b) Pantallas/componentes React · (c) Riesgos y ambigüedades

---

## 1. Modelo de datos

### 1.1 `clients` (una fila = un cliente y su cuenta de portal)

Columnas (unión de `schema.sql`, `db.php:ensure_schema`, `lib/google_metrics.php:gm_ensure_schema`, `lib/puentes.php:ensure_puentes_schema`, `agencias.php`; la migración nueva `backend/database/migrations/0001_esquema_base.php:62-109` ya las crea casi todas):

| Columna | Tipo | Uso |
|---|---|---|
| `id` | INT PK | |
| `name` | VARCHAR | Nombre del negocio (obligatorio). |
| `username` | VARCHAR, único (validado en app) | Usuario del portal (obligatorio). |
| `password_hash` | VARCHAR(255) | `password_hash()` bcrypt. |
| `cred_ver`, `password_changed_at` | solo en backend nuevo | invalidación de sesiones (no existen en legado). |
| `iniciales` | VARCHAR(4–6) | Avatar. Default legado `'CL'`. |
| `saludo` | VARCHAR | Nombre con el que el portal saluda («Hola, María 👋»). Si vacío → `name`. |
| `conversiones` | TINYINT 0/1 (def 1) | «Tiene conversiones». Solo decide Métricas si el cliente **no** tiene tipo. |
| `actual` | VARCHAR(40) | «Mes actual» (nombre de mes en español, p.ej. `Junio`). Mes que abre el portal por defecto. |
| `tipo_id` | INT NULL → `client_types.id` | Tipo = qué secciones ve. |
| `estado_json` | MEDIUMTEXT | Progreso del proyecto (§1.4). |
| `plan_json` | MEDIUMTEXT | Plan contratado (§1.4). |
| `accesos_json` | MEDIUMTEXT | Enlaces/recursos (§1.4). |
| `informes_json` | MEDIUMTEXT | Informes mensuales (§1.4). |
| `servicios_json` | MEDIUMTEXT NULL | Lista de nombres de servicio contratados. **NULL/vacío = ve todos** (§1.4). |
| `met_json` | MEDIUMTEXT | Métricas por mes (lo escribe solo la sync de Google, §1.4). |
| `tareas_json` | MEDIUMTEXT | Progreso por mes (§1.4). |
| `looker_url` | TEXT NULL | URL de inserción de Looker Studio (iframe en Métricas). |
| `orden` | INT def 0 | No se usa en esta área. |
| `activo` | TINYINT def 1 | «Cliente activo». 0 = «No activo». |
| `fact_nombre`, `fact_nif`, `fact_dir`, `fact_email`, `fact_tel` | VARCHAR | Datos fiscales (auto-relleno de facturas). `fact_tel` no se edita en `edit.php` (solo lo pone el puente CRM). |
| `login_email` | VARCHAR(160) | Gmail para «Entrar con Google» en el portal. Se guarda en minúsculas; único entre clientes (validado en app). |
| `email` | VARCHAR(160) | Añadida por `ensure_schema` (`db.php:497`) pero no usada en esta área. |
| `partner_id` | INT NULL → `partner_agencies.id` | Marca blanca. NULL = marca propia. |
| `contact_id` | INT NULL → `contacts.id` | Lead del CRM del que salió. |
| `gsc_site_url` | VARCHAR(255) NULL | Propiedad de Search Console (`https://x.com/` o `sc-domain:x.com`). |
| `ga4_property_id` | VARCHAR(40) NULL | Nº de propiedad GA4 (solo dígitos). |
| `ga4_ev_ll`, `ga4_ev_wa`, `ga4_ev_fo` | VARCHAR(255) NULL | Nombres de eventos GA4 (CSV) que cuentan como llamada / WhatsApp / formulario. |
| `met_sync_at` | DATETIME NULL | Última sincronización de métricas. |
| `created_at` | TIMESTAMP | No se muestra. |

> Atención: la migración nueva **no** declara `gsc_site_url`, `ga4_*`, `met_sync_at` en 0001; los crea `gm_ensure_schema()` llamado desde `0002_modulos.php:27`. Tampoco existe `portal_meeting_requests` en las migraciones (ver Riesgos).

### 1.2 `client_types`
`id`, `nombre` VARCHAR(120), `secciones_json` (`{"metricas":0|1,"progreso":0|1,"informes":0|1,"como":0|1,"accesos":0|1,"plan":0|1}`), `created_at`.
Semilla si la tabla está vacía (`db.php:619-630`): «SEO completo» (todas 1), «Solo web» (metricas=0, informes=0, resto 1), «SEM (campañas)» (todas 1); y asigna a los clientes sin tipo: `conversiones=1`→SEO completo, `conversiones=0`→Solo web.

### 1.3 Otras tablas que toca el área
- `partner_agencies`: `id, nombre, logo_url, color, web, email, whatsapp (solo dígitos), meeting_url, telefono, created_at` (`lib/marca.php:20-38`).
- `settings (clave PK, valor)`: claves usadas: `servicios_catalogo` (JSON), `video_id` (YouTube general, def `J9-aEZ523bA`), `video_web|video_seo|video_sem|video_cro|video_tienda|video_meta` (legado), `agency_name|agency_logo|agency_color|agency_web|agency_email|agency_phone`, `meeting_url`, `whatsapp`, `email`, `google_oauth_client_id|google_oauth_client_secret|google_oauth_refresh_token`, `ga4_event_ll` (def `phone_call`), `ga4_event_wa` (def `whatsapp_click`), `ga4_event_fo` (def `generate_lead`), `gm_auto_day` (YYYY-MM-DD), `gcal_client_id|gcal_client_secret` (login Google).
- `task_lists` (`client_id, nombre, es_cliente, tipo ('tareas'|'informe'), orden`) y `tasks` (`client_id, list_id, titulo, titulo_cliente, explicacion_cliente, descripcion, estado, prioridad, mes, due_date, responsable_id, visible_cliente, orden`), `task_assignees (task_id, admin_id, orden)`.
- `client_credentials` (bóveda): `client_id, titulo, categoria (web|correo|hosting|database|api|cms|domain|social|other), usuario, secreto, url, nota, visible_cliente, orden`.
- `support_tickets (id, asunto, cuerpo, client_id, prioridad 1-4, estado abierto|en_curso|esperando|resuelto|cerrado, assignee_id, created_by, created_at, updated_at)`, `support_replies (ticket_id …)`.
- `portal_meeting_requests (id, client_id, fecha_deseada DATE NULL, franja VARCHAR(30), motivo TEXT, estado pendiente|aprobada|rechazada, meeting_id, created_at)` — la crea el portal en caliente (`index.php:74-83`).
- `crm_meetings (id, contact_id, fecha, hora, titulo, estado agendada|realizada|no_show|cancelada)` — enlazada al cliente vía `clients.contact_id`.
- `invoices (id, numero, fecha, fecha_venc, estado borrador|enviada|pagada|vencida, client_id, iva_pct, irpf_pct, efectivo, cond_pago, notas, emisor_json, cliente_nombre, cliente_nif, cliente_dir, cliente_email, cliente_tel)` + `invoice_items (invoice_id, concepto, cantidad, precio)`. Total = Σ(cantidad·precio)·(1+iva/100−irpf/100).
- `contacts` (`id,nombre,empresa,email,telefono,whatsapp,fase,origen_lead,client_id,propietario_id`), `deals`, `accounting`, `time_entries`, `invoice_schedules`, `projects`.
- `trash` (papelera): `id, tabla, ref_id, tipo, titulo, datos (JSON {fila, hijos:[{tabla,fk,filas}]}), admin_id, autor, created_at`. Retención `PAP_DIAS=30`.
- `login_attempts (ident, ip, fails, last_at, blocked_until)` — freno de login.
- `admin_profiles (admin_id, foto)` para avatares de asignados en el portal.

### 1.4 Blobs JSON dentro de `clients`

**`estado_json`** — progreso del proyecto
```json
{"nombre":"Crecimiento y captación de clientes","etiqueta":"Etapa 3 de 4","siguiente":"nuevas páginas de servicio…",
 "fases":[{"t":"Auditoría","s":"y arranque","estado":"done"},{"t":"Crecimiento","s":"y captación","estado":"now"},{"t":"Consolidación","s":"y escala","estado":""}]}
```
`estado` de fase: `done` (Completada) · `now` (Actual / En curso ahora) · `''` (Pendiente). Fases por defecto en alta nueva (`edit.php:48-53`): Auditoría/«y arranque»/done, Base técnica/«y contenidos»/done, Crecimiento/«y captación»/now, Consolidación/«y escala»/''. Al guardar se descartan fases con `t` vacío.
Portal: tarjeta «Estado del proyecto» (nombre, etiqueta como píldora oscura, barra segmentada una celda por fase: `done` rellena, `now` resaltada; etiquetas debajo con la `now` destacada; «**Lo siguiente:** …»).

**`plan_json`**
```json
{"resumen":"Cada mes trabajamos…","items":[{"n":"4","t":"Artículos de blog al mes"}],"detalle":[{"h":"Página de servicio · 1 al mes","p":"Explica…"}]}
```
Se descartan items con `n` y `t` vacíos y detalle con `h` y `p` vacíos. Portal: rejilla 3 columnas de tarjetas (icono rotando 5 estilos, número grande `n`, concepto `t`); tarjeta con `resumen`; acordeón «Ver el detalle completo de lo que incluye» con `<b>h</b><p>p</p>`. Vacío: «Tu plan se mostrará aquí en cuanto tu equipo lo configure.»

**`accesos_json`** — lista de recursos
```json
[{"b":"Diseño en Figma","s":"Mockups de tu web","u":"https://figma.com/…","tipo":"figma"}]
```
`tipo` ∈ `figma|drive|web|looker|generic` (etiquetas: Figma, Google Drive, Sitio web, Looker Studio, Genérico/Otro). Filas con `b` vacío se descartan; `u` vacío → `'#'`. Portal: rejilla 2 columnas de enlaces (favicon de Google `https://www.google.com/s2/favicons?sz=64&domain=<host>` si hay URL; si no, logo SVG por `tipo`), título `b`, descripción `s`, abre en pestaña nueva.

**`informes_json`**
```json
[{"mes":"Junio","titulo":"Informe de junio","texto":"análisis largo…","url":""}]
```
Dos escritores: (1) `publicar_informes()` (`lib/publicar_lib.php:20-46`) lo reconstruye desde las tareas de las listas `tipo='informe'` del cliente (`mes`←`tasks.mes` o 'General', `titulo`←`titulo_cliente`||`titulo`, `texto`←`explicacion_cliente`||`descripcion`, `url`=''), **solo si existe alguna lista de tipo informe**; (2) el editor en vivo (`save-portal.php`). Portal: acordeones en orden inverso (lo último arriba), título + «· mes», texto con `white-space:pre-wrap`, botón «Abrir / descargar» si `url`.

**`servicios_json`**: `["SEO","Diseño web"]` o `NULL`. **NULL = todos desbloqueados**; array (aunque vacío) = solo esos. Se escribe desde el editor en vivo (checkboxes de los 6 servicios fijos) y desde el puente CRM (`servicio_json` del lead). El renombrado/borrado en el catálogo lo propaga (`svc_renombrar_en_clientes`).

**`met_json`** — métricas por mes, clave = **nombre de mes en español sin año** (`Enero`…`Diciembre`), en orden de inserción:
```json
{"Mayo":{"ll":12,"wa":30,"fo":5,"vi":840,"ap":21000,"ctr":4.0,
         "src":{"Organic Search":500,"Direct":120},"geo":{"ES":657,"US":12}}}
```
`ll` llamadas, `wa` WhatsApp, `fo` formularios (GA4, suma de eventos configurados); `vi` clics, `ap` impresiones, `ctr` % 2 decimales (Search Console); `src` sesiones por canal GA4 (`sessionDefaultChannelGroup`, top 12); `geo` sesiones por país ISO-2 (top 250). Solo lo escribe `gm_sync_client()`; `edit.php` y `save-portal.php` **no lo tocan nunca** (explícito, `edit.php:133-135`, `save-portal.php:35`). Portal: «oportunidades» = ll+wa+fo.

**`tareas_json`** — progreso «explicado» por mes:
```json
{"Junio":{"completado":[{"t":"Mejoras de SEO técnico","d":"explicación para el cliente"}],"pendiente":[{"t":"…","d":"…"}]}}
```
Escritores: `edit.php` (sección «Progreso del cliente»), editor en vivo, y `publicar_progreso()` (`publicar_lib.php:51-71`), que lo **reconstruye entero** desde `tasks` con `visible_cliente=1` o lista `es_cliente=1` (grupo `completado` si `estado='completada'`, si no `pendiente`; `t`=`titulo_cliente`||`titulo`; `d`=`explicacion_cliente`; mes vacío→'General'), y luego llama a `publicar_informes()`. Lo invocan las pantallas de tareas (fuera de este área) tras crear/editar/completar/mover/borrar.

---

## 2. Permisos y alcance

Catálogo (`admin/lib/permisos.php:43-114`):
- `ver.clientes` — «Las fichas de cliente y el panel de inicio.»
- `clientes.crear` — «Crear clientes nuevos y duplicarlos.»
- `clientes.editar` — «Cambiar sus datos, accesos y métricas.»
- `clientes.borrar` — «Mandarlos a la papelera con todo lo suyo.»
- `clientes.portal` — «Cambiar lo que el cliente ve: métricas, fases, informes.»
- `general.editar` («Guardar cambios», base para escribir; `can_edit()` = este permiso, `auth.php:110-114`).
- `alcance.todos` («Ve todos los clientes»), `ver.importes` (sin él los euros salen como `·····` vía `eur_vis()`).
- `ver.ajustes`, `tipos.editar`, `servicios.editar`, `marca.editar`, `ver.finanzas`, `datos.avanzado`, `admin.total` (puede todo).

Permiso por página (`perm_de_pagina`, `permisos.php:665-708`) y por acción POST (`perm_de_accion`, `:718-751`), aplicados automáticamente en `require_admin()`:

| Archivo | Página (GET) | POST |
|---|---|---|
| `index.php`, `client.php` | `ver.clientes` | `client.php` reset_pass: solo `general.editar` |
| `edit.php` | `ver.clientes` + `require_can_edit()` al cargar (incluso GET) | `clientes.editar` (también para **crear**) |
| `duplicate.php` | `ver.clientes` | `clientes.crear` |
| `delete.php` | `clientes.borrar` | `clientes.borrar` |
| `save-portal.php` | — | `clientes.portal` + `general.editar` |
| `conversiones.php` | `ver.clientes` | guarda si `can_edit()` |
| `types.php`, `type-edit.php`, `type-delete.php` | `ver.ajustes` | `tipos.editar` (+ `general.editar`) |
| `servicios.php` | `ver.ajustes` | `servicios.editar` |
| `agencias.php` | `ver.ajustes` | `marca.editar` |
| `metricas.php` | `ver.ajustes` | `can_edit()` |
| `pricing.php` | `ver.finanzas` | — |
| `data.php` | `datos.avanzado` + re-autenticación por contraseña (`lib/reauth.php`) | — |

Denegación: HTTP 403; si la petición es JSON (Accept json / X-CSRF-Token / XHR) responde `{"ok":false,"error":"permiso","msg":"No tienes permiso para esto."}`; si no, pantalla «no tienes permiso» (`permisos.php:446-458`).

**Alcance** (`permisos.php:572-631`): sin `alcance.todos`, el usuario solo ve clientes donde (a) es `responsable_id` de alguna tarea del cliente, (b) está en `task_assignees` de alguna tarea del cliente, o (c) es `propietario_id` de un contacto con ese `client_id`. Aplicado en: listado (`alcance_sql('c.id')`), ficha/edición (`alcance_exigir_cliente` → 403), borrar/duplicar/save-portal/conversiones/vista previa del portal/factura (redirigen o JSON error). **No** se aplica en `agencias.php`, `metricas.php`, `types.php`, `servicios.php`, ni en la lista de clientes de `login.php` en modo equipo (riesgo).

CSRF: todo POST que pase por `auth.php` exige `_csrf` (form) o cabecera `X-CSRF-Token`; fallo → 419 (`{"ok":false,"error":"csrf","msg":"La sesión ha caducado. Recarga la página."}`).

---

## 3. Pantallas del equipo

### 3.1 Listado de clientes — `admin/index.php`

**Propósito**: lista de todos los clientes (dentro del alcance) con filtros y atajos.
**URL**: `admin/index.php` (acepta `?msg=guardado|creado|cliente-eliminado|error-borrado` → toast global: «Cambios guardados.», «Creado correctamente.», «Cliente eliminado.», «No se ha podido borrar.»).
**Datos** (`index.php:10-31`), una consulta por métrica (sin N+1):
- clientes: `id, name, username, iniciales, conversiones, COALESCE(activo,1), tipo_id, tipo_nombre` ordenados por `name`.
- tipos: `id, nombre` por nombre.
- tareas abiertas por cliente: `tasks.estado<>'completada'`.
- tickets abiertos: `support_tickets.estado IN ('abierto','en_curso','esperando')`.
- pendiente de cobro: Σ total de facturas con `estado NOT IN ('borrador','pagada')`.

**Layout**
- Cabecera: H1 «Clientes en alta»; subtítulo «`N` cliente(s) a la vista.» (N = filas visibles tras filtrar, se actualiza en vivo; inicial = nº activos). Botón primario «+ Nuevo cliente» → `edit.php` (solo `can_edit`).
- Barra de filtros (solo si hay clientes):
  - Segmentado `#cliSeg` con contadores: «En alta `nAct`» (por defecto), «No activos `nNo`», «Todos `total`».
  - Buscador con lupa, placeholder «Buscar por nombre o usuario…» (busca subcadena en `lower(name+' '+username)`).
  - Select de tipo: «Todos los tipos» / cada tipo / «— Sin tipo —».
  - Todo el filtrado es **en cliente** (sin recarga), combinación AND.
- Tarjeta con tabla `Cliente | Tipo | Actividad | (acciones)`:
  - Cliente: avatar cuadrado 34px r9 color `avatar_color(name)` con iniciales (2 letras de `iniciales` o de `name`), nombre enlazado (color acento, 600) → `client.php?id=`. Etiquetas en línea: «No activo» (fondo `#feecec`, texto `#c0343a`) si inactivo; «Conversiones» (tag neutro) si `conversiones=1`.
  - Tipo: tag «on» con el nombre o tag gris «Sin tipo».
  - Actividad: chips «✓ N tarea(s)», «🎫 N ticket(s)», «€ importe» (este último solo con `ver.importes`); si nada → «Sin actividad» (muted 12px).
  - Acciones (aparecen al hover; siempre visibles <760px): ojo «Abrir su ficha», tareas «Sus tareas» → `workspace.php?view=cliente&cli=`, euro «Sus facturas» → `facturas.php?cli=`; con `can_edit`: lápiz «Editar sus datos» → `edit.php?id=`, capas «Duplicar» (confirmación «¿Crear una copia de X? Podrás editarla después.», botón «Duplicar», POST `duplicate.php {id}`).
  - Filas inactivas: fondo `--line2`, avatar al 55%.
  - Fila vacía de filtro: «No hay clientes que coincidan con la búsqueda.»
- Menú contextual (clic derecho en la fila, no sobre enlaces): «Abrir ficha», «Tareas del cliente», «Facturas»; con `can_edit`: separador, «Editar datos», «Duplicar»; separador, «Borrar cliente» (rojo) → confirmación título «¿Borrar «X»?», texto «Se borran también sus tareas, listas, tickets y accesos. Queda 30 días en la papelera por si acaso.», botón «Borrar» (danger) → POST `delete.php {id}`.
- Estado vacío (sin clientes): icono, «Aún no hay clientes»; con edición «Crea el primero para montar su portal, sus tareas y su facturación.» + botón «Nuevo cliente»; sin edición «Cuando el equipo dé de alta un cliente, aparecerá aquí.»
- Móvil ≤640px: cada cliente es una fila plana (sin thead, sin columna de acciones), segmentado a ancho completo, buscador en su propia línea.

Sin paginación ni ordenación configurable (orden fijo por nombre).

### 3.2 Ficha / hub del cliente — `admin/client.php?id=N`

**Acceso**: `ver.clientes` + alcance (403). Si no existe → redirige a `index.php`. Parámetros: `id`, `dup=1` (aviso tras duplicar).

**Datos** (`client.php:30-102`): cliente + tipo; blobs `estado/plan/accesos/met/tareas`; listas de trabajo (`task_lists` del cliente con `cnt` y `pend`); nº credenciales y las 6 primeras (`orden,id`); facturas (todas, para totales; muestra 5 últimas por `fecha DESC,id DESC`); tickets (orden por estado abierto→cerrado, prioridad desc, `updated_at` desc; muestra 4); contacto de origen (`contacts` por `clients.contact_id`, o el primer contacto con `client_id`).

**Acción POST** `action=reset_pass` (solo `can_edit`): genera contraseña `pu_password()` = 6 letras minúsculas sin l/i/o + 2 dígitos 10-99 (`puentes.php:71-76`), guarda hash, la guarda en sesión de un solo uso y redirige a la ficha. Se muestra una vez en caja verde: «Contraseña nueva de **usuario**: `xxxxxx42`» + «Apúntala y pásasela al cliente: no se vuelve a mostrar.» Confirmación previa: título «¿Restablecer contraseña?», texto «Se generará una contraseña nueva y la actual dejará de valer. Tendrás que pasársela al cliente.», botón «Restablecer».

**Layout** (de arriba a abajo):
1. **Cabecera**: avatar 54px r16 (color por nombre, 2 iniciales del nombre), H1 nombre (25px). Chips píldora: tipo o «Sin tipo»; «usuario: X»; «Con métricas» / «Solo web» (según `conversiones`); «Mes: X» si `actual`. Botones: «👁 Ver como cliente» → `../index.php?cli=N` (nueva pestaña), «← Clientes».
2. Aviso si `dup`: «Copia creada. Cámbiale el **usuario** y la **contraseña** entrando en “Editar ficha”.»
3. **Acciones operativas** (rejilla 6 col; 3 col ≤1080; 2 col ≤600). Cada tarjeta: cuadrado de color con icono, título, subtítulo.
   - Con `can_edit`: «Editar en vivo / Su portal con lápices» (#64748b → `../index.php?cli=N&edit=1`); «Nueva tarea / Popup rápido» (#e0a000, abre modal global de tarea con `{clientId, clientName, lists:[{id,nombre}]}`); «Agendar reunión / Popup rápido» (#4285F4, modal global con `titulo:'Reunión con '+name`, `email` = `fact_email` o email del contacto, `whatsapp` del contacto, `nombre`, `contactId`); «Informe del mes / Lo que verá en su portal» (#0ea5e9 → `workspace.php?view=cliente&cli=N&informe=1`); si `conversiones`: «Métricas / Web · Analytics · conversiones» (#a855f7 → `conversiones.php?cli=N`); «Nueva factura / Emitir y cobrar» (#34c759 → `facturas.php?new=1&cli=N`); «Abrir ticket / Popup rápido» (#ef4444, modal global de ticket); «Editar ficha / Campo a campo» (#5e5ce6 → `edit.php?id=N`).
   - Sin `can_edit`: «Tareas / Backlog del cliente», «Facturas / Ver del cliente», «Soporte / Tickets del cliente».
4. **Resumen** (4 KPIs): «Oportunidades · {último mes de met_json}» = ll+wa+fo del último mes (o «—»); «Tareas en curso» = nº de `pendiente` en `tareas_json`; «Soporte abierto» (azul `#3b82f6` si >0); «Cobrado» (Σ facturas pagadas, oculto `·····` sin `ver.importes`).
5. **Rejilla 2 columnas** (1 col ≤1024). Tarjetas con cabecera en mayúsculas pequeñas + enlace «Abrir →» a la derecha. La última tarjeta de cada columna se estira para igualar alturas.
   - Columna izq.:
     - «Tareas y backlog» → filas por lista: icono, nombre (+ mini-tag «cliente» si `es_cliente`), «`pend` abiertas · `cnt`»; enlace `workspace.php?view=cliente&cli=N&list=L`. Vacío: «Sin listas de trabajo todavía.»
     - «Facturas»: fila resumen «N factura(s)» + «Cobrado X · Pendiente Y»; luego hasta 5 facturas: número, fecha dd/mm/aaaa · estado coloreado (Borrador #9aa0a8, Enviada #3b82f6, Pagada #12a150, Vencida #ef4444), total a la derecha → `facturas.php?v=ID`.
     - «Soporte»: hasta 4 tickets: asunto, «#id · prioridad · dd/mm/aaaa», badge de estado (Abierto #3b82f6, En curso #7b68ee, Esperando #e0a000, Resuelto #12a150, Cerrado #9aa0a8; fondo = color+`18` alfa). Prioridades 1 Baja #94a3b8, 2 Normal #3b82f6, 3 Alta #f59e0b, 4 Urgente #ef4444. Vacío: «Sin tickets · abrir uno →».
     - «Contacto de origen» (solo si hay): nombre, «empresa · email · teléfono|whatsapp» o «Sin datos de contacto», `origen_lead` a la derecha → `crm.php?open=ID`.
   - Columna dcha.:
     - «Acceso al portal»: «Usuario: X» / «Entra en el portal del cliente» + botón «⚡ Restablecer» + caja de contraseña nueva (ver arriba).
     - «Datos fiscales» (enlace «Editar →» a `edit.php?id=N#fact`): rejilla 2×2: Nombre fiscal (o `name`), NIF / CIF, Dirección, Email factura; vacíos «—» en gris. Aviso si faltan y hay facturas: «Faltan N dato(s) de facturación y ya hay facturas emitidas.»
     - «Credenciales · acceso rápido» (enlace «Ver las N →» si hay más de 6, si no «Abrir bóveda →» → `credenciales.php?cli=N`): tarjetas (auto-fill min 230px) con icono por categoría, título, CATEGORÍA, campo Usuario (botón copiar), Contraseña enmascarada `••••••••••••` (botón ojo para mostrar/ocultar, botón copiar con tic verde y toast «Copiado ✓»), enlace «Acceder al servicio». Vacío: «Sin credenciales guardadas. Registrar la primera →».
     - «Estado del proyecto» (solo si hay nombre o fases): nombre, barra de segmentos (on si `done|now`), «Lo siguiente: …».
     - «Plan contratado» (solo si hay items): filas `t` … `n`.
6. **Zona peligro** (`can_edit`): «¿Dar de baja a este cliente?» + botón rojo «Eliminar cliente» → confirmación «¿Eliminar a X? Se borrarán sus tareas, listas y credenciales. Queda 30 días en la papelera.» → POST `delete.php`.

### 3.3 Alta / edición — `admin/edit.php[?id=N]`

**Acceso**: `require_can_edit()` al cargar; POST exige `clientes.editar`; alcance si `id`. Título «Nuevo cliente» / «Editar cliente», subtítulo «Rellena la ficha; el portal del cliente se arma con esto.»; botón «← Volver a la ficha» (si edita).

**Navegación**: segmentado con 6 secciones (solo una visible; todas viajan en el mismo formulario): «Datos», «Facturación», «Progreso», «Plan», «Accesos», «Progreso del cliente». `#fact` en la URL abre Facturación. Barra fija inferior: «✓ Guardar cliente», «Cancelar» (→ ficha o listado), nota «Se guardan todas las secciones a la vez, no solo la que estés viendo.»

**Sección Datos** («Datos del cliente» / «Quién es y cómo entra a su portal.»):
- Zona «El negocio»: «Nombre del negocio» `name` (requerido), «Saludo» `saludo` (placeholder «Ej: María», hint «Cómo le saluda el portal.»), «Iniciales» `iniciales` (max 4, placeholder «CA», hint «Su avatar.»).
- Zona «Cómo entra a su portal»: «Usuario» `username` (req.), «Contraseña» `password` (requerida al crear; al editar placeholder «Déjalo vacío para no cambiarla»), «Mes actual» `actual` (placeholder «Junio», hint «El mes que abre por defecto.»), «Correo de Google para entrar» `login_email` (hint «Si pones aquí su Gmail, podrá entrar al portal pulsando «Entrar con Google», sin contraseña. Déjalo vacío si no lo usa.»).
- Zona «Qué ve en su portal»: select «Tipo de cliente» (`— Sin tipo —` + tipos) + botón «+ Nuevo tipo» (alta rápida, ver 3.6) + hint con enlace a Tipos de cliente.
- Interruptor «Tiene conversiones» — «Enseña Métricas y oportunidades en su portal. Apágalo en proyectos de solo web.» Si edita: botón «Configurar sus métricas de Google (web, Analytics y conversiones)» → `conversiones.php?cli=N`.
- Interruptor «Cliente activo» — «Si lo apagas, pasa a «Clientes no activos» y deja de contar como cliente en alta.» (por defecto encendido).

**Sección Facturación** («Datos de facturación» / «Se copian solos a cada factura que le hagas. También se rellenan al crear la primera.»): `fact_nombre` (placeholder = nombre), `fact_nif` («B12345678»), `fact_dir` («Calle, número, código postal y ciudad»), `fact_email`.

**Sección Progreso** («Progreso del proyecto» / «La etapa y las fases que ve el cliente en la cabecera de su portal.»): `est_nombre`, `est_etiqueta` («Etapa 3 de 4»), `est_siguiente`; lista repetible «Fases» (columnas Nombre de la fase / Subtítulo / Estado [Completada|Actual|Pendiente]) con «+ Añadir fase» y papelera por fila (aparece al hover).

**Sección Plan** («Plan contratado» / «Lo que el cliente ve en la sección «Plan» de su portal.»): textarea `plan_resumen`; lista «Lo que incluye · número + concepto» (`item_n[]` 90px, `item_t[]`); lista «Detalle completo · título + texto» (`det_h[]`, `det_p[]` textarea).

**Sección Accesos** («Accesos» / «Los enlaces y herramientas que el cliente abre desde su portal (Figma, Drive, su web…).»): filas Título/Descripción/Enlace (url)/Tipo.

**Sección Progreso del cliente** («Lo que ve en su sección de Progreso: qué se ha hecho y qué está en curso cada mes, explicado para él.»): filas apiladas Mes (130px, negrita) / Estado [Completado|En curso] / Título + textarea «Explicación para el cliente». Se agrupa por mes al guardar.

Listas repetibles: campos sin borde hasta hover/focus (estilo tabla limpia), botón «+ Añadir …» como fila final.

**Guardado (POST)** (`edit.php:79-185`):
- Validaciones (mensajes en `.err-note` con «• …»): «El nombre es obligatorio.», «El usuario es obligatorio.», «Pon una contraseña para el cliente.» (solo alta), «Ese usuario ya existe, elige otro.» (único en `clients.username` excluyendo id), «Ese correo de Google ya está en otro cliente.» (`login_email` en minúsculas único). Si hay errores, se repinta con lo escrito.
- Campos escritos: `username, name, iniciales (def 'CL'), saludo, conversiones (checkbox), actual, tipo_id (''→NULL), fact_nombre, fact_nif, fact_dir, fact_email, login_email (lower), activo (checkbox), estado_json, plan_json, accesos_json, tareas_json` (+ `password_hash` si se escribió contraseña). **Nunca** `met_json`, `informes_json`, `servicios_json`, `looker_url`, `partner_id`, `ga4_*`.
- Alta: INSERT; luego crea 4 listas (`task_lists`): `TAREAS` (tareas, orden 0), `ESTRATEGIA` (1), `TAREA CLIENTE` (2), `INFORMES CLIENTE` (tipo `informe`, 3), todas `es_cliente=0`; notificación a los dueños `notif_client_new` (tipo info, «nuevo cliente de alta», nombre, enlace `client.php?id=`, ref `clinew:ID`).
- Redirige a `index.php?msg=guardado` (edición) o `?msg=creado` (alta).
- No hay audit_log en el legado.

### 3.4 Borrar — `admin/delete.php` (POST `{id}`)

`clientes.borrar` + CSRF + alcance. En transacción (`delete.php:18-121`):
1. Borra los hijos de las tareas del cliente (comentarios, checklist, adjuntos, reacciones) sin guardarlos en papelera (`pap_borrar_hijos_tareas`).
2. Horas (`time_entries`): **no se borran**. Antes de soltar referencias, rellena `concepto` vacío o genérico («Horas de la tarea») con `"{titulo tarea} · {nombre cliente}"` o `"Horas de {cliente}"`; luego `client_id=NULL`, `task_id=NULL`.
3. Papelera: guarda la fila del cliente + hijos de `tasks, task_lists, client_credentials, support_tickets, invoice_schedules, projects` (por `client_id`) en `trash` (tipo `cliente`, título = nombre) y deja aviso con «Deshacer» («Cliente «X» eliminado»).
4. DELETE de esas 6 tablas por `client_id`; borra `support_replies` huérfanas.
5. Facturas: asegura `invoices.cliente_nombre` (snapshot) y luego `client_id=NULL` en `accounting, invoices, contacts, deals`.
6. DELETE del cliente; commit → `index.php?msg=cliente-eliminado`. Error → rollback → `client.php?id=N&msg=error-borrado`.
Restaurar desde papelera (otra área) reinserta fila e hijos (no las referencias soltadas).

### 3.5 Duplicar — `admin/duplicate.php` (POST `{id}`)

`clientes.crear` + alcance. Usuario nuevo `"{username}_copia"`, si existe `_copia2`, `_copia3`… (a partir de 200 → `_copia_{timestamp}`). INSERT copiando: `password_hash, name+' (copia)', iniciales, saludo, conversiones, tipo_id, actual, estado_json, plan_json, accesos_json, met_json, tareas_json`. **No** copia datos fiscales, `informes_json`, `servicios_json`, `looker_url`, `partner_id`, `activo`(→default 1), `login_email`, `ga4_*`, ni crea listas de tareas. Redirige a `client.php?id=NEW&dup=1`.

### 3.6 Tipos de cliente

**`types.php`** (dentro del marco «Ajustes», menú lateral de ajustes; `ver.ajustes`). Cabecera «Tipos de cliente» / «Cada tipo decide qué secciones ve el cliente en su portal. Inicio se ve siempre.» + botón «+ Nuevo tipo» (`can_edit`).
Lista (tarjeta con filas): columna izq. nombre + «N cliente(s)» o «Sin clientes asignados»; centro: tags de secciones — «Inicio» (siempre on) y Métricas, Progreso, Informes, Método, Accesos, Plan (on = tag sólido; off = tag con borde discontinuo gris); dcha.: «Editar» → `type-edit.php?id=`, «Eliminar» (danger) → confirmación título «Eliminar tipo», texto «¿Eliminar el tipo X? Los N clientes con este tipo se quedarán sin tipo.» → POST `type-delete.php {id}`. Vacío: «Todavía no hay ningún tipo» / «Un tipo agrupa a los clientes que ven lo mismo en su portal. Por ejemplo «SEO completo», «Solo web» o «Mantenimiento».» + «Crear el primer tipo».

**`type-edit.php[?id=N]`**: cabecera «Editar «Nombre»» con «Lo que marques aquí cambia el portal de **N clientes** al momento.» o «Nuevo tipo de cliente» / «Un tipo agrupa…»; botón «← Volver».
- Tarjeta 1: «Nombre del tipo» (req., placeholder «Ej: SEO completo, Solo web, Mantenimiento», hint «Solo lo ves tú, en la ficha de cada cliente.»).
- Tarjeta 2 «Qué ve el cliente en su portal» / «Apaga lo que no quieras que vea. Se le oculta la sección entera del menú de su portal.» Fila fija «Inicio — El resumen del mes y los avisos. Se ve siempre.» con tag «Fija». Luego 6 filas con icono, título, descripción e interruptor (fila en gris si off):
  - metricas «Métricas» — «Llamadas, WhatsApps, formularios y visitas, mes a mes.»
  - progreso «Progreso» — «Las fases del trabajo y en cuál va ahora mismo.»
  - informes «Informes» — «Los informes mensuales que le subes.»
  - como «Método» — «Cómo trabajáis, con el vídeo de presentación.»
  - accesos «Accesos» — «Las claves y enlaces que le has dejado preparados.»
  - plan «Plan» — «Qué incluye lo que tiene contratado.»
  - Hint: «Si apagas **Métricas**, también desaparecen las oportunidades del Inicio: salen de ahí.»
- Botones «✓ Guardar cambios»/«Crear tipo», «Cancelar». Validación «Pon un nombre al tipo.». Default de alta: todas a 1. POST guarda `secciones_json` con las 6 claves 0/1 → `types.php?ok=1`.
- **Alta rápida AJAX**: `POST type-edit.php?ajax=create` body `nombre` → `{ok:true,id,nombre}`; si ya existe un tipo con ese nombre exacto → `{ok:true,id,nombre,dup:true}`; vacío → `{ok:false,msg:'Pon un nombre al tipo.'}`. Crea con todas las secciones a 1. La usa `edit.php` (prompt «Nuevo tipo de cliente», texto «Se crea al momento con todas las secciones visibles. Podrás afinar qué ve cada tipo en Ajustes › Tipos de cliente.», placeholder «Ej: SEO completo, Solo web…», botón «Crear tipo»; toasts «Tipo «X» creado.» / «Ese tipo ya existía: seleccionado.» / «No se pudo crear el tipo.» / «Error de conexión.»).

**`type-delete.php`** (POST `{id}`): `UPDATE clients SET tipo_id=NULL WHERE tipo_id=?`, DELETE tipo → `types.php?msg=tipo-eliminado` («Tipo eliminado.»). Sin papelera.

### 3.7 Catálogo de servicios — `admin/servicios.php`

Guardado en `settings.servicios_catalogo` = `[{nombre, desc, video}]` (video = id de YouTube). Por defecto (`servicios_cat.php:21-30`): SEO, SEM, CRO, Diseño web, Tiendas online, Meta Ads con sus descripciones. Si una fila no tiene `video` y su nombre está en el mapa legado (`Diseño web→video_web, SEO→video_seo, SEM→video_sem, CRO→video_cro, Tiendas online→video_tienda, Meta|Meta Ads→video_meta`) usa ese setting.
**Uso por servicio** (`svc_uso`): `por[nombre]` = clientes con lista que lo incluye; `abiertos` = clientes con `servicios_json` NULL/vacío/no-array (ven todos); `svc_uso_de = por + abiertos`.

**Layout** (marco Ajustes): subtítulo «Lo que ofreces y lo que le puedes asignar a cada cliente. Está disponible al crear presupuestos y programaciones, y decide qué secciones ve cada cliente en su portal.» Aviso tras guardar «Catálogo guardado.» (+ «Se ha cambiado el nombre en la ficha de N cliente(s).» si hubo renombrados). Tarjeta: «N servicio(s) en el catálogo»; si `abiertos>0`: «A de tus T clientes no tienen la lista de servicios personalizada, así que **ven todos**. Se elige cliente a cliente desde su portal.» Tabla de 3 columnas «Servicio | Descripción corta | Estado»: input nombre (+ hidden `orig[]`), input desc («Para qué es, en una línea»), tag «N cliente(s)» (on si >0), tag «👁 Vídeo» si tiene vídeo, papelera (hover) «Quitar del catálogo». Fila nueva con tag «Nuevo». Botón fila «+ Añadir servicio». Pie: «✓ Guardar catálogo» + «Al cambiarle el nombre a un servicio, se cambia también en la ficha de los clientes que lo tengan. El vídeo de cada uno se pone en Vídeos.» (enlace `settings.php?tab=videos`). Sin permiso: inputs deshabilitados y «Solo lectura: no puedes cambiar el catálogo con tu permiso actual.»
Quitar uno con clientes: confirmación título «Quitar del catálogo», ««X» lo tienen N cliente(s) contratado. Si lo quitas del catálogo, dejará de aparecer en su portal.», botón «Quitar igualmente» (danger).

**POST** (`nombre[]`, `desc[]`, `orig[]`): por cada fila no vacía, si `orig≠nombre` → `svc_renombrar_en_clientes(orig, nombre)` (reescribe `servicios_json` de cada cliente que lo tenga, deduplicando); servicios que desaparecen → `svc_renombrar_en_clientes(nombre,'')` (lo quita). `svc_guardar` conserva `video` previo por nombre (y `desc` si no viene). Redirige `servicios.php?ok=1&r={renombrados}&c={clientes afectados}`.

### 3.8 Marca blanca — `admin/agencias.php`

Marco Ajustes, título «Marca blanca», subtítulo «Los clientes asignados a una agencia ven su portal e informes con el logo de esa agencia y escriben a su WhatsApp y su enlace de reuniones, no a los tuyos. Lo que no se rellene aquí cae a los datos de {agencia}.» Botón «+ Nueva agencia».
- Rejilla de tarjetas de agencia: logo (imagen o inicial sobre `color` o `avatar_color`), nombre, «web · email» o «Sin datos de contacto», «N cliente(s)», enlace de acceso con su marca `{BASE}/login.php?m={id}` + botón «Copiar» («Copiado»). Acciones (editar engranaje, eliminar con «¿Eliminar agencia? Los clientes volverán a tu marca.»). Vacío: «Aún no hay agencias colaboradoras. Añade la primera →».
- «Asignar clientes»: tabla «Cliente | Agencia (white-label)» con select por cliente («— Tu marca (Casa) —» + agencias); al cambiar → POST AJAX `action=assign&client_id&partner_id` → `{ok:1}`; toasts «Cliente asignado» / «No se pudo asignar».
- Modal «Nueva agencia/Editar agencia»: sección «La agencia»: Nombre (req.), Color de marca («#7b68ee»), Logo (URL); sección «Contacto que ve el cliente en su portal»: Email, Teléfono, Web, WhatsApp («34600000000», se guardan solo dígitos), Enlace para pedir reunión («https://calendly.com/…»). Hint «Lo que se deje vacío cae a los datos de {Casa} (apartado Agencia).»
- POST `action=save` (`id` opcional) INSERT/UPDATE; `action=del` → `clients.partner_id=NULL` y DELETE; `action=assign` (JSON).
**Resolución de marca** (`lib/marca.php`): `marca_partner(pid)` → `{name, initial, logo, color, web, propia}`; campo vacío de la agencia cae al de la casa (`agency_name` def «Croilab», `agency_logo`, `agency_color`, `agency_web`). `marca_contacto(pid)` → `{meeting_url, whatsapp, email, telefono}` cayendo a settings `meeting_url`, `whatsapp`, `email`||`agency_email`, `agency_phone`.

### 3.9 Calculadora de precios — `admin/pricing.php`

`ver.finanzas`. Solo cliente (JS), sin persistencia. H1 «Calculadora de precios», «Arma un presupuesto, calcula el precio y comprueba tu margen antes de enviarlo.» Segmentado «Proyecto puntual | Cuota mensual» (solo añade « / mes» al total).
- Tarjeta «Servicios»: líneas Concepto (datalist con nombres del catálogo o default SEO, SEM, CRO, Diseño web, Tienda online, Meta Ads, Mantenimiento) / Cant. / Precio / Total / ✕; «＋ Añadir línea». Empieza con una línea vacía.
- Tarjeta «Coste interno»: Horas estimadas (0), Coste por hora (15 €), Gastos fijos / herramientas (0 €) → «Coste total del trabajo».
- Resumen: Descuento % (0), IVA % (21); Subtotal, Descuento (−), Base imponible, IVA, **Total**; bloque «Beneficio» `base−coste` y % sobre base con barra; notas: coste 0 → «Añade el coste interno para ver tu margen real.»; beneficio<0 → «Estás perdiendo dinero: el precio no cubre el coste.» (rojo); <35% → «Margen ajustado. Lo sano en agencia suele ser 40–60%.» (ámbar); si no «Margen saludable sobre la base imponible.». Ayudante «Con margen del [60]%» (máx 95) → «precio (base) recomendado» = coste/(1−m).
- Formato euros `1.234,56 €`.

### 3.10 Métricas de Google de un cliente — `admin/conversiones.php?cli=N`

`ver.clientes` + alcance; guardar con `can_edit`. Lienzo gris. Sin cliente válido → estado vacío «No encuentro ese cliente» / «El enlace no lleva a ningún cliente válido.» + «Ver mis clientes».
Layout: «← Volver a la ficha de X»; H1 «Métricas de X»; lead «Todo lo de Google de este cliente en un solo sitio. Rellena lo que tengas y pulsa **Guardar cambios**. Cada apartado tiene un **?** con la explicación.» Flash «Cambios guardados.» (`?ok=1`). Aviso si Google no está conectado: «⚠️ Todavía no está conectado Google en el ERP. Puedes dejar esto preparado, pero para ver la lista de eventos hay que conectarlo una vez en Métricas de Google.»
- Fila de 2 tarjetas: «Su web en Google» (input `site`, placeholder `https://sucliente.com/`, hint «Ponla igual que aparece en Search Console (a veces es `sc-domain:sucliente.com`).»; tooltip ? explicativo) y «Número de Analytics (GA4)» (`prop`, «ej: 313888031», «Solo números.»).
- Tarjeta «¿Qué cuenta como cada contacto?»: 3 filas objetivo «📞 Llamadas / Clic en el teléfono», «💬 WhatsApp / Clic en WhatsApp», «📝 Formularios / Formulario enviado», cada una con chips de eventos asignados (× para quitar) o «Sin asignar». Botón «🔍 Ver mis eventos de Analytics» → carga lista: por evento «nombre **N veces**» + 3 botones emoji (📞 💬 📝) para asignarlo (un evento solo puede estar en un tipo; volver a pulsar lo desasigna). Vacío: «No se han encontrado eventos en los últimos 90 días. Comprueba el número de Analytics.»
- «✓ Guardar cambios» (serializa asignaciones a CSV en hidden `ev_ll/ev_wa/ev_fo`).
**JSON**: `GET conversiones.php?ajax=ga4_events&cli=N&prop=XXX` → `{ok:true, events:[{name,n}]}` (top 100 por `eventCount`, últimos 90 días) | `{ok:false,msg}` («Cliente no encontrado.», «Falta el número de Analytics de este cliente.», «El ERP aún no está conectado con Google. Ve a Métricas de Google y conéctalo una vez.»).
**POST**: `UPDATE clients SET gsc_site_url, ga4_property_id, ga4_ev_ll, ga4_ev_wa, ga4_ev_fo` (vacío→NULL) → `?ok=1`. No lanza sincronización.

### 3.11 Métricas de Google (ajustes) — `admin/metricas.php` + `admin/cron_metricas.php`

`ver.ajustes`. Cabecera con logo de Google, «Métricas de Google», «El ERP trae de Google las **visitas y apariciones** (Search Console) y las **conversiones** (Analytics) de cada cliente y las muestra en su portal. Se actualiza **solo, una vez al día**.» Si conectado y `can_edit`: botón «⚡ Actualizar ahora» (confirmación «El ERP va a leer Google de todos tus clientes. Puede tardar un poco. ¿Seguimos?», «Sí, traer datos») + «Última: dd/mm/aaaa» (max `met_sync_at`). Sin conexión: «Todavía no está conectado con Google. Conéctalo en Integraciones (se hace una sola vez)…» (→ `integraciones.php?i=gmet`).
- «Tus clientes» (rejilla 2 col): punto verde si tiene web, nombre, chips «Web», «Analytics», «Conversiones» (si `conversiones` y algún evento), «Sin configurar», «· dd/mm/aaaa» de sync; botón «⚙ Configurar» → `conversiones.php?cli=`. Texto con «(X de Y)» clientes con web.
- `<details>` «Opciones avanzadas (no hace falta tocar)»: eventos por defecto 📞/💬/📝 → POST `action=save_adv` («Opciones avanzadas guardadas.»). Nota «Actualización automática mensual (para el técnico): `php admin/cron_metricas.php`».
- POST `action=sync_one {id}` («Actualizado.») y `action=sync_all` («Listo: N cliente(s) actualizados, M con aviso.»). Todos redirigen `metricas.php?ok={mensaje}&t=ok`.
**Motor de sincronización** (`lib/google_metrics.php:203-239`): para cada mes `YYYY-MM` (por defecto mes actual y anterior): rango del 1 al último día; Search Console `searchAnalytics/query` sin dimensiones → `vi,ap,ctr`; GA4 `runReport` `eventCount` filtrado por `eventName in (lista)` → `ll,wa,fo` (eventos del cliente o defaults globales); canales y países. Fusiona en `met_json[NombreMes]` sin borrar otras claves; pone defaults 0; actualiza `met_sync_at=NOW()`; si `actual` vacío lo fija al último mes tratado. `gm_sync_all` recorre clientes con `gsc_site_url` o `ga4_property_id` y marca `settings.gm_auto_day=hoy`.
**Disparadores**: (1) `gm_auto_daily()` al final de cada página del ERP, solo si no se hizo hoy, Google conectado y existe `fastcgi_finish_request`/`litespeed_finish_request` (responde primero y luego sincroniza); (2) cron CLI `php admin/cron_metricas.php` (solo `cli`, 403 por web; imprime `fecha · métricas: N ok, M avisos` y detalle; exit 1 si no hay conexión); (3) botones manuales.
OAuth de métricas: scopes `webmasters.readonly analytics.readonly`, redirect `/admin/gmet_callback.php`, refresh token permanente en `google_oauth_refresh_token` (pantalla Integraciones, fuera de esta área).

### 3.12 Datos avanzados — `admin/data.php`

`datos.avanzado` + **reautenticación con contraseña** (`reauth('datos','Datos avanzados')`; `?bloquear=1` la cierra). Edición en bruto tipo phpMyAdmin de 3 tablas: `clients` «Clientes», `client_types` «Tipos de cliente», `admins` «Equipo» (botones de pestaña). Aviso rojo «**Cuidado:** esta vista escribe en la base de datos tal cual, sin comprobar nada. Para el día a día usa Clientes, Tipos de cliente y Equipo…».
- Listado: tabla con todas las columnas (celdas truncadas a 40 chars, title completo) + «Editar» / «Borrar» (confirmación «¿Borrar la fila #N de X? No se puede deshacer.»).
- Edición: un input por columna; `id`/`created_at` deshabilitados; columnas con «json» en el nombre → textarea monoespaciada; `password_hash` readonly + campo «Nueva contraseña» (`__newpass`, se cifra al guardar). `tipo_id` vacío→NULL. POST `__id` → UPDATE → «Fila guardada.». POST `del` → si tabla es `clients`: borra `tasks, task_lists, client_credentials, time_entries, support_tickets, invoice_schedules, projects` y suelta `accounting, invoices, contacts, deals` (¡distinto de delete.php: aquí sí borra horas y no usa papelera!) → «Fila eliminada.».

### 3.13 Editor en vivo del portal — `index.php?cli=N&edit=1` + `admin/save-portal.php`

Disponible si hay sesión de admin, `?cli` válido dentro del alcance y `can_edit()`. Es el portal real con:
- Barra fija superior negra 52px: «✏️ Modo edición» + nombre del cliente + «Guardar cambios» + «Salir» (→ `admin/index.php`).
- Bloques editables con borde degradado naranja animado y botón «✏️ …» (naranja `#ff9500`): cabecera «Editar identidad», tarjeta de estado «Editar estado del proyecto», vista Plan «Editar plan», vista Accesos «Editar accesos», vista Progreso «Editar tareas», vista Informes «Editar informes».
- Cada botón abre un modal (título, cuerpo con scroll, «Cancelar»/«Aplicar»). «Aplicar» actualiza el estado local y repinta; marca `dirty` (aviso `beforeunload`). Nada se guarda hasta «Guardar cambios».
- Modales:
  - **Identidad del cliente**: hint; Saludo, Iniciales (max 4), Nombre del negocio, Usuario (para entrar), Contraseña (en blanco = no cambiar), Mes actual, select Tipo de cliente, «Panel de Looker Studio (URL de inserción, opcional)» (placeholder `https://lookerstudio.google.com/embed/...`), checkbox «Mostrar Métricas y oportunidades (si no usas tipo)», sección «Servicios contratados (desbloquean su vídeo en Método; el resto sale con candado)» con 6 checkboxes fijos (Diseño web, SEO, SEM, CRO, Tiendas online, Meta).
  - **Estado del proyecto**: Etapa actual, Etiqueta, Lo siguiente (textarea), repetible «Fase N» (Nombre de la fase / Subtítulo (opcional) / ¿En qué punto está? [✓ Completada | ● En curso ahora | ○ Pendiente]) «➕ Añadir fase».
  - **Plan contratado**: Resumen; repetible «Concepto N» (Número (grande) / Concepto); repetible «Apartado N» (Título del apartado / Descripción).
  - **Accesos del cliente**: repetible «Acceso N» (Título / Descripción / Enlace / Logo que se muestra [Figma, Google Drive, Sitio web, Looker Studio, Otro]).
  - **Informes mensuales**: hint «Cada informe es el análisis de un mes… (Más adelante n8n los creará solos cada mes.)»; repetible «Informe N» (Mes / Título / Enlace a PDF (opcional) / Análisis del mes textarea).
  - **Tareas por mes**: chips de meses + «➕ Mes»; por mes: input grande del mes, «🗑 Quitar este mes», grupo «✓ Completado (ya hecho)» y «● En curso ahora», cada uno repetible (Título de la tarea / Explicación para el cliente).
  - Repetibles: tarjeta numerada («Fase 1»…) con «🗑 Quitar».
- **Guardar** → `POST admin/save-portal.php` JSON con cabecera `X-CSRF-Token`:
  ```json
  {"id":5,"name":"","username":"","password":"","iniciales":"","saludo":"","actual":"","tipo_id":"3","conversiones":1,
   "estado":{…},"plan":{…},"accesos":[…],"tareas":{…},"informes":[…],"servicios":["SEO"],"looker":"https://…"}
  ```
  Respuesta `{ok:true}` → toast «✓ Guardado. Así lo ve el cliente.»; `{ok:false,msg}` → «⚠ msg» («Datos incompletos.», «No tienes acceso a este cliente.», «Nombre y usuario son obligatorios.», «Ese usuario ya existe, elige otro.», «Error al guardar.»); red → «⚠ Error de conexión». Escribe `username, name, iniciales, saludo, conversiones, tipo_id, actual, estado_json, plan_json, accesos_json, informes_json, looker_url, tareas_json` (+ `servicios_json` solo si viene array; + password si no vacía). **Nunca `met_json`.** No valida `login_email` ni toca datos fiscales/activo.
  - Ojo: si el cliente tiene lista de informes, cualquier cambio de tareas posterior sobrescribe `informes_json` y `tareas_json` (publicar_progreso), perdiendo lo escrito aquí.

---

## 4. Portal del cliente

### 4.1 Autenticación

**Sesión**: cookie `croilab_portal` (misma sesión PHP que el ERP); el cliente queda en `$_SESSION['client_id']`. `current_client()` carga la fila. `logout.php` (raíz) solo hace `unset(client_id)` → `login.php`.

**`login.php`** (`?m=AGENCIA_ID` para mostrar la marca de una agencia; `?ge=denied|err|cancel|nocfg` para errores de Google). Si ya hay cliente → `index.php`.
- Layout 2 paneles: izquierda «brand» (logo/inicial + nombre de marca; H2 «El estado de tu proyecto, en un solo sitio.»; «Tus métricas, el trabajo mes a mes, informes, reuniones y facturas de {marca}.»; 3 features con icono: «Tus métricas y resultados», «El trabajo de tu proyecto, mes a mes», «Informes, reuniones y facturas»). Derecha: tarjeta. Botón flotante de tema claro/oscuro (localStorage `portalTheme`, transición circular View Transitions). Fuente Inter. Título `Área de cliente · {marca}`.
- **Modo cliente**: «Área de cliente» / «Entra para ver el estado de tu proyecto con {marca}.»; error en caja roja; campos «Usuario» (placeholder «Tu usuario») y «Contraseña» («Tu contraseña», botón ojo); botón «Entrar →»; si Google configurado (`gcal_client_id` + secret): separador «o» + «[G] Entrar con Google» → `admin/client_google_login.php`; enlace «¿No puedes entrar?» que despliega «Ponte en contacto con **{marca}** y te restablecen la contraseña.»; pie «Acceso del equipo →» (`admin/`).
- POST `username,password,_csrf`: freno `login_throttle` con clave `cli:{username}` + IP: a partir de 5 fallos bloqueo 60s·2^(fallos−5), tope 15 min; olvido tras 15 min; mensaje «Demasiados intentos. Espera un minuto y vuelve a probar.» (o «… N minutos …»). Credenciales OK (`password_verify`) → `session_regenerate_id`, `client_id`, → `index.php`. Fallo: «Usuario o contraseña incorrectos.» No comprueba `activo` (un cliente no activo puede entrar).
- **Modo equipo** (hay sesión de admin): «Hola, {usuario} 👋» / «Has entrado como equipo. Elige un cliente para ver su portal — sin contraseña.», nota «Entras en modo vista previa (solo lectura)…», select de **todos** los clientes, botón «Ver su portal →» (`index.php?cli=ID`), «Ir al panel de gestión», «Cerrar sesión de equipo». Sin clientes: «Todavía no hay clientes dados de alta.»
- Mensajes Google: denied «Ese correo de Google no está asociado a tu cuenta. Escríbenos y lo activamos.»; err «No se ha podido entrar con Google. Inténtalo de nuevo.»; cancel «Has cancelado el acceso con Google.»; nocfg «El acceso con Google todavía no está disponible.»

**Google** (`admin/client_google_login.php`, público): si ya hay cliente → portal; si no configurado → `login.php?ge=nocfg`; genera `state` (16 bytes hex), guarda `$_SESSION['gclilogin']=1` y `gclilogin_state`, redirige a Google OAuth (`scope=openid email profile`, `prompt=select_account`, redirect = el mismo `admin/gcal_callback.php` del calendario). En el callback (`admin/gcal_callback.php:34-53`): consume el marcador; `error`→`?ge=cancel`; valida `state` con `hash_equals` → si no `?ge=err`; canjea `code`, lee `userinfo`, exige `verified_email`; con ≥5 fallos de sesión duerme 2 s; busca `clients.login_email` (no vacío) = email en minúsculas → si no existe suma fallo y `?ge=denied`; si existe regenera sesión, `client_id` → `../index.php`.

**Vista previa del equipo**: `index.php?cli=N` con sesión de admin (+ alcance) muestra el portal de ese cliente en modo lectura; `CANMSG=false` (desactiva enviar mensajes y solicitudes: botones «Vista previa — envío desactivado» / «Vista previa — solicitud desactivada», toasts «En vista previa no se envían mensajes.» / «… solicitudes.»). Con `&edit=1` y `can_edit` → editor en vivo (§3.13).

### 4.2 Portal — `index.php`

Página única; el servidor inyecta todo como JSON en variables globales y el JS pinta las vistas (sin peticiones de lectura posteriores). Para React: un `GET /portal` debe devolver equivalente a `DATA + PORTAL + BRAND + META + flags`.

**Payload construido en servidor** (`index.php:97-269`):
- `META = {id, name, username, actual, iniciales, saludo, conversiones:bool, tipo_id, looker}`.
- **Secciones visibles**: base `{metricas,progreso,informes,como,accesos,plan}` = true; si `tipo_id` → cada una = `!empty(tipo.secciones_json[k])`; si no hay tipo → solo `metricas = (conversiones==1)`. `verMetricas = secciones.metricas`.
- `DATA = {config:{cliente, iniciales||'CL', saludo||name, conversiones:verMetricas, plan}, secciones, estado, accesos, informes, servicios (null si vacío), looker, cfg:{meeting_url, video_id (def 'J9-aEZ523bA'), whatsapp, email, serv_videos:{nombreServicio:youtubeId}, video_web…video_meta}, met (objeto), meses: keys(met) en orden, actual: clients.actual || último mes de met, tareas: tareas_json (objeto)}`.
- `BRAND = marca_partner(partner_id)`; contacto = `marca_contacto(partner_id)`.
- `PORTAL`:
  - `tareas`: tareas de `tasks` del cliente con `visible_cliente=1` **o** lista `es_cliente=1` **o** lista `tipo='informe'`; orden `mes DESC, lista.orden, tarea.orden, id`; cada una `{id, titulo (titulo_cliente||titulo), texto (explicacion_cliente||descripcion), estado, prioridad, mes, due, lista, asig:[{n:username, ini, c:color, foto:'archivo.php?d=avatars&f=…'}]}` (asignados de `task_assignees` o, si no hay, el responsable).
  - `facturas`: estado `enviada|pagada|vencida` (nunca borrador), `{id, numero, fecha, venc, estado, total}` por fecha desc.
  - `reuniones`: `crm_meetings` con `contact_id = clients.contact_id`, `{id, fecha, hora, titulo, estado}` por fecha/hora desc.
  - `tickets`: `{id, asunto, estado, nresp (nº support_replies), fecha}` orden estado abierto→cerrado, `updated_at` desc.
  - `accesos_vault`: credenciales con `visible_cliente=1` `{t, cat, u, s (¡secreto en claro!), url, nota}`.
  - `solicitudes`: `portal_meeting_requests` `{id, fecha, franja, motivo, estado}` desc.
- `CANMSG` (cliente real), `CLIID`, `EDIT`, `TIPOS` (solo en edición).
- Normalización en JS (`index.php:1762-1764`): cada mes de `MET` con `ll,wa,fo,vi,ap,ctr` no numéricos → 0; `total=ll+wa+fo`.

**Estructura visual** (escritorio): 
- Raíl oscuro fino 62px (`--dark #0f1012`): logo de marca arriba (color de marca de fondo si hay), botón cerrar sesión y avatar del cliente abajo.
- Barra lateral clara 236px: logo + nombre de marca; grupos:
  - «Tu proyecto»: Inicio (`resumen`), Métricas (`metricas`, clase conv-only), Tareas (`tareas`, contador de abiertas), Reuniones (`reuniones`, contador de próximas).
  - «Documentos»: Informes, Facturas (contador = nº facturas).
  - «Ayuda»: Soporte (contador abiertos), Método (`como`), Accesos, Plan.
  - Pie: avatar + nombre del cliente + «Área de cliente» + botón salir.
  - ≤980px: raíl oculto, barra lateral como cajón deslizante con scrim y botón hamburguesa.
- Cabecera: H1 «Hola, {saludo} 👋»; subtítulo «Echemos un vistazo a tu proyecto · {actual en minúsculas} {año actual}». Herramientas: buscador «Buscar en tu proyecto» (Enter), botón «Informe de {actual}» / «Ver informes» (→ informes; oculto si Informes off), «⤓ Descargar PDF», botón tema claro/oscuro.
- Tokens de color: `--bg #f5f5f7`, `--card #fff`, `--ink #22262c`, `--muted #9aa0a8`, `--line #eeeeef`, acento `--yellow #1f232a` (oscuro; en modo oscuro `#3b82f6`), `--green #12a150`, radio 20px. Fuente del sistema (-apple-system…). Modo oscuro por `data-theme=dark` en `<html>`, persistido en `localStorage.portalTheme`.
- **Visibilidad**: `applySections()` oculta enlace y vista de cada sección con `secciones[k]===false` (las claves `tareas, reuniones, facturas, soporte` no existen en los tipos → siempre visibles). Si `como` está off, también `servicio`. `body.no-conv` oculta todo `.conv-only` cuando `config.conversiones` es false (tarjeta de resultado, enlace y tile de Métricas).
- Navegación: `go(view)` cambia la vista activa, marca el enlace, repinta la vista y hace scroll arriba; cierra el cajón en ≤980px. Sin rutas/URL (todo en memoria).

**Secciones**

1. **Inicio (`resumen`)**
   - Banner de tareas (`PORTAL.tareas` no completadas): «En curso: {primera}» + «Tienes N tareas en marcha»/«1 tarea en marcha» + «Ver tareas →»; si no hay: check verde «Todo al día» / «No hay tareas pendientes ahora mismo».
   - Tarjeta «Tu resultado de {actual}» (conv-only): número grande = ll+wa+fo de `MET[actual]`, «oportunidades de contacto», variación vs mes anterior («▲ N% más que el mes pasado» / «▼ N% que el mes pasado» / «▲ primer mes con contactos» / «sin contactos todavía» / «primer mes con datos»); texto «Son las veces que alguien os ha llamado, escrito por WhatsApp o rellenado un formulario este mes. Es lo que de verdad os trae clientes.»; 3 minis Llamadas / WhatsApp / Formularios con delta («▲ +N%», «▼ N%», «igual», «▲ nuevo», «mes de partida»).
   - Tarjeta «Estado del proyecto» (`estado_json`, §1.4) + enlace «Ver el trabajo mes a mes» (→ tareas).
   - «¿Qué quieres ver?»: tiles «Lo que hemos hecho / Todo el trabajo, mes a mes. / Ver tareas →» y (conv-only) «Tus números / Contactos y visibilidad en Google. / Ver métricas →».

2. **Métricas** (`metricas`; requiere `MESES` no vacío) — rejilla de 12 columnas:
   - Cabecera «Tus métricas» / «Tus contactos y tu visibilidad en Google, mes a mes.» + select «Mes»: «Global (todos)» + meses en orden inverso etiquetados «{Mes} 2026» (el primero «· este mes»). Global = suma de todos los meses, `ctr = vi/ap·100` con 1 decimal.
   - Enlaces: «Lo que hicimos [en {mes}]» (→ tareas), «Informe de {mes}» / «Ver informes».
   - KPIs: «Oportunidades» (total), «Visitas» (vi), «Apariciones» (ap), «CTR» (ctr en «x,y%»; delta en puntos «▲ +0,4 pts»). Delta vs mes anterior en %, «nuevo» si el anterior era 0, «primer dato» si no hay anterior; en Global: «en N meses». Animación count-up.
   - «Cómo evolucionan tus contactos»: gráfica de área suavizada con pestañas «Contactos | Visitas | Apariciones» (títulos «Oportunidades de contacto», «Visitas desde Google», «Apariciones en Google»), meses abreviados (3 letras), mes elegido resaltado, tooltip con crosshair.
   - «Tus contactos por canal»: donut (Llamadas = acento, WhatsApp `#12a150`, Formularios `#8b5cf6`) con total en el centro y leyenda; hover muestra canal/valor/%.
   - «Visitas a tu web por mes»: barras verticales por mes con valor.
   - «Meses con más contactos»: top 5 meses por total con barra proporcional; vacío «Aún no hay contactos registrados.».
   - Fila de crecimiento (se oculta si vi y ap son 0 en todos): «Crecimiento de clics en Google» y «Crecimiento de apariciones» con número, badge (▲ +N% / ▼ N% / = / ▲ nuevo / —) y sparkline.
   - «¿De dónde viene tu tráfico?» (oculta si no hay `src`): barras horizontales por canal traducido (Organic Search→«Búsqueda en Google», Direct→«Directo», Paid Search→«Anuncios (Google Ads)», Organic Social→«Redes sociales», Paid Social→«Anuncios en redes», Referral→«Otras webs», Email, Display, Organic Video→«Vídeo», Paid Video→«Anuncios de vídeo», Unassigned→«Sin clasificar», Organic Shopping→«Shopping», Paid Shopping→«Shopping de pago», Cross-network→«Varias redes»), valor y %.
   - «¿Desde dónde te visitan?» (oculta si no hay `geo`): mapa mundial coroplético (jsVectorMap 1.5.3 por CDN) + top 8 países con bandera emoji, nombre en español, sesiones y %.
   - Calculadora «¿Cuánto pueden suponer tus contactos?»: «Contactos del mes» (se rellena con el total del mes elegido, editable), «De cada 100, ¿cuántos acaban comprando?» (5 %), «¿Cuánto te deja de media cada cliente?» (1500 €) → «Clientes nuevos estimados» y «Podrías ingresar · {mes}» + frase «Si cierras el X% de tus N oportunidades, podrías ingresar Y €.». Solo cliente, sin persistencia.
   - Looker: si `looker_url` → iframe 600px a ancho completo; si no, no se muestra nada.
   - Todo el color de las gráficas sale de variables CSS; al cambiar tema se repinta.

3. **Tareas** (`tareas`, siempre visible) — fuente `PORTAL.tareas` (tareas reales). «Tu trabajo» / «Esto es lo que estamos haciendo por ti, con quién lo lleva. Pulsa una tarea para ver el detalle.» Select «Mes» («Todos los meses» + meses en el orden en que aparecen, el primero «· este mes»; por defecto el primero). Grupos por mes: píldora del mes, «Este mes» si es el primero, «X/Y hechas»; tabla `Tarea | Quién lo lleva | Prioridad | Fecha`: círculo de estado (pendiente «En espera» #b0b4bb, «en proceso» #3b82f6, atemporal punteado #e0a000, completada relleno #12a150 con check), título, tag «Hecho»; pila de hasta 3 avatares (+N) y nombre («Sin asignar», «Ana», «Ana +2»); prioridad con punto de color (Baja/Normal/Alta/Urgente) o «—»; fecha `due` dd/mm/aa o «—». Clic despliega la explicación. Vacío: «Aún no hay tareas para ti. En cuanto empecemos a trabajar en tu proyecto aparecerán aquí, mes a mes y con quién lleva cada cosa.» / «No hay tareas en ese mes.»

4. **Progreso** (`progreso`) — fuente `tareas_json`. «Elige un mes» con chips (orden inverso), enlaces «Métricas de {mes}», «Informe de {mes}», subgrupos «En curso ahora (n)» y «Completado (n)», cada tarea plegable con caja ✓, píldora «Hecho»/«En curso» y la explicación. Vacío «Sin tareas registradas en {mes}.» **No hay enlace en el menú a esta vista** (ver Riesgos); solo la usa el editor en vivo y `downloadPDF`.

5. **Reuniones** — «Tus reuniones» + botón «Solicitar reunión»; nota «Aquí tienes tus reuniones con el equipo. ¿Necesitas hablar? Pide una nueva y te la confirmamos.» «Tus solicitudes» (solo pendientes: motivo, «día · franja» o «Sin día preferido», estado «Pendiente de confirmar» #e0a000 / «Confirmada» #12a150 / «No disponible» #9aa0a8). «Próximas» (fecha ≥ hoy y no cancelada/realizada, orden asc) y «Anteriores» (resto): cuadro con mes abreviado y día, título o «Reunión con tu equipo», «d de mes de aaaa · hora», estado (Agendada #3b82f6, Realizada #12a150, No realizada #e0a000, Cancelada #9aa0a8). Vacío: «No tienes reuniones programadas ahora mismo. Cuando agendemos una contigo, la verás aquí.»
   - Modal «Solicitar una reunión» / «Te la confirmamos nosotros»: «Día que prefieres (opcional)» (date), «Franja (opcional)» [Sin preferencia | Por la mañana | Al mediodía | Por la tarde], «¿De qué quieres hablar?» (requerido, placeholder «Ej. Revisar los resultados del mes y los próximos pasos»); «Cancelar» / «Enviar solicitud». POST `index.php` form-urlencoded `portal_action=meetreq, motivo, fecha, franja, _csrf` (+ cabeceras `X-CSRF-Token`, `X-Requested-With`) → `{ok:true}` → INSERT `portal_meeting_requests` (estado pendiente; fecha solo si `YYYY-MM-DD`; franja ≤30) → toast «¡Solicitud enviada! Te confirmaremos la reunión.» (se añade en local). Errores: «Cuéntanos brevemente el motivo.», «No se pudo enviar. Inténtalo de nuevo.». La aprobación se hace en `admin/reuniones.php` (otra área).

6. **Soporte** — «Soporte» + botón «Escríbenos»; nota «Cuéntanos cualquier cosa —una duda, una petición, un problema— y te respondemos. Aquí ves tus mensajes y en qué estado están.» Lista «Tus mensajes»: asunto, «N respuesta(s) de tu equipo» / «Sin respuesta todavía», estado (Abierto, En curso, Esperando, Resuelto, Cerrado con colores de §3.2). No se pueden abrir para leer las respuestas. Vacío: icono, «Aquí verás tus conversaciones», texto y botón «Escribir a tu equipo» (solo cliente real).
   - Modal «Escríbenos» / «Nos llega directamente a tu equipo de **{marca}**. Te respondemos por aquí o por email lo antes posible.»: «Asunto (opcional)» (max 120), «Tu mensaje» (requerido). POST `portal_action=ticket, asunto, cuerpo` → INSERT `support_tickets (asunto||'Mensaje de {name}' truncado 200, cuerpo, client_id, prioridad 2, estado 'abierto', created_by NULL)` → `{ok:true}`; toast «¡Mensaje enviado! Tu equipo te responderá en breve.»; errores «Escribe tu mensaje.», «No se pudo enviar. Inténtalo de nuevo.». Sin notificación al equipo en el legado.
   - Escape cierra cualquier modal; clic fuera también.

7. **Informes** — «Tus informes mensuales» / «Cada mes recibes aquí tu informe con todo el análisis. Quedan guardados: puedes abrir cualquiera cuando quieras.» Acordeones (§1.4). Vacío: «Aún no hay informes publicados. En cuanto subamos el informe del mes aparecerá aquí, y podrás abrirlo siempre que quieras.»

8. **Facturas** — «Tus facturas» / «Todas tus facturas. Pulsa una para verla y descargarla en PDF.» Filas: icono, «Factura {numero}», «{d de mes de aaaa} · vence dd/mm/aa», estado (Enviada #3b82f6, Pagada #12a150, Vencida #ef4444), total `1.234,56 €`, abre `factura.php?id=X` (en vista previa añade `&cli=N`) en pestaña nueva. Vacío: «Todavía no tienes facturas. Cuando emitamos alguna aparecerá aquí para que la veas y la descargues.»

9. **Método (`como`)** — «Cómo trabajamos» / «Nuestro método y cómo enfocamos cada servicio.» Tarjeta de vídeo con miniatura YouTube (`video_id`) y «▶ Cómo trabajamos · 2 min»; clic → iframe autoplay. Nota «Vídeo provisional. Ábrelo en YouTube». «Nuestros servicios»: 6 tiles **fijos en código** (`SERV_ORDER = Diseño web, SEO, SEM, CRO, Tiendas online, Meta`) con icono, nombre y frase corta; los no contratados (según `servicios_json`) llevan 🔒.
   - **Detalle de servicio** (`servicio`): «← Volver a servicios», cabecera (icono, nombre, intro), vídeo (catálogo → clave legado → vídeo general) «▶ {servicio} · presentación», «Cómo lo hacemos» (5 pasos), «Preguntas frecuentes» (acordeones). Textos fijos en el JS (`index.php:2276-2283`). Si bloqueado: tarjeta 🔒 «Este servicio no está en tu plan actual. Si te interesa, organizamos una reunión y te contamos cómo **X** puede ayudarte — sin compromiso.» + botón «Reservar una reunión» → `meeting_url` de la marca.

10. **Accesos** — (a) «Tus contraseñas y accesos» (solo si hay credenciales visibles) / «Tus claves de acceso, guardadas y seguras. Pulsa el ojo para verlas o el icono para copiarlas.»: tarjetas (min 320px) con icono por categoría, título, categoría (Web, Correo, Hosting, Base de datos, API / Token, CMS, Dominio, Redes, Acceso), Usuario + copiar, Contraseña enmascarada + ver + copiar (tic de confirmación), nota, «Acceder al servicio». (b) «Enlaces y recursos» / «Todo lo que compartimos contigo, a un clic.» (`accesos_json`). (c) «¿Necesitas algo?»: «¿Necesitas algo de tu equipo de {marca}?» / «Ábrenos un ticket y te respondemos por aquí. Verás el estado en Soporte.» + «Abrir un ticket» (va a Soporte y abre el modal). Pie: «{marca} · Área privada de {cliente} · Actualizado automáticamente desde el seguimiento del proyecto.»

11. **Plan** — «Tu plan mensual» / «Esto es exactamente lo que tienes contratado cada mes. Sin sorpresas.»; rejilla de items, «Qué incluye tu plan» (resumen), acordeón de detalle (§1.4).

**Buscador** (Enter): 1) servicio cuyo nombre contenga el texto (si contratado y Método visible) → detalle; 2) palabras clave → sección (metricas: metric, numero, conversion, llamada, whatsapp, formulario, google, visita, visibilidad, ctr; tareas: progreso, trabajo, tarea, hecho, avance; informes: informe, report, pdf; como: metodo, como, servicio, video, seo, web, sem, cro, meta, tienda; accesos: acceso, contraseña, credencial, contacto, ayuda, email, correo; plan: plan, precio, contrato, mensual, incluye; resumen: inicio, resumen, home, portada) respetando visibilidad; 3) mes/título de informe → Informes; si no: toast «No encontré nada con «q»».

**Descargar PDF**: monta `#printReport` (solo visible al imprimir) y llama a `window.print()`. Mes = el elegido en Métricas (Global → `actual`). Contenido: cabecera con logo y «{marca} · Informe mensual», «{Mes} 2026 — {saludo}»; si conversiones: «Resultados del mes» con 6 KPIs (Oportunidades de contacto, Llamadas, WhatsApp, Formularios, Visitas en Google, Apariciones en Google) y delta «▲ +N% vs mes anterior» / «▼ …» / «igual que el mes anterior» / «▲ nuevo» / «mes de partida»; «Estado del proyecto» (nombre — etiqueta, «Lo siguiente: …»); «Trabajo de {mes}» desde `tareas_json` (• en curso / ✓ hecho); pie «Generado el {fecha larga} · {marca} · Área de cliente».

**Arranque**: ejecuta en orden protegido (cada uno en try/catch): applySections, applyConfig, applyCfg (enlaces WhatsApp `https://wa.me/{whatsapp}`, email, vídeo, Looker), renderEstado, renderBigResult, renderMonths, renderTasks, renderRecursos, renderInformes, renderBanner, renderMetrics, renderHub, renderPlan, renderTareas, renderFacturas, renderReuniones, renderSoporte, renderVault. Los `<select>` se sustituyen por un desplegable estilizado (`csEnhance`).

### 4.3 Factura del portal — `factura.php?id=X[&cli=N][&print=1]`

Acceso: cliente logueado (solo sus facturas) o admin con `?cli=N` dentro de su alcance. Carga `invoices WHERE id=? AND client_id=? AND estado IN ('enviada','pagada','vencida')`. No existe → «No encontramos esa factura» / «Puede que ya no esté disponible o que no corresponda a tu cuenta. Vuelve a tus facturas e inténtalo de nuevo.» + «Volver».
Hoja imprimible: barra (no se imprime) «← Volver», badge de estado (Enviada/Pagada/Vencida), «Descargar / Imprimir» (`window.print()`). Hoja: «FACTURA», píldora «Nº {numero}», chips «Fecha: dd/mm/aaaa», «Vencimiento: …» (si hay). Bloques «Datos del cliente» (snapshot `cliente_nombre`, nif, tel, dir, email) y «Emitida por» (`emisor_json`: name (def Croilab), nif, email, phone, dir). Tabla «Detalle | Cantidad | Precio | Total». Totales: «Base imponible», «IVA (+21%)», «IRPF (−15%)» si >0, «TOTAL». «Información de pago»: Banco (si hay), Titular, Forma de pago («Efectivo»/«Transferencia»), Condiciones (`cond_pago`), IBAN (si no efectivo). Notas: «Operación cobrada en efectivo.» y `notas`. `?print=1` imprime al cargar. `@page{margin:0}`.

---

## 5. Dependencias externas y procesos

- **Google Search Console API** (`webmasters/v3 …/searchAnalytics/query`) y **GA4 Data API** (`analyticsdata v1beta …:runReport`) con OAuth de una cuenta del dueño (refresh token en settings). Escriben `met_json`.
- **Google OAuth (identidad)** para «Entrar con Google» del cliente (cliente OAuth del calendario: `gcal_client_id/secret`, redirect `admin/gcal_callback.php`).
- **YouTube** (miniaturas `img.youtube.com/vi/{id}/hqdefault.jpg`, embeds `youtube.com/embed/{id}?autoplay=1`).
- **Looker Studio**: iframe de `looker_url` en Métricas.
- **Google favicons** (`google.com/s2/favicons`) en Accesos.
- **jsVectorMap 1.5.3** (CDN jsdelivr) para el mapa de países.
- **n8n** (mencionado): puede escribir `informes_json` por su cuenta; por eso `publicar_informes` no toca clientes sin lista de informes.
- **Cron**: `php admin/cron_metricas.php` diario (+ auto-refresco tras respuesta en cada página del ERP). Purga de papelera a 30 días (`pap_purga`, al borrar).
- **Notificaciones internas**: solo `notif_client_new` al crear cliente desde `edit.php`.
- **Ni audit_log ni emails** en el legado para esta área (el backend nuevo tiene `admin/lib/audit.php`; recomendable registrar alta/edición/borrado/reset de contraseña).

---

## 6. Recomendaciones

### (a) Endpoints API recomendados (`/api/v1`, convenciones de `backend/API.md`)

Equipo (sesión admin, permisos y alcance como §2):

| Método | Ruta | Propósito |
|---|---|---|
| GET | `/clientes` | Listado (ya existe; ampliar con `username, conversiones, tipo_id, tipo_nombre, activo` y contadores `tareas_abiertas, tickets_abiertos, pendiente_cobro` (este último `null` sin `ver.importes`); filtros `q`, `activo`, `tipo` (`id`|`none`)). |
| GET | `/clientes/{id}` | Ficha completa: datos, tipo, datos fiscales, blobs normalizados (estado/plan/accesos/tareas), resumen (oportunidades último mes, tareas en curso, soporte abierto, cobrado/pendiente), listas, facturas (5 + totales), tickets (4 + abiertos), credenciales (6 + total), contacto origen. |
| POST | `/clientes` | Alta (`clientes.crear`): valida, crea 4 listas por defecto, notifica dueños. 422 con `campo`. |
| PATCH | `/clientes/{id}` | Edición de ficha (`clientes.editar`); nunca toca `met_json`. |
| DELETE | `/clientes/{id}` | Borrado con papelera y desvinculaciones (`clientes.borrar`), devuelve `{trash_id}` para «Deshacer». |
| POST | `/clientes/{id}/duplicar` | Duplicar (`clientes.crear`) → `{id}`. |
| POST | `/clientes/{id}/password` | Restablecer contraseña de portal → `{password}` (una vez). |
| GET/PUT | `/clientes/{id}/portal` | Lectura/escritura del contenido del portal para el editor en vivo (`clientes.portal`): identidad, estado, plan, accesos, informes, tareas, servicios, looker, tipo. |
| GET | `/clientes/{id}/portal/preview` | Mismo payload que ve el cliente (vista previa del equipo). |
| GET/PUT | `/clientes/{id}/google` | `gsc_site_url, ga4_property_id, ga4_ev_ll/wa/fo`. |
| GET | `/clientes/{id}/google/eventos?prop=` | Eventos GA4 últimos 90 días. |
| POST | `/clientes/{id}/google/sync` | Sincronizar métricas de un cliente (mes actual + anterior). |
| POST | `/metricas/sync` | Sincronizar todos. |
| GET/PUT | `/metricas/ajustes` | Eventos por defecto y estado de conexión/última sync. |
| GET | `/tipos-cliente` · POST · PATCH `/{id}` · DELETE `/{id}` | CRUD de tipos con `uso` (nº clientes); POST admite alta rápida (devuelve existente con `dup:true`). |
| GET/PUT | `/servicios` | Catálogo con uso por servicio y `abiertos/total`; PUT recibe `[{nombre, desc, orig}]` y propaga renombrados/borrados. |
| GET/POST/PATCH/DELETE | `/agencias[/{id}]` | Marca blanca. |
| PUT | `/clientes/{id}/agencia` | Asignar `partner_id`. |
| GET | `/marca?m={id}` | Marca pública para el login (sin sesión). |
| GET/PATCH/DELETE | `/datos/{tabla}[/{id}]` | Solo si se decide conservar «Datos avanzados» (`datos.avanzado` + reauth). |

Portal del cliente (sesión de cliente; recomendable un prefijo propio `/portal` y cookie/rol separados):

| Método | Ruta | Propósito |
|---|---|---|
| POST | `/portal/auth/login` | `{username,password}` con freno `cli:` → `{csrf, cliente}`. |
| GET | `/portal/auth/google` · GET `/portal/auth/google/callback` | Login con Google por `login_email`. |
| POST | `/portal/auth/logout` | |
| GET | `/portal` | Payload único: `config, secciones, estado, plan, accesos, informes, servicios, looker, cfg(contacto+vídeos), met, meses, actual, tareas(json), marca, portal:{tareas,facturas,reuniones,tickets,solicitudes,accesos_vault}`. |
| POST | `/portal/tickets` | `{asunto?, cuerpo}` → ticket. |
| POST | `/portal/reuniones/solicitudes` | `{motivo, fecha?, franja?}`. |
| GET | `/portal/facturas/{id}` | Factura (cabecera, líneas, totales, emisor) para la hoja imprimible. |
| GET | `/portal/credenciales/{idx}/secreto` | (Recomendado) revelar secreto bajo demanda en vez de enviarlo en el payload. |

### (b) Pantallas y componentes React

Equipo:
- `ClientesListPage` (barra `SegmentedFilter` con contadores, `SearchInput`, `TipoSelect`, `ClientesTable` con `ClienteAvatar`, `Tag`, `ActivityChips`, `RowActions` hover, `ContextMenu`, `EmptyState`, layout móvil en filas).
- `ClienteFichaPage` (`FichaHeader`, `QuickActionsGrid`, `StatTiles`, `CardTareasBacklog`, `CardFacturas`, `CardSoporte`, `CardContactoOrigen`, `CardAccesoPortal` + `ResetPasswordDialog` + `OneTimePasswordNotice`, `CardDatosFiscales`, `CardCredenciales` con `SecretField`, `CardEstadoProyecto` (`PhaseBar`), `CardPlan`, `DangerZone`). Reusar modales globales `NuevaTareaModal`, `AgendarModal`, `NuevoTicketModal`.
- `ClienteFormPage` (alta/edición) con `SegmentedTabs` de 6 secciones, `Switch`, `RepeatableList` genérica (fases, items, detalle, accesos, tareas por mes), `StickySaveBar`, `QuickCreateTipoPrompt`.
- `TiposClientePage`, `TipoClienteFormPage` (`SectionToggleRow`).
- `ServiciosCatalogoPage` (filas editables, contador de uso, confirmación al quitar).
- `AgenciasPage` (`AgenciaCard` con `CopyLink`, `AgenciaModal`, `AsignarClientesTable`).
- `PricingCalculatorPage` (puro cliente).
- `ClienteGooglePage` (conversiones: `EventPicker` con asignación 📞💬📝) y `MetricasGoogleAjustesPage`.
- `DatosAvanzadosPage` (opcional, con `ReauthGate`).
- `PortalLiveEditor` (envoltorio del portal con `EditBar`, `EditableBlock`, modales `IdentidadModal`, `EstadoModal`, `PlanModal`, `AccesosModal`, `InformesModal`, `TareasPorMesModal`; guardado único + aviso de cambios sin guardar).

Portal del cliente (app/ruta separada, marca blanca):
- `PortalLoginPage` (`BrandPanel`, `LoginForm`, `GoogleButton`, modo equipo `ClientPicker`), `ThemeToggle`.
- `PortalLayout` (`BrandRail`, `PortalSidebar` con contadores y secciones visibles, `PortalHeader` con buscador, botón informe, PDF, tema; cajón móvil).
- Vistas: `InicioView` (`TareasBanner`, `BigResultCard`, `EstadoProyectoCard`, `HubTiles`), `MetricasView` (`MonthSelect`, `KpiCard` con count-up, `EvolutionAreaChart` con tabs, `ChannelDonut`, `MonthlyBars`, `TopMonthsRank`, `GrowthSparkCards`, `TrafficSources`, `GeoMap` + `GeoList`, `RevenueCalculator`, `LookerEmbed`), `TareasView` (`TaskGroup`, `TaskRow`, `AvatarStack`, `StatusCircle`), `ProgresoView`, `ReunionesView` (`SolicitudReunionModal`), `SoporteView` (`EscribenosModal`), `InformesView` (`Accordion`), `FacturasView`, `MetodoView` + `ServicioDetailView` (`VideoCard`, `Steps`, `Faq`, `LockedService`), `AccesosView` (`VaultCard`, `ResourceGrid`, `ContactCTA`), `PlanView`.
- `PrintReport` (informe mensual imprimible) y `FacturaPrintPage`.
- Utilidades: `delta()` / formateadores `es-ES`, `useSections()`, `usePortalData()`.

### (c) Riesgos y ambigüedades

1. **Meses sin año** en `met_json`/`tareas_json`/`informes` (`Enero`…): en enero del año siguiente la sync **sobrescribe** el mes del año anterior; el portal etiqueta todo como «2026» fijo (`index.php` en tooltips, select y PDF). Además el orden de meses es el de inserción: un cliente nuevo sincronizado con `[mes actual, anterior]` queda en orden invertido y los deltas «vs mes anterior» salen mal. Recomendado migrar a claves `YYYY-MM` con etiqueta derivada.
2. **Vista «Progreso» huérfana**: el menú no tiene enlace a `progreso` (`index.php:1395-1418`); el interruptor «Progreso» de los tipos no oculta nada visible y el lápiz «Editar tareas» del editor en vivo cuelga de una vista inalcanzable. «Tareas» (tareas reales) no es configurable por tipo. Decidir si se unifican.
3. **Dos fuentes de «tareas del cliente»**: `tareas_json` (editable a mano en `edit.php` y editor en vivo) vs `PORTAL.tareas` (tabla `tasks`). `publicar_progreso()` reescribe `tareas_json` e `informes_json` en cuanto se toca una tarea, **borrando lo editado a mano**. Ídem informes del editor en vivo si existe lista de informes.
4. **Permisos incoherentes**: crear cliente exige `clientes.editar` (no `clientes.crear`); `edit.php` exige `general.editar` incluso para ver el formulario; `reset_pass` solo `general.editar`; el editor en vivo se muestra con `can_edit` pero guardar exige `clientes.portal`; botones «Duplicar»/«Borrar» se pintan con `can_edit` aunque falte `clientes.crear/borrar`. Alcance no aplicado en `login.php` (modo equipo lista todos), `agencias.php`, `metricas.php`.
5. **Secretos al navegador**: el portal envía todas las contraseñas `visible_cliente` en claro dentro del HTML; la ficha del equipo también (`data-v`). Recomendado revelar bajo demanda.
6. **`activo=0` no bloquea el login** del cliente.
7. **Servicios desalineados**: el portal usa 6 servicios fijos (`SERV_ORDER`, con «Meta»), el catálogo por defecto dice «Meta Ads», y los servicios nuevos del catálogo no aparecen en el portal (solo aportan vídeo). Los textos/pasos/FAQs de cada servicio están en el JS.
8. **`data.php`** borra clientes sin papelera, borra `time_entries` (delete.php las conserva) y no limpia hijos de tareas.
9. **Duplicar** no copia datos fiscales, servicios, informes, looker, marca, ni crea listas → el duplicado no tiene `INFORMES CLIENTE`.
10. **Esquema nuevo**: `portal_meeting_requests` no está en las migraciones (se crea en caliente); `gsc_site_url/ga4_*/met_sync_at` dependen de `gm_ensure_schema()` en 0002. El backend nuevo añade `cred_ver`: al cambiar la contraseña del cliente habría que subirlo para cerrar sesiones.
11. **Seguridad del editor JSON**: `save-portal.php` guarda `estado/plan/accesos/tareas/informes` tal cual llegan (sin validar forma ni URLs; el portal escapa al pintar y `safeUrl` filtra `javascript:`). En React validar esquemas en la API.
12. **Sin notificaciones** al equipo cuando el cliente abre un ticket o pide reunión; sin audit_log en ninguna acción del área.
13. **`gm_auto_daily`** solo corre con FastCGI/LiteSpeed; en otros entornos depende del cron. Los errores de Google solo van a `error_log`; el portal no distingue «sin datos» de «error».
14. **Sesión compartida** admin/cliente en la misma cookie: un admin logueado que abre el portal entra en vista previa; definir en la arquitectura nueva si el portal es otra app/cookie.
15. **Ambigüedades menores**: `clients.email` existe y no se usa; `fact_tel` no se edita en la ficha; `orden` de clientes no se usa; `accesos_json.u` vacío se guarda como `'#'`; `delta` de CTR en puntos vs porcentaje en el resto; la calculadora de ingresos y la de pricing no persisten nada.
