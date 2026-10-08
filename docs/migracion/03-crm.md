# 03 · CRM (Contactos, Negocio, Listas, Dashboard, Reporting/Seguimientos)

Especificación funcional del CRM del ERP legado (`copia-erp/admin`) para reconstruirlo como
React (`front/`) + API PHP (`backend/`). Todo lo que sigue sale de leer el código; cuando algo es
un fallo o una ambigüedad del legado se marca con **[RIESGO]** y se recoge al final.

Fuentes leídas: `admin/crm.php`, `crm_profile.php`, `negocio.php`, `crm_dashboard.php`,
`crm_import.php`, `crm_followup_email.php`, `automatizaciones.php`, `automations.php`, `listas.php`,
`agendar.php`, `cron.php` (parte CRM), `buscar.php` (parte CRM), `client.php` (origen del lead),
`settings.php` (interruptores), `erp_nav.php` (menú, `notif_sync_leads`, `erpAgendar`, `auto_on`),
`lib/crm_lib.php`, `lib/crm_followup.php`, `lib/puentes.php`, `lib/permisos.php`, `lib/gcal.php`
(`gcal_meeting_notes`), `lib/papelera.php`, `auth.php` (`require_admin`, `can_edit`, `is_owner`,
`num_es`, subida de archivos). En el backend nuevo: `backend/API.md`, `api/rutas.php`,
`src/Seguridad/Acceso.php`, `database/migrations/0001..0005`, `admin/lib/*` (copias de las libs).

Estado en el backend nuevo: **no hay ningún módulo CRM** en `backend/src/Modulos` (solo Auth,
Clientes, Equipo, Nav, Tareas) ni rutas CRM en `API.md`. Las libs `crm_lib.php`, `crm_followup.php`,
`puentes.php` están copiadas en `backend/admin/lib` con dos cambios: `ensure_crm_schema()`,
`crm_meetings_ensure()` y `ensure_puentes_schema()` salen enseguida si
`croilab_esquema_gestionado()` (el esquema lo crea la migración `0002_modulos.php`), y
`pu_lead_a_cliente()` usa `db_tx_begin/commit/rollback` (savepoints) en vez de
`beginTransaction()`. `crm_followup.php` es idéntico salvo finales de línea. La migración `0005`
añade el índice `contacts(propietario_id, client_id)` (lo usa `Acceso::clientesVisibles()`).

Nomenclatura obligatoria del legado: «Embudo de venta» (nunca «ciclo de vida»). Moneda en € con
formato español (`number_format(..., 0, ',', '.')` → «12.500 €»).

---

## 1. Navegación y piezas comunes (erp_nav.php)

- **Raíl izquierdo**: entrada `CRM` (icono `crm`, enlace `crm.php`) visible solo con `can('ver.crm')`.
  Su menú flotante: Contactos (`crm.php`), Negocio (`negocio.php`), Dashboard (`crm_dashboard.php`),
  Reporting (`automatizaciones.php`), Reuniones (`reuniones.php`) y una sección «Listas» con
  `SELECT id,nombre,tipo FROM lists ORDER BY tipo DESC, nombre` (icono `layers` si estática, `list` si activa).
- **Sidebar del módulo** (`$mod==='crm'`, título «CRM · Ventas»): las mismas 5 entradas + sección
  «Listas» con botón «+» (`listas.php?new=1`, solo `can_edit`). Las listas se pueden **reordenar
  arrastrando** (`sb_nav('crm-listas', …, true)`, clave `l<id>`) y tienen menú contextual
  (clic derecho) con Renombrar / Congelar (solo activas) / Eliminar, que envían POST a
  `listas.php` (`rename_list`, `freeze`, `del_list`). Vacío: «Sin listas todavía.»
- `erp_head('crm', 'CRM · Contactos'|'CRM · Negocio'|'CRM · Dashboard'|'CRM · Reporting'|'CRM · Listas'|'CRM · Importar contactos')`.
- **Buscador global** (Ctrl+K, `buscar.php`): grupos «Contactos» (busca `nombre|empresa|email|telefono LIKE`,
  orden `updated_at DESC`, subtítulo «empresa · email» o la fase, enlace `crm.php?open=ID`) y
  «Negocio» (busca `d.nombre|d.servicio|c.nombre|c.empresa`, orden `archivado, id DESC`, subtítulo
  «contacto · 12.500 €» o la fase, enlace `negocio.php?open=ID`).
- **Avisos «Toca contactar»** (`notif_sync_leads()`, cada ≤120 s al navegar si `auto_on('lead_reminder')`
  y en el cron): contactos con `fecha_prox <= hoy` en fases cuyo `tipo NOT IN ('ganada','perdida','pausa')`.
  Notificación tipo `lead` al propietario (o a todos los admins si no tiene), título
  «Toca contactar a {empresa|nombre}», cuerpo «{proxima_accion|'Seguimiento pendiente'} · dd/mm/aaaa»,
  url `crm.php?open=ID`, `ref='cto:{id}:{fecha_prox}'` (INSERT IGNORE → idempotente).
- **Popup global «Agendar reunión»** (`erpAgendar({nombre,email,whatsapp,contactId})`, ver §5.9).
- Diálogos propios: `erpConfirm`, `erpPrompt`, `erpAlert`, `toast(msg[, 'err'|'plain'])`,
  datepicker `.dpick` (visible dd/mm/aa, sincroniza un hidden ISO), selects estilizados (`csEnhance`).

---

## 2. Permisos

### 2.1 Lo que existe en el catálogo (`lib/permisos.php`)

| Permiso | Etiqueta | Requisitos (`perm_requisitos`) | Herencia (`perm_herencia`) |
|---|---|---|---|
| `ver.crm` | «CRM y ventas» – «Contactos, embudo y negocios.» | — | — |
| `crm.crear` | «Crear contactos y negocios» | `ver.crm`, `general.editar` | `general.editar` |
| `crm.editar` | «Editar el embudo» – «Mover de fase, asignar propietario y cambiar datos.» | `ver.crm`, `general.editar` | `general.editar` |
| `crm.borrar` | «Borrar del CRM» – «Eliminar contactos y negocios.» | `ver.crm`, `general.editar` | `general.editar` |
| `crm.convertir` | «Convertir en cliente» – «Pasar un negocio ganado a cliente, con sus listas y su portal.» | `ver.crm`, `ver.clientes`, `general.editar` | `general.editar` |

`can_edit()` = `can('general.editar')`; `is_owner()` = `can('admin.total')`.

### 2.2 Lo que realmente se aplica en el legado

- **Por pantalla** (`perm_de_pagina`, aplicado en `require_admin()`): `crm.php`, `crm_profile.php`,
  `crm_dashboard.php`, `crm_import.php`, `negocio.php`, `automatizaciones.php`,
  `crm_followup_email.php` → `ver.crm`. **[RIESGO]** `listas.php` está mapeada a **`ver.tareas`**
  (no a `ver.crm`), y `agendar.php`/`reuniones.php` a `ver.agenda`.
- **Por acción** (`perm_de_accion`): `crm.php => ['del'=>'crm.borrar','bulk_del'=>'crm.borrar']`,
  `negocio.php => ['del_deal'=>'crm.borrar','ganar'=>'crm.convertir']`. **[RIESGO]** Las acciones
  reales se llaman `del_contact` y `bulk` (op=`del`), y `ganar` no existe; por tanto solo
  `del_deal` queda protegido. `crm.crear`, `crm.editar` y `crm.convertir` **no se comprueban en
  ningún sitio**: en la práctica todo POST del CRM exige solo `can_edit()` (= `general.editar`).
- Reglas adicionales dentro de las páginas:
  - Todos los POST de `crm.php`, `negocio.php`, `listas.php`, `automatizaciones.php` van dentro de
    `if (POST && can_edit())`. **[RIESGO]** Si no puede editar, el POST se ignora y se pinta la página
    HTML normal con 200 → los `fetch` del front muestran «Guardado».
  - `crm_import.php`: `if(!can_edit()) 403 'Sin permisos.'`.
  - Editar fases del embudo (`stage_save|stage_add|stage_del|stage_reorder`) y el botón «Editar fases»:
    solo `is_owner()`.
  - Borrar comentario: el dueño borra cualquiera; el resto solo los suyos (`autor_id = me`).
  - Borrar vista guardada: solo las propias.
  - Regenerar la clave del cron: `is_owner()`.
  - En la UI, los controles de edición se ocultan/ponen `readonly` con `can_edit()`; en el perfil
    (`crm_profile.php`) **no** se ponen en solo lectura **[RIESGO]**.
- **Alcance por propietario**: el CRM legado **no filtra por propietario**: cualquiera con `ver.crm`
  ve y edita todos los contactos y negocios. El único uso de `propietario_id` en alcance está en el
  backend nuevo: `Acceso::clientesVisibles()` añade a los clientes visibles los `contacts.client_id`
  de los contactos de los que la persona es propietaria (sin `alcance.todos`).

### 2.3 Propuesta para la API nueva

| Acción | Permiso |
|---|---|
| Leer contactos, negocios, listas, dashboard, seguimientos | `ver.crm` |
| Crear contacto, negocio, lista, propuesta, comentario, adjunto, importar CSV | `crm.crear` |
| Editar campos, mover fase, etiquetar, asignar, marcar perdido/ganado, archivar, seguimientos | `crm.editar` |
| Borrar contacto/negocio/lista/propuesta/adjunto, borrado en lote | `crm.borrar` |
| Convertir en cliente / generar factura | `crm.convertir` (+ `finanzas.emitir` para factura, a decidir) |
| Fases del embudo, etiquetas globales, sectores | `admin.total` (como hoy) o un permiso de configuración nuevo |
| Alcance | sin `alcance.todos`: solo contactos con `propietario_id = yo` (y sus negocios), a decidir si incluir los sin propietario |

---

## 3. Modelo de datos

Todas las tablas son InnoDB utf8mb4, sin claves foráneas declaradas (la integridad la lleva el
código). Las crea `ensure_crm_schema()` (`lib/crm_lib.php`) salvo `crm_meetings`
(`crm_meetings_ensure()`) y las columnas puente (`ensure_puentes_schema()`).

### 3.1 `contacts` — persona/lead del CRM
| Columna | Tipo | Significado |
|---|---|---|
| id | INT PK AI | |
| nombre | VARCHAR(200) NOT NULL | Persona (obligatorio) |
| empresa | VARCHAR(200) NULL | Empresa; al convertir es el nombre del cliente |
| sector | VARCHAR(80) NULL, idx | Texto libre con sugerencias de `crm_sectors()` |
| email | VARCHAR(160) NULL | |
| telefono | VARCHAR(60) NULL | |
| whatsapp | VARCHAR(60) NULL | |
| linkedin, web | VARCHAR(200) NULL | |
| origen_lead | VARCHAR(40) NULL, idx | Uno de `crm_origenes()` (en el perfil es texto libre) |
| servicio_json | VARCHAR(255) NULL | Array JSON de servicios de `crm_servicios()`, p.ej. `["Web","SEO"]` |
| valor | DECIMAL(12,2) NULL | Valor estimado del contacto (independiente del de sus negocios) |
| fase | VARCHAR(40) NOT NULL DEFAULT 'lead_nuevo', idx | slug de `pipeline_stages` («Embudo de venta») |
| proxima_accion | VARCHAR(255) NULL | Texto de la próxima acción |
| fecha_prox | DATE NULL | Fecha de la próxima acción (vencida si ≤ hoy) |
| propietario_id | INT NULL, idx | `admins.id` |
| foto_url | VARCHAR(255) NULL | Sin uso en el código |
| ultima_actualizacion | VARCHAR(255) NULL | Copia (≤80 chars, espacios colapsados) del último comentario (`crm_sync_ultima`) |
| fecha_creacion | TIMESTAMP DEFAULT now | |
| fecha_ultimo_contacto | DATE NULL | Se pone a hoy al registrar llamada/email/WhatsApp/reunión |
| updated_at | TIMESTAMP ON UPDATE | Lo usa el buscador global |
| client_id | INT NULL (puente) | Cliente creado al convertir |

### 3.2 `pipeline_stages` — fases del embudo (compartidas por contactos y negocios)
| Columna | Significado |
|---|---|
| id, nombre VARCHAR(80), slug VARCHAR(40) UNIQUE | slug = clave usada en `contacts.fase` / `deals.fase` |
| orden INT | Orden de columnas (`crm_stages()` ordena por `orden,id`) |
| probabilidad INT 0–100 | Se copia a `deals.probabilidad` al mover |
| tipo VARCHAR(20) | `abierta` · `ganada` · `perdida` · `pausa` |
| color VARCHAR(20) | Hex |
| dias_alerta_estancamiento INT | **Sin uso** (el aviso usa 14/30 días fijos) |

Semilla (si la tabla está vacía):

| nombre | slug | orden | prob | tipo | color |
|---|---|---|---|---|---|
| Lead nuevo | lead_nuevo | 1 | 5 | abierta | #64748b |
| Onboarding | onboarding | 2 | 15 | abierta | #2563eb |
| Onboarding hecho | onboarding_hecho | 3 | 30 | abierta | #1d4ed8 |
| Propuesta enviada | propuesta | 4 | 50 | abierta | #a16207 |
| Negociación | negociacion | 5 | 70 | abierta | #c2410c |
| Contrato firmado | contrato | 6 | 90 | abierta | #0f7a3d |
| Cerrado ganado | ganado | 7 | 100 | ganada | #047857 |
| Cerrado perdido | perdido | 8 | 0 | perdida | #b91c1c |
| En pausa | pausa | 9 | 0 | pausa | #64748b |

Slugs con lógica cableada: `lead_nuevo` (alta de contacto/negocio, regla de seguimiento A),
`propuesta` (regla B), `ganado` y `perdido` (métricas, dashboard, `mark_lost`, regla C).

### 3.3 `deals` — negocio/oportunidad
| Columna | Significado |
|---|---|
| id, contact_id INT NOT NULL idx | Contacto dueño del negocio |
| nombre VARCHAR(200) | Por defecto el nombre del contacto |
| valor DECIMAL(12,2) | Importe (base imponible al facturar) |
| servicio VARCHAR(80) | Un servicio (por defecto el primero del contacto) |
| fase VARCHAR(40) idx, probabilidad INT | slug + probabilidad copiada de la fase |
| fecha_cierre_prevista DATE | |
| fecha_entrada_fase DATE | Para «N días sin avanzar» y la regla B |
| fecha_creacion TIMESTAMP | |
| fecha_cierre_real DATE | Al entrar en fase ganada/perdida; NULL al volver a abierta |
| motivo_perdida VARCHAR(40) | clave de `crm_motivos_perdida()` |
| motivo_perdida_txt VARCHAR(255) | Comentario opcional |
| fecha_reactivacion DATE | hoy + N meses según motivo (null = nunca) |
| propietario_id INT | Copiado del contacto al crear |
| orden INT | Siempre 0 (no hay orden dentro de columna) |
| archivado TINYINT idx, fecha_archivado DATE | |
| client_id INT (puente) | Cliente del contacto tras convertir |
| invoice_id INT (puente) | Factura borrador generada |

### 3.4 Resto de tablas
- **`billing_data`** (1:1 con contacto, UNIQUE `contact_id`): `razon_social`, `cif`, `direccion`, `cp`,
  `ciudad`, `provincia`, `pais`, `email_facturacion`, `iban` (todos NULL si vacíos). Upsert por campo.
- **`comments`**: `contact_id`, `autor_id` (admins), `tipo` (`nota|llamada|whatsapp|email|reunion`),
  `contenido` MEDIUMTEXT (texto plano), `menciones` VARCHAR(255) (JSON array de los `@nombre` escritos),
  `fecha`.
- **`activities`** (timeline automático): `contact_id` NULL, `deal_id` NULL, `tipo` VARCHAR(40),
  `descripcion` VARCHAR(255), `fecha`. Tipos usados: `creado`, `fase`, `nota`, `llamada`, `whatsapp`,
  `email`, `reunion`, `propuesta`, `archivo`, `negocio`, `perdida`, `cliente`, `factura`, y además los
  slugs de canal `llamar`/`whatsapp`/`email` desde «Seguimiento hecho».
- **`lists`**: `nombre`, `descripcion`, `tipo` (`activa` = dinámica por condiciones · `estatica` = congelada),
  `condiciones` MEDIUMTEXT (JSON `{q,sector,origen,fase,servicio,prop,vmin,vmax,quick}`),
  `fecha_creacion`, `fecha_congelado`.
- **`list_members`** PK(`list_id`,`contact_id`): en estáticas, la lista entera; en activas, los añadidos
  a mano que se suman (UNION) a los que cumplen el filtro.
- **`proposals`**: `contact_id`, `nombre`, `importe` DECIMAL, `estado` (`enviada|vista|aceptada|rechazada`,
  def. `enviada`), `fecha_envio` DATE, `url_archivo` VARCHAR(400).
- **`attachments`**: `contact_id`, `nombre` (original, ≤240), `url` (`../archivo.php?d=crm&f=<fichero>`;
  formato antiguo `../uploads/crm/<fichero>`), `tipo` (MIME), `fecha_subida`. Ficheros en `uploads/crm/`.
- **`crm_tags`**: `nombre` UNIQUE, `color`. **`contact_tags`** PK(contact_id,tag_id) y **`deal_tags`**
  PK(deal_id,tag_id). Paleta sugerida `crm_tag_colors()` (no usada en UI; el modal usa `<input type=color>`,
  def. `#5b8def`).
- **`saved_views`**: `usuario_id` (NULL = global, visible para todos), `nombre`, `modulo` (`'crm'`),
  `filtros` MEDIUMTEXT (el **query string literal**, p.ej. `?sector=Salud&quick=act7`), `fecha`.
- **`follow_up_tasks`**: `contact_id`, `deal_id` NULL, `tipo_accion` (= canal), `canal`
  (`llamar|whatsapp|email|reunion`), `descripcion`, `fecha_prevista` DATE idx, `estado`
  (`pendiente|hecha|omitida`), `secuencia_id` (`lead_nuevo|propuesta|reactivacion|manual|auto`),
  `ciclo` INT (1 automáticas, 99 manuales).
- **`email_log`** (registro de ejecuciones del resumen diario): `fecha_ejecucion`, `enviado`
  (nº de destinatarios notificados), `n_acciones`, `destinatarios` (usernames separados por comas).
- **`crm_meetings`**: `contact_id` NOT NULL idx, `fecha` DATE, `hora` VARCHAR(10) (texto libre),
  `titulo` VARCHAR(200) (solo lo rellena `reuniones.php`), `estado`
  (`agendada|realizada|no_show|cancelada`), `notas` TEXT, `notas_doc` TEXT (JSON
  `[{title,url}]` de los Docs de Gemini), `created_at`. También la leen `reuniones.php` y el portal
  (`index.php`, vía `clients.contact_id`).
- **`settings`** (clave/valor) usadas por el CRM: `crm_sectors` (JSON; **sin pantalla para editarlo**),
  `crm_digest_key` (**sin pantalla para fijarla**), `auto_lead_reminder`, `auto_followups`,
  `auto_daily_digest` (interruptores, def. «1»).
- **Columnas puente en otras tablas**: `clients.contact_id`, `clients.partner_id`,
  `time_entries.acc_id`, `accounting.admin_id`.

### 3.5 Catálogos fijos (crm_lib.php)
- Orígenes: `Outreach, Referido, Google Ads, Meta Ads, Web/formulario, Evento, LinkedIn, Otro`.
- Servicios: `Web, SEO, Meta Ads, Automatización, Mantenimiento, Otro`.
- Sectores (por defecto si no hay `crm_sectors`): `Restauración, Ecommerce, Servicios, Salud, Inmobiliaria, Formación, Otro`.
- Tipos de comentario: `nota`→«Nota» (no reinicia), `llamada`→«Llamada», `whatsapp`→«WhatsApp»,
  `email`→«Email», `reunion`→«Reunión» (estos cuatro ponen `fecha_ultimo_contacto = hoy`).
- Motivos de pérdida → meses de reactivación: `precio` «Precio» 3 · `timing` «Timing / no es el momento» 3 ·
  `competencia` «Se ha ido con la competencia» 6 · `sin_respuesta` «Sin respuesta» 6 ·
  `no_cualificado` «No cualificado» nunca · `otro` «Otro» 6.
- Estados de propuesta: Enviada, Vista, Aceptada, Rechazada.
- Estados de reunión (color): Agendada #6b7280, Realizada #12a150, No se presentó #c76a12, Cancelada #c0343a.
- Canales de seguimiento: `llamar` «LLAMAR HOY», `whatsapp` «ESCRIBIR WHATSAPP», `email` «ENVIAR EMAIL»,
  `reunion` «REUNIONES».

### 3.6 Tablas legadas `crm_leads` / `crm_types`
Las crea `db.php` (y la migración `0001_esquema_base.php`): `crm_leads(empresa, segmento b2b/b2c,
estado, localizacion, origen, seguimiento, paso_flujo, proxima_accion, fecha_prox, intentos, telefono,
correo, valor, notas, responsable_id, …)` y `crm_types(slug, nombre, orden)` con semilla
`b2b «B2B · Agencias»`, `b2c «B2C · Clientes»`. **Ningún archivo actual las lee ni escribe** (el
comentario de `notif_sync_leads` confirma que es «la del CRM viejo y ya no se escribe»). Recomendación:
no exponerlas en la API; migrar sus filas a `contacts` si hubiera datos y eliminarlas.

---

## 4. Lógica transversal

- **`crm_activity($contact,$deal,$tipo,$desc)`**: inserta en `activities` (traga errores). Toda acción
  relevante escribe una.
- **`crm_sync_ultima($cid)`**: recalcula `contacts.ultima_actualizacion` = último comentario (≤80 chars).
- **`crm_sync_fase_contacto($cid)`** (tras crear/mover/perder/archivar/desarchivar/borrar negocio y al
  borrar una fase): si el contacto tiene negocios no archivados → fase = la abierta de mayor `orden`
  (las de `pausa` no cuentan); si no hay abiertas pero hay ganada → primera fase `ganada`; si solo
  perdidas → primera `perdida`; sin negocios → no se toca. **[RIESGO]** pisa el cambio manual de fase
  del contacto en cuanto se toca uno de sus negocios.
- **Fecha último contacto = hoy** al: comentario de tipo interacción, `crm_act` de
  llamada/email/whatsapp/reunion, agendar reunión, «Seguimiento hecho».
- **Papelera**: `pap_borrar_flash(tabla,id,tipo,titulo,hijos,msg)` guarda la fila y las hijas indicadas,
  las borra y deja un aviso con «Deshacer» (30 días). Contacto: hijas `contact_tags`, `activities`,
  `comments`. Negocio: hija `deal_tags`.
- **`num_es()`** (auth.php): parsea importes «a la española»: «1.234,56»→1234.56, «12,5»→12.5,
  «12.5»→12.5, «1.234»→1234, «1.200 €»→1200; vacío→null.

---

## 5. Pantallas

### 5.1 Contactos — `crm.php`

**Propósito**: tabla editable en línea de todos los contactos con filtros, vistas, etiquetas,
acciones en lote y ficha emergente.

**URL / GET**: `q`, `sector`, `origen`, `fase`, `servicio`, `prop` (admin id), `tag` (tag id),
`vmin`, `vmax` (valor €), `fdesde`, `fhasta` (ISO, sobre `DATE(fecha_creacion)`), `quick`, `sort`, `dir`
(`asc`|otro=desc), `open` (abre la ficha de ese id), `frag=profile&id=` (fragmento HTML de la ficha),
`bulk_export=1&ids=1,2,3` (CSV).

Filtros (AND):
- `q` → `nombre|empresa|email|telefono LIKE %q%`.
- `servicio` → `servicio_json LIKE '%"Web"%'`.
- `tag` → `id IN (SELECT contact_id FROM contact_tags WHERE tag_id=?)`.
- `quick`: `''` «Todos» · `sin_contactar` «Sin contactar» (`fecha_ultimo_contacto IS NULL`) ·
  `act7` «Sin actividad +7 días» (NULL o ≤ hoy−7) · `act30` «+30 días» · `vencidas` «Acción vencida»
  (`fecha_prox <= hoy`) · `perdido` «Cerrado perdido» (`fase='perdido'`).
- Orden: claves `nombre, empresa, sector, valor, fase, ult(fecha_ultimo_contacto), prox(fecha_prox),
  creado(fecha_creacion)`; por defecto `fecha_creacion DESC, id DESC`. Clic en cabecera alterna asc/desc,
  flecha «↑/↓».
- **Sin paginación**: carga todos los contactos que cumplen.

**Layout**
1. Cabecera: «Contactos» + contador `N` o `N de TOTAL` (si hay filtros avanzados o búsqueda).
   Derecha (si `can_edit`): «Importar» (→ `crm_import.php`) y botón negro «Nuevo contacto».
2. Barra: buscador («Buscar nombre, empresa, email, teléfono…», envía al `change`), botón «Filtros»
   (badge con nº de filtros avanzados; abre panel), desplegable «Vistas» (lista de vistas guardadas
   propias+globales, ✕ para borrar las propias; vacío «Sin vistas. Filtra y pulsa «Guardar vista».»;
   botón «Guardar filtros actuales»), botón «Etiquetas» (modal, solo editores).
3. Píldoras rápidas (`quick`).
4. Panel de filtros (rejilla 4 col.; abierto si hay filtros): Sector, Origen, Embudo de venta, Servicio,
   Propietario, Etiqueta (selects «Cualquiera»), Valor (€) mín/máx, «Creado entre» desde/hasta
   (datepicker), enlace «Limpiar filtros». Cada cambio reenvía el formulario.
5. Chips de filtros activos (««texto»», «Sector: X», «Embudo: X», «≥ N €», «Desde …») con ✕ y «Limpiar todo».
6. Si hay `q` y coinciden negocios no archivados (máx. 8): bloque «Negocios que coinciden (n)» con
   nombre, contacto, valor y píldora de fase (enlaza a `negocio.php`, no al negocio concreto).
7. Tabla con scroll propio (alto `100vh-250px`), cabecera fija y las dos primeras columnas fijas a la izquierda:

| Col | Contenido / edición |
|---|---|
| ☐ | Checkbox (solo editores) + «seleccionar todos» con estado indeterminado |
| Nombre | Avatar (2 iniciales, color por nombre) + nombre en negrita (abre ficha) + chips de etiquetas |
| Empresa | input inline |
| Sector | input inline con datalist de sectores |
| Email | input inline |
| Teléfono | input inline |
| Origen | select inline (orígenes) |
| Servicio | chips; clic abre popover de checkboxes (multi) → guarda `servicio_json` |
| Valor | input numérico alineado a la derecha, formateado «12.500» |
| Embudo de venta | píldora de color con flecha; clic abre popover de fases con ✓ en la actual; cambio optimista con reversión |
| Última actualización | texto (último comentario), solo lectura |
| Últ. contacto | datepicker inline |
| Próxima acción | input texto («Acción…») + datepicker pequeño («dd/mm/aa»); punto rojo y fecha roja si vencida (title «Acción vencida») |
| Propietario | select (admins) |
| Creado | dd/mm/aaaa |
| ⋯ | menú de acciones (= menú contextual) |

Estados vacíos: con contactos pero sin coincidencias → «Ningún contacto coincide» / «Prueba a quitar
algún filtro o a buscar otra cosa.»; sin contactos → «Aún no hay contactos» / «Aquí verás tus leads y
contactos. Crea el primero con el botón «Nuevo contacto» de arriba.»

Móvil (≤640px): la tabla pasa a filas-tarjeta (solo nombre, empresa y fase visibles), panel de filtros 1 col.

**Barra de acciones en lote** (flotante abajo al centro, aparece con ≥1 seleccionado):
«N seleccionados» · «Asignar» (popover: «Sin propietario» + admins) · «Añadir a lista» (popover:
«Crear lista nueva…» + listas; vacío «Aún no has creado ninguna lista.») · «Etiquetar» (popover de
etiquetas con color; vacío «Aún no has creado ninguna etiqueta.») · «Exportar» · «Eliminar» (rojo) · ✕.
Escape cierra popovers y, si no había, limpia la selección.

**Menú contextual** (clic derecho en fila o «⋯»): cabecera con el nombre; «Abrir ficha»; «Enviar email»
(si email; abre `https://mail.google.com/mail/?view=cm&to=…`); «Llamar» (`tel:`); «WhatsApp»
(`https://wa.me/<dígitos de whatsapp o teléfono>`); «Ver ficha de cliente» (si convertido); — editores:
«Crear negocio» (confirm «¿Crear un negocio para este contacto?» → POST `negocio.php action=new_deal
contact_id=ID` → navega a `negocio.php`), «Convertir en cliente» (si no convertido), «Eliminar contacto» (rojo).

**Atajos**: `/` enfoca el buscador; `n` abre «Nuevo contacto» (fuera de inputs y con la ficha cerrada).

**Acciones POST** (`action=`; todas requieren `can_edit`; las marcadas JSON responden `{"ok":1}`
salvo indicación; el resto redirige a `crm.php` + filtros):

| action | Parámetros | Validación / efecto |
|---|---|---|
| `inline` (JSON) | `id, field, val` | `field` ∈ {nombre, empresa, sector, email, telefono, whatsapp, linkedin, web, origen_lead, valor, proxima_accion, fecha_prox, propietario_id, fecha_ultimo_contacto, servicio_json, fase}. `valor`→`num_es`; fechas ''→NULL; `propietario_id` ''→NULL; `fase` debe existir en `pipeline_stages` (si no, no guarda pero responde ok) y registra actividad `fase` «Fase cambiada a {nombre}». Sin validación de email/teléfono. |
| `new_contact` | `nombre*`, `empresa`, `sector`, `email`, `telefono`, `origen_lead`, `propietario_id` | nombre obligatorio (si vacío no hace nada); `fase='lead_nuevo'`; actividad `creado` «Contacto creado»; flash «Contacto creado». |
| `del_contact` | `id` | Papelera (hijas contact_tags, activities, comments) y DELETE; flash «Contacto «X» eliminado» con Deshacer. Confirm UI: «¿Eliminar este contacto?». |
| `add_comment` (JSON) | `cid, tipo, body` | tipo fuera de catálogo → `nota`; body no vacío; extrae menciones `@([\p{L}0-9_.\-]+)` a JSON; actividad `{tipo}` «{Etiqueta}: {80 chars}»; si tipo de interacción → `fecha_ultimo_contacto=hoy`; `crm_sync_ultima`. **No notifica a los mencionados.** |
| `del_comment` (JSON) | `id, cid` | dueño: cualquiera; resto: solo propios; `crm_sync_ultima`. |
| `save_billing` (JSON) | `cid, field, val` | field ∈ {razon_social, cif, direccion, cp, ciudad, provincia, pais, email_facturacion, iban}; upsert; vacío→NULL. |
| `add_proposal` (JSON) | `cid, nombre, importe, estado, fecha, url` | nombre def. «Propuesta»; importe `num_es`; estado def. `enviada`; fecha def. hoy; actividad `propuesta` «Propuesta enviada». La UI solo manda nombre+importe. |
| `del_proposal` (JSON) | `id` | DELETE directo (sin comprobar contacto). Confirm «¿Eliminar propuesta?». |
| `crm_act` (JSON) | `cid, tipo, desc` | actividad libre; si tipo ∈ llamada/email/whatsapp/reunion → `fecha_ultimo_contacto=hoy`. Lo usan los botones Email («Email abierto») y Llamar («Llamada iniciada») del perfil. |
| `to_client` (JSON) | `cid` | `pu_lead_a_cliente(cid)` (ver §6.1). Respuesta `{ok,id,user,pass,ya,msg}`. |
| `meeting` (JSON) | `cid, fecha dd/mm/aaaa, hora` | Inserta `crm_meetings` agendada, actividad, último contacto; responde `{ok,mid}`. **Código muerto**: `pfMeeting()` ya no se llama (el perfil usa `erpAgendar`/`agendar.php`). |
| `meeting_notes` (JSON) | `mid, cid` | `gcal_meeting_notes(meId, mid)`; errores: «Falta la reunión.», «No estás conectado a Google Calendar.», «Todavía no encuentro el evento de esta reunión en Google. Se enlaza al agendarla desde aquí.», «El evento existe pero aún no tiene notas de Gemini adjuntas. Suelen aparecer un rato después de la reunión.». Éxito: guarda `notas_doc` = JSON docs, añade la descripción del evento a `notas` si no estaba, actividad «Notas de Gemini vinculadas (n doc)», responde `{ok,docs:[{title,url}],descripcion}`. |
| `meeting_outcome` (JSON) | `mid, cid, estado, notas, fase` | estado ∈ realizada/no_show/cancelada (def. realizada); actualiza estado+notas; actividad «Reunión realizada / No se presentó a la reunión / Reunión cancelada[: notas 120]»; si `fase` válida → cambia `contacts.fase` + actividad. |
| `bulk` | `ids[]`, `op`, `propietario_id`, `list_id`, `list_name`, `tag_id` | `owner` (0/''→NULL) · `del` (**DELETE directo, sin papelera ni hijas**; confirm «Se eliminarán N contacto(s). No se puede deshacer.») · `list` (INSERT IGNORE list_members) · `newlist` (crea lista `estatica` con `fecha_congelado=NOW()`, flash «Lista «X» creada con N contacto(s)») · `tag` (INSERT IGNORE contact_tags). Flash «N contacto(s) actualizados». |
| `tag_create` | `nombre, color` | nombre no vacío; duplicado se ignora en silencio. |
| `tag_del` | `id` | borra la etiqueta y sus filas en contact_tags y deal_tags. Confirm «¿Eliminar la etiqueta?» / «Se quita de todos los contactos y negocios.» |
| `contact_tag` (JSON) | `cid, tag_id, on=1|0` | alterna la etiqueta del contacto. |
| `upload_att` (multipart) | `cid, file` | lista blanca de extensiones (imágenes, pdf, office, txt, csv, rtf, audio/vídeo, zip/rar/7z); si no: flash «Ese tipo de archivo no está permitido»; nombre seguro aleatorio en `uploads/crm/`; `img_optimizar` (reduce imágenes); actividad `archivo` «Archivo adjuntado: X». Redirige a `crm.php?open=cid`. |
| `att_del` (JSON) | `id, cid` | borra el fichero físico (soporta url vieja y nueva) y la fila. |
| `save_view` | `nombre, filtros` (= `location.search`) | inserta vista del usuario, redirige con esos filtros. Prompt «Guardar vista» / «Se guardan los filtros que tienes puestos ahora mismo.» |
| `del_view` | `id` | solo propias. Confirm «¿Eliminar esta vista?». |

**Export CSV** (`bulk_export`): UTF-8 con BOM, `contactos.csv`, columnas `Nombre, Empresa, Sector, Email,
Teléfono, WhatsApp, Origen, Valor, Embudo, Propietario` (fase y propietario por nombre).

**Comportamiento JS clave**
- Guardado en línea `cmSave`: POST `inline`; guarda el valor previo (`_cmPrev`); si la respuesta no es
  2xx revierte y avisa «No se pudo guardar: el campo vuelve a su valor anterior»; si va bien «Guardado».
- Cambio de fase: optimista; si falla «No se pudo guardar: la fase vuelve a la anterior».
- Popovers posicionados `fixed` que se abren hacia arriba si no caben, se cierran con clic fuera, Escape
  y scroll (salvo scroll dentro del propio popover).

### 5.2 Ficha del contacto — `crm.php?frag=profile&id=` (`crm_profile.php`)

Modal grande (1020px, 95vw; pantalla completa en móvil) cargado por AJAX encima de la tabla. Navegación
‹ › entre los contactos **del listado filtrado actual** con contador «i / N» y teclas ←/→; Escape cierra.
No encontrado: «Contacto no encontrado.»; error de red: «Error al cargar.».

- **Cabecera fija**: avatar, nombre editable inline (`inline nombre`), subtítulo «sector · empresa ·
  servicios» + píldora de fase.
- **Barra de acciones**: «Crear nota» (enfoca el textarea) · «Email» (Gmail compose + `crm_act email
  'Email abierto'`) · «Llamar» (`tel:`, copia el número y `crm_act llamada 'Llamada iniciada'`) ·
  «Agendar reunión» (`erpAgendar`, §5.9) · si convertido «Ver ficha de {cliente}» (→ `client.php?id=`),
  si no y editor «Convertir en cliente».
- **Cuerpo en 2 columnas** (1 en ≤820px):
  - Izquierda «Comentarios / Actualizaciones»: selector de tipo (Nota, Llamada, WhatsApp, Email,
    Reunión), textarea «Escribe una actualización… (@ para mencionar)», botón «Publicar». Lista
    (más reciente primero): avatar autor, nombre, etiqueta de tipo, «dd/mm/aaaa HH:MM», ✕ borrar,
    texto con saltos de línea y menciones `@usuario` resaltadas si coinciden con un admin. Vacío
    «Sin comentarios todavía.»
  - Derecha, secciones en orden:
    1. «Etiquetas»: todas las etiquetas como chips alternables (activos con su color). Vacío «Sin
       etiquetas. Créalas en Contactos › Etiquetas.» Toast «Etiqueta añadida/quitada».
    2. «Datos de contacto»: Email, Teléfono, WhatsApp, LinkedIn, Web (inline).
    3. «Datos comerciales»: Sector (datalist), Origen (texto libre), Valor (€), Propietario (select).
    4. «Datos de facturación»: Razón social, CIF / NIF, IBAN, Dirección, CP, Ciudad, Provincia, País,
       Email facturación (`save_billing`).
    5. «Propuestas enviadas» + «Añadir» (dos prompts: «Nueva propuesta»/«Nombre de la propuesta» e
       «Importe de la propuesta»/«En euros. Puedes dejarlo vacío si aún no lo sabes.»). Fila: nombre,
       «dd/mm/aaaa · 12.500 €», píldora de estado, icono enlace si `url_archivo`, ✕. Vacío «Sin propuestas.»
       **No se puede cambiar el estado ni la URL desde la UI.**
    6. «Archivos adjuntos» + «Subir» (input file oculto). Fila: icono, nombre (enlace nueva pestaña),
       fecha, ✕ (confirm «¿Eliminar el archivo?»). Vacío «Sin archivos.»
    7. «Listas» (solo si pertenece a alguna estática/forzada — se calcula solo con `list_members`): chips.
    8. «Reuniones» (solo si hay): tarjeta por reunión con «dd/mm/aaaa · hora», píldora de estado; si
       pasada y agendada → borde discontinuo ámbar + botón «Registrar resultado»; si pasada → botón
       «Notas de Gemini» («Buscando…» mientras tanto; toasts «Notas de Gemini vinculadas ✓» /
       «Enlazado, pero aún sin notas»); notas en texto; enlaces a Docs («Notas de la reunión»).
    9. «Historial de actividad»: últimas 60 `activities` (descripción o tipo + «dd/mm/aaaa HH:MM»).
       Vacío «Sin actividad registrada.»
- **Modal «Resultado de la reunión»**: «¿Se realizó?» segmentado (Realizada / No se presentó /
  Cancelada), «Notas» (placeholder «¿Qué salió de la reunión? Próximos pasos…»), «Mover a fase
  (opcional)» («— No cambiar —» + fases), botones Cancelar / «Guardar resultado» → toast «Resultado guardado».
- **Convertir en cliente**: confirm «¿Convertir en cliente?» / «Se crea la ficha con sus datos de
  facturación y sus listas por defecto. El contacto seguirá en el CRM, enlazado al cliente nuevo.»
  (botón «Convertir»). Si `ya` → toast y navega a la ficha. Si nuevo → alerta con título = `msg`
  («Cliente «X» creado.») y cuerpo «Usuario: …\nContraseña: …\n\nApúntala ahora: no se vuelve a
  mostrar. Puedes cambiarla desde la ficha del cliente.», botón «Abrir la ficha» → `client.php?id=`.

### 5.3 Negocio (embudo kanban) — `negocio.php`

**URL**: `?arch=1` (archivados), `?open=ID` (abre el detalle del negocio).

**Datos**: todos los negocios (`archivado = 0|1`) con `c.nombre, c.empresa, c.sector, c.client_id`,
orden `d.orden, d.id DESC`, agrupados por fase; etiquetas por negocio.

**Métricas** (solo vista activa; 5 tarjetas):
«Negocios abiertos» (nº en fases `abierta` del tablero) «en el embudo» · «Valor pipeline» (suma valor
abiertos) «bruto abierto» · «Ganado (mes)» (suma valor `fase='ganado'` con `fecha_cierre_real ≥ día 1
del mes`) «N negocios» · «Tasa conversión» (ganados/(ganados+perdidos), histórico) «ganado vs cerrado» ·
«Ticket medio» (media valor ganados) «negocios ganados». (Métricas de ganado/perdido incluyen archivados.)

**Cabecera**: «Negocio»; «Editar fases» (dueño, vista activa); «Archivados» / «← Activos» (editores);
«Nuevo negocio» (editores, vista activa).

**Tablero**: una columna (264px, scroll horizontal; 82vw con scroll-snap en móvil) por **cada fase**
(incluidas ganado/perdido/pausa). Cabecera: asa de arrastre (solo dueño), punto de color, nombre,
contador. Pie: «Total» con la suma de valor. Columna vacía: «—».
Tarjeta: nombre del negocio; «empresa|contacto · sector»; fila con valor «12.500 €», chip de servicio,
etiquetas de color, avatar del propietario, fecha de cierre prevista «dd/mm» con icono calendario. En
fases abiertas, si lleva ≥14 días en la fase (`fecha_entrada_fase`) → «⏱ N días sin avanzar»
(naranja; rojo ≥30) + enlace «archivar». Botón «⋯» (editores). Clic → detalle (solo editores).
Archivados vacío: «No hay negocios archivados. Los que archives desde el embudo aparecerán aquí.»

**Drag & drop**
- Tarjetas (editores): soltar en otra columna → mueve en el DOM y POST `move_deal`; luego refresca
  métricas, contadores y totales sin recargar. Soltar en una columna de tipo `perdida` → **no** mueve:
  abre el modal de motivo; al confirmar se recarga. Soltar en `ganada` mueve sin confirmación.
- Columnas (solo dueño): reordenar fases arrastrando por el asa → POST `stage_reorder order[]=stageId…`.
- No hay orden dentro de una columna (no se persiste posición).

**Modales**
- «Nuevo negocio»: «Contacto *» (select de todos: «nombre — empresa», placeholder «Selecciona un
  contacto…»), «Nombre del negocio» («(por defecto, el del contacto)»), «Valor (€)», «Cierre previsto»
  (datepicker); «Crear negocio».
- «¿Por qué se perdió?»: botones por motivo con subtexto «reactivar en N meses»; «Comentario
  (opcional)» («Detalle…»); «Marcar perdido». Sin motivo → toast «Elige un motivo».
- Detalle del negocio (480px): título = nombre, subtítulo «empresa|contacto · fase»; campos inline
  «Nombre del negocio», «Valor (€)», «Cierre previsto», «Servicio» (select «—» + servicios),
  «Propietario», «Fase del embudo» (select; elegir `perdido` abre el modal de motivo), «Etiquetas»
  (chips alternables, si hay etiquetas). Botones: «Ver contacto» (`crm.php?open=cid`), «Crear cliente» /
  «Ver cliente», «Crear factura» / «Ver factura» (`facturas.php?edit=ID`), «Marcar ganado» (confirm
  «¿Marcar como ganado?» / «Pasa a la fase «Ganado» y cuenta en el importe cerrado.»), «Perdido»,
  «Archivar» (confirm «¿Archivar este negocio?» / «Sale del tablero pero no se borra: lo tienes en el
  filtro de archivados.»), «Eliminar» (confirm «¿Eliminar este negocio?» / «El negocio se guarda en la
  papelera 30 días por si te arrepientes.»).
- Menú contextual de tarjeta: «✎ Editar negocio», «✓ Marcar ganado», «✕ Marcar perdido», «Mover a fase»
  (todas menos la actual), «→ Ver contacto», «→ Convertir en cliente»/«→ Ver cliente», «→ Generar
  factura»/«→ Ver factura», «Archivar», «Eliminar».
- «Editar fases del embudo» (dueño): texto «Nombre, color y probabilidad de cierre (%) de cada fase.
  Puedes añadir pasos nuevos o quitar los que ya no uses.»; fila por fase: color, nombre, prob (0–100) %,
  papelera (solo fases `abierta`; confirm «¿Quitar la fase «X»?» / «Los negocios que estén en «X»
  pasarán a la primera fase abierta del embudo; no se pierde ninguno.») o candado «Fase estructural del
  ERP: no se puede quitar»; «Añadir fase» (prompt «Nombre de la nueva fase», «Ej: Demo agendada»);
  Cancelar / Guardar.
- Puente cliente: confirm «¿Convertir en cliente?» / «Se crea la ficha de cliente con los datos de
  facturación del contacto y sus listas por defecto.»; alerta de credenciales igual que en §5.2.
- Puente factura: confirm «¿Generar la factura?» / «Se crea en borrador con el importe y el servicio del
  negocio, para que la repases antes de emitirla.» («Generar borrador») → toast `msg` y navega a
  `facturas.php?edit=ID`.

**Acciones POST** (`can_edit`; JSON `{"ok":1}` salvo indicación):

| action | Parámetros | Efecto |
|---|---|---|
| `new_deal` (redirect) | `contact_id*, nombre, valor, fecha_cierre` | valor `num_es` o el del contacto; servicio = primer servicio del contacto; fase `lead_nuevo` con su probabilidad; propietario del contacto; `fecha_entrada_fase=hoy`; actividad `negocio` «Negocio creado: X»; sync fase contacto; flash «Negocio creado». |
| `move_deal` | `id, fase` | fase debe existir. ganada/perdida → `fecha_cierre_real=hoy`; abierta/pausa → `fecha_cierre_real=NULL`; siempre `probabilidad` de la fase y `fecha_entrada_fase=hoy` (aunque no cambie). Actividad `fase` «Movido a X» si cambia; sync contacto; flash. **No crea cliente ni factura al ganar.** |
| `mark_lost` | `id, motivo*, motivo_txt` | motivo válido; fase `perdido`, prob 0, cierre real hoy, motivo, `fecha_reactivacion` = hoy + meses (o NULL); actividad `perdida` «Perdido — {motivo}[: txt]»; sync. |
| `deal_inline` | `id, field, val` | field ∈ {nombre, valor, servicio, fecha_cierre_prevista, propietario_id}. |
| `archive_deal` | `id` | `archivado=1, fecha_archivado=hoy`; sync; flash «Negocio archivado». |
| `unarchive_deal` (redirect) | `id` | `archivado=0`; sync. **Sin botón en la UI.** |
| `del_deal` | `id` | papelera (hija deal_tags) + DELETE; sync; responde `{ok,undo:<trash id>}`. Protegido por `crm.borrar`. |
| `to_client` | `id` (deal) | `pu_lead_a_cliente(contact_id, deal_id)`. |
| `to_invoice` | `id` | `pu_negocio_a_factura(id)`. |
| `deal_tag` | `id, tag_id, on` | alterna etiqueta. |
| `stage_reorder` (dueño) | `order[]` | `orden = índice`. |
| `stage_save` (dueño, redirect) | `sid[]`, `nombre[id]`, `color[id]`, `prob[id]` | nombre vacío → «—»; prob 0–100; flash «Fases del embudo actualizadas». |
| `stage_add` (dueño, redirect) | `nombre, prob, color` | slug ASCII `[a-z0-9_]` único (`_2`, `_3`…); tipo `abierta`; se inserta tras la última abierta desplazando las de cierre; color `#rrggbb` o `#94a3b8`; flash «Fase «X» añadida». |
| `stage_del` (dueño, redirect) | `id` | solo tipo `abierta`; mueve sus negocios a la primera abierta restante (prob de esa fase); sync contactos; flash «Fase eliminada; sus negocios pasaron a «X»» / «No se puede eliminar: es la única fase abierta del embudo» / «Esa fase no se puede eliminar». |

### 5.4 Dashboard — `crm_dashboard.php`

**URL**: `d1`, `d2` (YYYY-MM-DD, validados por regex). Sin rango = «Todo».
Formulario: «Desde», «Hasta» (datepicker), «Aplicar»; atajos «Este mes», «Últimos 3 meses», «Este año», «Todo».

**KPIs** (7 tarjetas; `rango()` filtra la columna indicada):
| Tarjeta | Valor | Sub |
|---|---|---|
| Contactos | COUNT contacts (fecha_creacion) | «en el periodo» / «total en CRM» |
| Negocios abiertos | COUNT deals en fases abiertas (fecha_creacion) | «X € en pipeline» |
| Ganado | SUM valor ganados (fecha_cierre_real) | «N ganados en el periodo/histórico» |
| Tasa conversión | ganados/(ganados+perdidos) % | «N ganados · M perdidos» |
| Ticket medio | AVG valor ganados | «negocios ganados» |
| Negocios perdidos | COUNT perdidos | «en el periodo/histórico» |
| Cierres previstos | abiertos con `fecha_cierre_prevista ≤ hoy+30` (sin rango) | «próximos 30 días» |

**Gráficas** (Chart.js 4.4.1; «Sin datos todavía» si vacío). Meses: los del rango (máx. 36) o los últimos 6.
1. «Embudo de venta» (ancha) – barras horizontales, nº negocios por fase abierta+ganada, color de fase.
2. «Valor en pipeline por fase» – barras €, fases abiertas.
3. «Ganados vs perdidos por mes» – barras agrupadas (verde/rojo).
4. «Tasa de conversión mensual» – línea %.
5. «Valor ganado por mes» – barras € verdes.
6. «Contactos nuevos por mes» – línea naranja (no aplica el rango, solo los meses).
7. «Contactos por origen» – donut.
8. «Contactos por sector» – barras horizontales, top 8.
9. «Motivos de pérdida» – donut con etiquetas de motivo.
10. «Negocios abiertos por propietario» – barras horizontales («Sin asignar»).
11. «Servicios más presupuestados» – barras horizontales (cuenta en `contacts.servicio_json`).
12. «Contactos por fase del embudo» – donut (fases con >0).
13. «Días medios en cada fase» – barras, media `DATEDIFF(hoy, fecha_entrada_fase)` por fase abierta.
Paleta: `#5b8def #12a150 #f0872a #e0a000 #7c9cf5 #ef4444 #12854a #94a3b8 #a855f7 #06b6d4`.
Nota: no excluye negocios archivados.

### 5.5 Importar CSV — `crm_import.php`

- `GET ?template=1` → `plantilla-contactos.csv` (BOM) con cabecera `Nombre, Empresa, Sector, Email,
  Telefono, WhatsApp, Origen, Servicios, Valor, Fase, Propietario` y una fila de ejemplo
  (`Ejemplo SL, …, Web;SEO, 2500, lead_nuevo`).
- `POST` multipart `csv`: detecta separador (`;` si la primera línea tiene `;` y no `,`), quita BOM.
  **No hay UI de mapeo**: las columnas se reconocen por alias de cabecera (minúsculas):
  nombre {nombre, nombre completo, contacto, name}* · empresa {empresa, company, negocio} ·
  sector {sector, industria} · email {email, correo, e-mail, mail} · telefono {telefono, teléfono, tel,
  phone, móvil, movil} · whatsapp {whatsapp, wsp, wa} · origen_lead {origen, origen lead, origen_lead,
  fuente, source} · servicios {servicios, servicio, services} (separados por `;`, `,` o `|`) · valor
  {valor, importe, value, presupuesto} · fase {fase, embudo, estado, etapa, stage} (por slug o nombre
  de fase; si no, `lead_nuevo`) · propietario {propietario, owner, responsable, asignado} (por username).
- Error: «El CSV debe tener al menos una columna «Nombre».» Filas sin nombre se omiten.
- Cada fila: INSERT en contacts + actividad `creado` «Importado desde CSV». **Sin deduplicación,
  sin transacción, sin límite de tamaño.**
- Resultado: «✓ Importación completada: N contacto(s) creados, M fila(s) omitidas (sin nombre).» con
  enlaces «→ Ver contactos» e «Importar otro archivo».
- UI: título «Importar contactos», «Sube un CSV y se crean los contactos en el CRM.», zona de
  soltar/clic «Haz clic para elegir tu archivo CSV» / «o arrástralo aquí», botón «Importar contactos»
  (deshabilitado sin archivo), «Descargar plantilla CSV», texto de columnas reconocidas.

### 5.6 Listas — `listas.php`

**URL**: `?id=` (lista activa en pantalla; por defecto la más reciente), `?new=1` (abre el modal),
`?export=ID` (CSV `lista-<nombre>.csv`, mismas columnas que el export de contactos).

- Miembros: estática → `list_members`; activa → contactos que cumplen `condiciones` (mismo motor que
  los filtros de Contactos: q (nombre/empresa/email), sector, origen, fase, servicio, prop, vmin, vmax,
  quick `sin_contactar|act30`) **UNION** los forzados en `list_members`.
- Vista: «Listas» + «Nueva lista»; cabecera de la lista con nombre, «N contactos», «Añadir contacto»,
  «Exportar CSV», «Congelar» (solo activas; confirm «¿Congelar esta lista? Se guardarán los contactos
  actuales y dejará de actualizarse.»), eliminar (confirm «¿Eliminar la lista?»); línea «Condiciones:
  Sector: X · Embudo: Y · ≥ N€ …»; tabla Nombre (avatar), Empresa, Sector, Email, Teléfono, Embudo
  (píldora), y botón quitar solo en miembros de `list_members` (confirm «¿Quitar este contacto de la
  lista?»). Vacíos: «Aún no hay listas. Crea la primera con «Nueva lista».» + explicación activa/estática;
  «Ningún contacto coincide.»
- Modal «Nueva lista»: Nombre* («Ej: Restaurantes sin contactar»), Descripción, Tipo (radio «Activa
  (dinámica)», «Estática (congelada)», «Selección manual»); bloque «Condiciones» (Sector, Origen, Embudo,
  Servicio, Propietario, «Estado contacto» {Sin contactar, Sin actividad +30 días}, Valor mín/máx,
  «Búsqueda de texto»); en manual, buscador + checkboxes de todos los contactos con «Todos»/«Ninguno» y
  «N seleccionados» (validación «Elige al menos un contacto para la lista»).
- Modal «Añadir contacto a la lista»: «Entra en «X» aunque no cumpla las condiciones. Quedará fijo hasta
  que lo quites.», buscador y clic en contacto.
- POST: `new_list` (activa guarda condiciones; estática ejecuta el filtro y congela; manual guarda ids y
  congela; redirige a `?id=`), `freeze` (materializa y pasa a estática), `del_list` (borra lista y
  miembros, sin papelera), `rename_list`, `add_member`, `del_member`.

### 5.7 Reporting / Seguimientos — `automatizaciones.php` (+ `crm_followup_email.php`, `cron.php`)

**Propósito**: bandeja diaria de seguimientos generados por reglas + estado del cron.

**Motor (`lib/crm_followup.php`)**
- `crm_fu_generate()` (idempotente; solo crea si no hay una tarea **pendiente** con el mismo
  contact+deal(<=>)+canal+ciclo):
  - A) contactos `fase='lead_nuevo'` y `fecha_ultimo_contacto IS NULL` → `llamar` hoy, «Primer contacto
    con el lead nuevo», secuencia `lead_nuevo`.
  - B) negocios en `fase='propuesta'` → secuencia desde `fecha_entrada_fase`: +2 días `llamar` «Llamar para
    confirmar recepción de la propuesta», +5 `whatsapp` «WhatsApp de seguimiento de la propuesta», +9
    `email` «Email de último intento / cierre».
  - C) negocios `perdido` con `fecha_reactivacion ≤ hoy` → `llamar` hoy «Reactivar: revisar si es buen
    momento ahora», secuencia `reactivacion`.
- `crm_fu_today()` → pendientes con `fecha_prevista ≤ hoy` agrupadas por canal (con datos del contacto).
- `crm_fu_digest_html()` → HTML con estilos en línea: «{Día} · dd/mm/aaaa», «Seguimientos de hoy»,
  «N acción(es) por hacer.» / «Nada pendiente por ahora.», secciones por canal (colores llamar #3b82f6,
  whatsapp #1a9d5b, email #e0872a, reunion #d1a000) con nombre + dato de contacto según canal, empresa ·
  sector, descripción; pie «{Agencia} · CRM · resumen automático diario».
- `crm_fu_send_daily($force)`: no en fin de semana ni si ya hay `email_log` hoy (salvo force); genera;
  **no envía email**: por cada admin con `role='owner'` crea notificación «Resumen de seguimientos de
  hoy» / «N seguimiento(s) que tocan hoy: llamadas, WhatsApp y emails del CRM.» → `automatizaciones.php`
  (ref `digest:{fecha}:{id}`); inserta `email_log`. Devuelve `{sent, acciones, reason}` con reason
  `fin_de_semana|ya_enviado|ok|sin_dest|nada_hoy|error`.

**Cron (`cron.php`, cada 15 min)**: tareas con interruptor `auto_<clave>` en Ajustes:
`followups` (genera, «N acción(es) para hoy»), `daily_digest` (`crm_fu_send_daily(false)`),
`lead_reminder` (`notif_sync_leads`), además de las no-CRM.

**Pantalla** (título de pestaña «CRM · Reporting»):
- Hero «Reporting» / «Los seguimientos del CRM se preparan solos desde leads nuevos, propuestas enviadas
  y negocios a reactivar.»; botones «Ver email diario» (`crm_followup_email.php?preview=1`, nueva
  pestaña), y para editores «Seguimiento manual», «Generar» (POST `regen`), «Ejecutar resumen» (POST
  `run_digest`, force).
- Chips: «N pendiente(s) hoy», «N hecho(s) hoy», fecha, «Último resumen: dd/mm HH:MM · N acciones».
- Sin pendientes: «✓ No hay seguimientos pendientes hoy» + «Las tareas se generan solas desde leads
  nuevos, propuestas enviadas y negocios a reactivar.»
- Columnas por canal (la de REUNIONES se oculta si está vacía): punto + título + contador; tarjeta:
  nombre, empresa + «vencía dd/mm» en rojo si atrasada, descripción; botones (editores) «✓ Hecho»,
  «+1 día», «+1 sem», «Omitir» (la tarjeta se desvanece y se quita sin recargar). Vacío «Nada por aquí hoy.»
- «Próximos seguimientos» (40 pendientes futuras: punto de canal, nombre, canal, dd/mm). Vacío «Nada
  programado más adelante.»
- «Resumen diario por email»: texto explicativo (L–V, agrupado por canal; lo lanza el cron).
- Tarjeta «Ejecución automática (cron)»: píldora «Funcionando» (última ejecución < 25 h) / «Parado» /
  «Sin configurar»; «Última ejecución hace X · fecha»; desplegable «Ver detalles» con «Ejecutar el cron
  ahora» (enlace a la URL con clave), tabla por tarea (nombre legible, detalle, hace cuánto, punto
  verde/rojo), línea crontab `*/15 * * * * php <ruta>` y URL con botones «Copiar», nota sobre Ajustes;
  dueño: «Generar una clave nueva» (confirm «¿Generar una clave nueva?» / «La línea del cron dejará de
  funcionar hasta que vuelvas a copiarla.»).
- Modal «Seguimiento manual»: Contacto* (select), Canal (4), Descripción* («Qué hay que hacer»), Fecha
  (datepicker, def. hoy); «Crear».

**POST**:
| action | Parámetros | Efecto |
|---|---|---|
| `done` (JSON) | `id` | `estado='hecha'`; actividad tipo=`canal` «Seguimiento hecho: desc»; `fecha_ultimo_contacto=hoy`. |
| `snooze` (JSON) | `id, dias` | `fecha_prevista = hoy + dias`. |
| `skip` (JSON) | `id` | `estado='omitida'`. |
| `add` (redirect) | `contact_id*, canal, descripcion*, fecha` | `crm_fu_add(..., 'manual', ciclo 99)` (dedupe: no deja dos manuales pendientes del mismo canal para el mismo contacto). |
| `regen` | — | `crm_fu_generate()`. |
| `run_digest` | — | `crm_fu_send_daily(true)` → `?ran=1`. |
| `cron_key_regen` (dueño) | — | `cron_key(true)` → `?nk=1`. |

**`crm_followup_email.php`**: `?preview=1` o sin parámetros (sesión admin): ejecuta
`crm_fu_generate()` (escribe en un GET) y muestra el HTML del digest dentro de una página «Resumen diario
· {Agencia} CRM» con barra «← Reporting» / «Vista previa del email diario». `?send=1&key=` (sin sesión):
exige `settings.crm_digest_key` no vacía y coincidente; si no, 403 «forbidden»; responde JSON del envío.

### 5.8 `automations.php` y «Reglas automáticas»
`automations.php` solo redirige a `settings.php?tab=reglas`. Allí: interruptores por tarea del cron
(POST `section=auto_toggle&key=&on=` → JSON; 403 «No tienes permiso para cambiar las automatizaciones.»),
textos: «Recordar leads» – «Crea una notificación cuando llega la fecha de volver a contactar un lead
del CRM.»; «Seguimientos del CRM» – «Genera las llamadas, emails y WhatsApps de seguimiento que tocan cada
día.»; «Resumen diario» – «Prepara el resumen de seguimientos de la mañana, de lunes a viernes.». Botón
«Ejecutar ahora» (POST `section=reglas`) ejecuta `notif_sync_leads`, `notif_sync_invoices` y el aviso de
informe mensual → «Reglas ejecutadas. Revisa tus notificaciones.». (Esta pantalla pertenece a Ajustes;
se cita por el modelo de automatizaciones.)

**Modelo de automatizaciones**: no hay tabla de reglas configurables. Son reglas fijas en código
(A/B/C de seguimientos, aviso de leads, digest), encendidas/apagadas con `settings.auto_<clave>`
(`auto_on()`, por defecto encendidas) y ejecutadas por `cron.php` (registro en `cron_log`, vistas con
`cron_ultima()`/`cron_por_tarea()`) y, para `lead_reminder`, también al navegar.

### 5.9 Agendar reunión (compartido) — `erpAgendar()` + `agendar.php`
- Modal «Agendar reunión» / «Se crea en tu Google Calendar», modos «La agendo yo» / «Que elija el
  cliente». Modo yo: Título (def. «Reunión con {nombre}»), Fecha (def. mañana), Hora (def. 10:00),
  «Invitar (correos, opcional)» (def. email del contacto), «Recordatorio» (Predeterminado de Google,
  10/30 min, 1 h, 2 h, 1 día, Sin recordatorio; def. 30), «Añadir videollamada de Google Meet» (on),
  «Avisar a los invitados por correo» (on), «Tomar notas con Gemini» (solo con Meet). Modo cliente:
  enlace de reservas del usuario con botones email (mailto) y WhatsApp (`wa.me`, antepone 34 si <11
  dígitos) con el texto «Hola {nombre}, te paso mi enlace para agendar nuestra reunión cuando mejor te
  venga: {link}».
- Validaciones cliente: «Escribe un título», «Elige una fecha».
- `POST agendar.php` (`require_can_edit`, permiso de página `ver.agenda`): `titulo, fecha (dd/mm/aaaa o
  ISO), hora, invitados, meet, gemini, notificar, recordar, contact_id`. Error «Falta el título o la
  fecha.». Si `contact_id`: inserta `crm_meetings` (agendada; **no guarda el título**), actividad
  `reunion` «Reunión agendada para dd/mm/aaaa hora», último contacto = hoy. Si Google conectado: crea el
  evento con `extendedProperties.private.erp_meeting = mid` (es lo que luego permite encontrar las notas
  de Gemini). Respuesta `{ok:1, mid, msg}` con msg «Reunión agendada.» / «Reunión guardada, pero Google
  dio un aviso: …» / «Reunión guardada. (Conecta Google Calendar en Integraciones para crear el
  evento.)». El front: toast «Reunión agendada ✓» y recarga la ficha.

---

## 6. Puentes (`lib/puentes.php`)

### 6.1 `pu_lead_a_cliente($contactId, $dealId=0)` — lead → cliente
1. Contacto inexistente → «Contacto no válido.» / «Ese contacto ya no existe.»
2. Si `contacts.client_id` apunta a un cliente existente → `{ok:true, id, ya:true, msg:'Este contacto
   ya era el cliente «X».'}` (no duplica).
3. Datos: nombre cliente = `empresa` o `nombre`; razón social = `billing_data.razon_social` o nombre;
   `saludo` = primera palabra del nombre de la persona; iniciales = 2 primeras letras (o «CL»);
   `username` = nombre sin acentos ni símbolos, minúsculas, ≤40, único (`x`, `x2`, `x3`…, «cliente» si
   vacío); contraseña = 6 letras sin l/i/o + 2 dígitos (se guarda `password_hash`).
4. INSERT `clients` (`username, password_hash, name, iniciales, saludo, conversiones=1, actual='',
   contact_id, tipo_id=NULL, partner_id=NULL, fact_nombre, fact_nif=cif, fact_dir=dirección unida
   «dirección, CP ciudad, provincia, país», fact_email=email_facturacion|email, fact_tel=telefono, activo=1,
   servicios_json=servicio_json`).
5. INSERT 4 `task_lists`: «TAREAS» (tareas, 0), «ESTRATEGIA» (1), «TAREA CLIENTE» (2), «INFORMES
   CLIENTE» (`tipo='informe'`, 3), `es_cliente=0`.
6. `contacts.client_id = nuevo`; `deals.client_id = nuevo` para **todos** los negocios del contacto.
7. Todo en una transacción (savepoints en el backend nuevo); error → «No se ha podido crear el cliente: …».
8. Actividad `cliente` «Convertido en cliente: X». Devuelve `{ok, id, user, pass, ya:false, msg:'Cliente «X» creado.'}`.
No cambia la fase del contacto/negocio ni envía nada al cliente.

### 6.2 `pu_negocio_a_factura($dealId)` — negocio → factura borrador
1. Si `deals.invoice_id` existe → `{ok, id, ya:true, msg:'Este negocio ya tenía la factura N.'}`.
2. Cliente = `deals.client_id` o `contacts.client_id` (puede ser NULL).
3. Datos fiscales por prioridad: ficha cliente → `billing_data` → contacto.
4. Emisor = `settings.emisor_por_defecto` validado con `fin_emisor_ok()`; datos con `fin_emisor_data()`;
   serie `settings.serie_<emisor>`; número `fin_next_numero(serie, emisor, hoy)`.
5. INSERT `invoices` (`estado='borrador'`, fecha hoy, `cond_pago`=venc del emisor, IVA/IRPF del emisor,
   `emisor_json`) + 1 línea `invoice_items` (concepto = nombre del negocio «· servicio» si no lo contiene,
   o «Servicios»; cantidad 1; precio = valor).
6. `deals.invoice_id`; actividad `factura` «Factura N generada desde el negocio».
7. `{ok, id, numero, ya:false, msg:'Factura N creada en borrador.'}`.

### 6.3 `pu_horas_a_gasto` — no es CRM (Horas → Contabilidad); se documenta en Finanzas.

### 6.4 Otros consumidores del CRM
- `client.php`: muestra «de qué lead salió» (contacto por `clients.contact_id` o `contacts.client_id`).
- Portal (`index.php`): reuniones del cliente desde `crm_meetings` vía `clients.contact_id`.
- `reuniones.php` y `dashboard.php`: emparejan eventos de Google con contactos por email y crean
  `crm_meetings` (con `titulo`).

---

## 7. Recomendaciones

### (a) Endpoints de API propuestos (prefijo `/api/v1`, JSON, CSRF en escrituras, convenciones de API.md)

**Catálogos y configuración**
| Método | Ruta | Propósito |
|---|---|---|
| GET | `/crm/catalogos` | `{fases[], origenes[], servicios[], sectores[], tipos_comentario[], motivos_perdida[], canales[], etiquetas[]}` |
| GET | `/crm/fases` | Fases del embudo ordenadas |
| POST | `/crm/fases` | Añadir fase (`nombre, probabilidad, color`) — dueño |
| PATCH | `/crm/fases/{id}` | Renombrar/color/probabilidad — dueño |
| PUT | `/crm/fases/orden` | `{ids:[...]}` reordenar — dueño |
| DELETE | `/crm/fases/{id}` | Solo abiertas; mueve negocios; 409 si es la única abierta o estructural |
| GET/POST | `/crm/etiquetas` | Listar / crear (`nombre, color`; 409 si existe) |
| PATCH/DELETE | `/crm/etiquetas/{id}` | Editar / borrar (quita de contactos y negocios) |
| GET/PUT | `/crm/sectores` | Lista editable (`settings.crm_sectors`; hoy sin UI) |

**Contactos**
| Método | Ruta | Propósito |
|---|---|---|
| GET | `/crm/contactos` | Lista con filtros `q, sector, origen, fase, servicio, prop, tag, vmin, vmax, fdesde, fhasta, quick, sort, dir, limit, offset` → `{items: ContactoFila[], total, total_sin_filtros, negocios_coincidentes[]}` (paginar; hoy no hay) |
| POST | `/crm/contactos` | Crear (`nombre*` + campos) → 201 |
| GET | `/crm/contactos/{id}` | Ficha completa: contacto + `facturacion`, `etiquetas`, `comentarios`, `propuestas`, `adjuntos`, `listas`, `reuniones`, `actividad` (60), `cliente` {id,name} |
| PATCH | `/crm/contactos/{id}` | Subconjunto de campos editables (incl. `fase`, `servicios: string[]`) |
| DELETE | `/crm/contactos/{id}` | A la papelera con **todas** sus hijas → `{papelera_id}` |
| POST | `/crm/contactos/lote` | `{ids[], op: asignar|etiquetar|a_lista|nueva_lista|borrar, ...}` (borrar → papelera) |
| GET | `/crm/contactos/export.csv` | `?ids=` o mismos filtros |
| POST | `/crm/contactos/importar` | multipart CSV; opcional `mapeo` y `dry_run=1` → `{insertados, omitidos, errores[], duplicados[]}` |
| GET | `/crm/contactos/plantilla.csv` | Plantilla |
| PUT | `/crm/contactos/{id}/facturacion` | Datos de facturación (objeto completo o parcial) |
| PUT/DELETE | `/crm/contactos/{id}/etiquetas/{tagId}` | Poner/quitar etiqueta |
| POST | `/crm/contactos/{id}/comentarios` | `{tipo, contenido}` → 201 (+ notificar menciones) |
| DELETE | `/crm/comentarios/{id}` | Autor o dueño |
| POST | `/crm/contactos/{id}/actividad` | Registrar interacción (`tipo, descripcion`) — botones Email/Llamar |
| POST | `/crm/contactos/{id}/propuestas` | `{nombre, importe, estado, fecha_envio, url_archivo}` |
| PATCH/DELETE | `/crm/propuestas/{id}` | Editar estado/url (hoy imposible) / borrar |
| POST | `/crm/contactos/{id}/adjuntos` | multipart (lista blanca, optimizar imágenes) |
| DELETE | `/crm/adjuntos/{id}` | Borra fichero y fila |
| GET | `/crm/adjuntos/{id}/descarga` | Servir el fichero con control de permiso |
| POST | `/crm/contactos/{id}/convertir` | Lead → cliente → `{cliente_id, ya, usuario?, password?}` (password solo una vez) |
| POST | `/crm/contactos/{id}/reuniones` | Agendar (mismo cuerpo que `agendar.php`; crea evento Google si conectado) |
| PATCH | `/crm/reuniones/{id}` | Resultado `{estado, notas, mover_fase?}` |
| POST | `/crm/reuniones/{id}/notas-gemini` | Traer notas → `{docs[], descripcion}` |

**Negocios**
| Método | Ruta | Propósito |
|---|---|---|
| GET | `/crm/negocios` | `?archivados=0|1` → `{items: Negocio[], metricas:{abiertos, valor_pipeline, ganado_mes, n_ganado_mes, conversion, ticket_medio}}` |
| POST | `/crm/negocios` | `{contact_id*, nombre?, valor?, fecha_cierre_prevista?}` |
| GET | `/crm/negocios/{id}` | Detalle |
| PATCH | `/crm/negocios/{id}` | `nombre, valor, servicio, fecha_cierre_prevista, propietario_id, archivado` |
| POST | `/crm/negocios/{id}/mover` | `{fase}` (si es perdida exige motivo → 422) |
| POST | `/crm/negocios/{id}/perder` | `{motivo*, comentario}` |
| DELETE | `/crm/negocios/{id}` | Papelera → `{papelera_id}` |
| PUT/DELETE | `/crm/negocios/{id}/etiquetas/{tagId}` | Etiquetas |
| POST | `/crm/negocios/{id}/convertir` | Contacto del negocio → cliente |
| POST | `/crm/negocios/{id}/factura` | Factura borrador → `{factura_id, numero, ya}` |

**Listas, vistas, dashboard, seguimientos**
| Método | Ruta | Propósito |
|---|---|---|
| GET/POST | `/crm/listas` | Listar (para sidebar, con orden) / crear (`tipo: activa|estatica|manual`, `condiciones`, `ids`) |
| GET | `/crm/listas/{id}` | Lista + miembros (`forzado` por miembro) |
| PATCH/DELETE | `/crm/listas/{id}` | Renombrar / borrar |
| POST | `/crm/listas/{id}/congelar` | Activa → estática |
| PUT/DELETE | `/crm/listas/{id}/miembros/{contactId}` | Añadir/quitar a mano |
| PUT | `/crm/listas/orden` | Orden del sidebar |
| GET | `/crm/listas/{id}/export.csv` | CSV |
| GET/POST/DELETE | `/crm/vistas[/{id}]` | Vistas guardadas (`filtros` como objeto JSON, no query string) |
| GET | `/crm/dashboard` | `?desde&hasta` → `{kpis, series:{...13}}` |
| GET | `/crm/seguimientos` | `?ambito=hoy|proximos` → agrupados por canal + `hechos_hoy`, `ultimo_resumen` |
| POST | `/crm/seguimientos` | Manual `{contact_id, canal, descripcion, fecha}` |
| POST | `/crm/seguimientos/{id}/hecho` · `/posponer` (`dias`) · `/omitir` | Acciones |
| POST | `/crm/seguimientos/generar` | Ejecutar reglas |
| POST | `/crm/resumen-diario/ejecutar` | Digest forzado |
| GET | `/crm/resumen-diario/preview` | HTML del digest (solo lectura, sin generar) |
| GET | `/crm/cron` | Estado del cron (`ultima`, `por_tarea`, `linea`, `url` solo dueño) |

Formas sugeridas:
```
ContactoFila = {id, nombre, empresa, sector, email, telefono, whatsapp, origen_lead, servicios: string[],
  valor: number|null, fase, ultima_actualizacion, fecha_ultimo_contacto, proxima_accion, fecha_prox,
  accion_vencida: boolean, propietario_id, fecha_creacion, client_id, etiquetas: {id,nombre,color}[]}
Negocio = {id, contact_id, contacto:{nombre, empresa, sector}, nombre, valor, servicio, fase, probabilidad,
  fecha_cierre_prevista, fecha_entrada_fase, dias_en_fase, fecha_cierre_real, motivo_perdida,
  motivo_perdida_txt, fecha_reactivacion, propietario_id, archivado, client_id, invoice_id, etiquetas[]}
Seguimiento = {id, contact_id, deal_id, canal, descripcion, fecha_prevista, estado, secuencia_id,
  contacto:{nombre, empresa, sector, telefono, whatsapp, email}, vencida: boolean}
```

### (b) Pantallas y componentes React

- Rutas: `/crm` (Contactos), `/crm/contactos/:id` (ficha como modal/drawer sobre la tabla, con
  deep-link equivalente a `?open=`), `/crm/negocio` (+ `?archivados=1`, `?open=`), `/crm/dashboard`,
  `/crm/reporting`, `/crm/importar`, `/crm/listas/:id?`. Sidebar CRM con secciones y listas reordenables.
- Contactos: `ContactosPage`, `ContactosToolbar` (buscador, `FiltrosButton`, `VistasMenu`,
  `EtiquetasModal`, Importar, Nuevo), `QuickFilters`, `FiltrosPanel`, `FiltroChips`,
  `NegociosCoincidentes`, `ContactosTable` (columnas fijas, cabecera fija, ordenación, virtualización
  recomendable) con celdas `InlineText`, `InlineSelect`, `InlineDate`, `InlineMoney`, `ServiciosCell`
  (popover multi), `FasePill` (popover), `ProximaAccionCell`, `RowMenu`/`ContextMenu`, `BulkBar` +
  `BulkPopover`, `NuevoContactoModal`. Mutaciones optimistas con rollback (TanStack Query) y toasts.
  Atajos `/` y `n`.
- Ficha: `ContactoDrawer` con `ContactoHeader` (nombre inline, navegación ‹ › por la lista filtrada,
  teclado), `ContactoAcciones`, `ComentariosPanel` (selector de tipo, editor con autocompletado de
  `@menciones`, lista), `EtiquetasChips`, `DatosContactoForm`, `DatosComercialesForm`,
  `FacturacionForm`, `PropuestasList` + `PropuestaModal` (nombre, importe, estado, fecha, url),
  `AdjuntosList` (subida drag&drop), `ListasChips`, `ReunionesList` + `ResultadoReunionModal` +
  `NotasGeminiButton`, `ActividadTimeline`, `ConvertirClienteDialog` + `CredencialesUnaVezDialog`.
- Negocio: `NegocioPage`, `MetricasNegocio`, `KanbanBoard` (dnd-kit: tarjetas entre columnas, columnas
  reordenables solo dueño), `KanbanColumn` (contador, total), `DealCard` (aviso de estancamiento),
  `DealDetailModal`, `NuevoNegocioModal` (combobox de contacto con búsqueda), `MotivoPerdidaModal`,
  `FasesEditorModal`, `DealContextMenu`, toggle Activos/Archivados (con «Desarchivar», que hoy falta).
- Dashboard: `RangoFechas` (atajos), `KpiGrid`, 13 `ChartCard` (librería de gráficas a elegir; mismos tipos).
- Importar: `CsvImportWizard` (subir → **vista previa y mapeo de columnas** con autodetección por alias
  → validación/duplicados → importar → resumen). El legado no tiene mapeo; es mejora.
- Listas: `ListaPage`, `NuevaListaModal` (activa/estática/manual, `CondicionesForm` reutilizando los
  filtros de Contactos, `ContactPicker`), `AnadirMiembroModal`, export.
- Reporting: `SeguimientosHoy` (columnas por canal, acciones hecho/+1 día/+1 sem/omitir con salida
  animada), `ProximosSeguimientos`, `SeguimientoManualModal`, `ResumenDiarioCard` (preview),
  `CronStatusCard` (copiar línea, regenerar clave).
- Compartidos: `AgendarReunionModal` (modos yo/cliente; se reutiliza en Clientes y Reuniones),
  `ConfirmDialog`, `PromptDialog`, `DatePicker` dd/mm/aa ↔ ISO, `MoneyInput` con parseo tipo `num_es`,
  `Avatar` (iniciales + color por nombre), `TagChip`.

### (c) Riesgos y ambigüedades

1. **Permisos mal cableados**: `perm_de_accion` usa `del`/`bulk_del`/`ganar`, que no existen; borrar
   contactos (uno o en lote) y convertir no están protegidos por `crm.borrar`/`crm.convertir`;
   `crm.crear`/`crm.editar` no se aplican. `listas.php` exige `ver.tareas` en vez de `ver.crm`. Decidir
   la matriz real (§2.3) antes de construir.
2. **Sin alcance por propietario** en el CRM legado. Definir si los roles sin `alcance.todos` ven solo
   sus contactos (y qué pasa con los sin propietario, negocios de contactos ajenos, listas y dashboard).
3. **POST sin permiso devuelve 200 con HTML**: el front legado dice «Guardado» aunque no se guarde. En la
   API: 403 JSON siempre. El perfil legado muestra campos editables a quien no puede editar.
4. **Borrados que dejan huérfanos**: el borrado en lote es `DELETE` directo (sin papelera ni hijas);
   el borrado individual guarda solo tags/actividades/comentarios y deja huérfanos `deals`,
   `billing_data`, `proposals`, `attachments` (y ficheros), `list_members`, `follow_up_tasks`,
   `crm_meetings`, y `clients.contact_id` colgando. Los negocios huérfanos desaparecen del tablero (JOIN).
   Definir cascada/papelera completa y qué hacer si el contacto ya es cliente.
5. **Doble fase contacto/negocio**: `contacts.fase` es editable a mano pero `crm_sync_fase_contacto`
   la sobrescribe al tocar cualquier negocio; además el contacto tiene su propio `valor`. Decidir la
   fuente de verdad.
6. **Slugs cableados**: `lead_nuevo`, `propuesta`, `ganado`, `perdido`. `stage_del` permite borrar
   `lead_nuevo` y `propuesta` (son `abierta`), lo que rompe el alta (fase por defecto inexistente) y la
   regla B de seguimientos. `stage_reorder` permite poner fases de cierre antes que las abiertas.
   `dias_alerta_estancamiento` existe pero el aviso usa 14/30 fijos.
7. **Email**: el CRM **no envía ningún correo**. «Email» abre Gmail web; el «resumen diario por email» es
   una notificación interna a los admins con `role='owner'` literal (no por permiso) y `email_log` registra
   ejecuciones, no envíos. `crm_followup_email.php?send=1` depende de `crm_digest_key`, que no se puede
   fijar desde ninguna pantalla. El backend nuevo ya tiene `Correo\Fabrica`: decidir si el digest pasa a
   enviarse por correo de verdad (necesita email en `admins`) y a quién.
8. **Gemini / Google**: `meeting_notes` usa el token OAuth de Google **del usuario que pulsa** y busca el
   evento por `privateExtendedProperty erp_meeting=<id>`; solo funciona si la reunión se creó con
   `agendar.php` estando ese mismo usuario conectado. Los enlaces a Docs requieren acceso Drive del que
   mira. La descripción del evento se concatena a `notas` sin límite. Dependencia externa: definir
   timeouts, errores y si se mueve a un job en segundo plano. Los mensajes de error actuales están en §5.1.
9. **Conversión a cliente**: la contraseña del portal (6 letras + 2 dígitos, débil) se devuelve en claro
   una vez; la API no debe registrarla en logs ni cachearla. No comprueba `crm.convertir`/`ver.clientes`,
   no cambia fases, asigna `client_id` a todos los negocios del contacto y crea listas fijas en español.
   Ganar un negocio no dispara la conversión ni la factura (son manuales).
10. **Factura desde negocio**: crea número definitivo de la serie aunque sea borrador (consume
    numeración), puede quedar con `client_id` NULL, IVA/IRPF del emisor por defecto. Coordinar con el
    módulo de Finanzas.
11. **Importación CSV**: el valor se parsea distinto que en el resto (`"12.5"` → 125; no usa `num_es`),
    sin deduplicar por email/teléfono, sin transacción, sin límite de filas/tamaño, sin mapeo manual,
    fase/propietario no reconocidos se descartan en silencio.
12. **Menciones**: se guardan en `comments.menciones` pero no generan notificación. Los autores se
    reconocen por `username` en minúsculas.
13. **Seguimientos**: dedupe por (contacto, negocio, canal, ciclo) pendiente; los manuales usan ciclo 99,
    así que no se pueden tener dos manuales del mismo canal pendientes para un contacto. Si se «Omite» la
    llamada de un lead nuevo, la regla A la vuelve a crear en la siguiente generación (sigue sin
    `fecha_ultimo_contacto`; «Hecho» sí lo pone); lo mismo con la secuencia de propuesta y la
    reactivación mientras el negocio siga en esa fase. «Hechos hoy» cuenta por `fecha_prevista`, no por
    fecha de cierre (no existe esa columna). La actividad de «Seguimiento hecho» usa el slug de canal
    (`llamar`) como tipo, distinto de `llamada`. `crm_followup_email.php` (GET) genera tareas.
14. **Vistas guardadas** almacenan el query string literal del legado; habrá que convertirlas a objeto
    de filtros (o descartarlas) en la migración.
15. **Rendimiento**: Contactos y Negocio cargan todo sin paginar; el dashboard lanza decenas de
    consultas (una por fase y por mes). La API debe paginar y agregar con `GROUP BY`.
16. **Datos libres**: `hora` de reunión es texto libre; `origen_lead` es select en la tabla pero texto
    libre en la ficha; sector es texto con sugerencias; sin validación de email/teléfono/IBAN.
17. **Código muerto/incoherente**: acción `meeting` y `pfMeeting()` sin uso; `unarchive_deal` sin botón;
    `crm_tag_colors()` y `contacts.foto_url` sin uso; propuestas sin forma de editar estado/URL;
    `crm_meetings.titulo` vacío cuando se agenda desde el CRM; tablas `crm_leads`/`crm_types` sin uso.
18. **Adjuntos**: la URL guardada es relativa al legado (`../archivo.php?d=crm&f=`); la API debe servirlos
    con su propio endpoint y migrar las URLs antiguas (`../uploads/crm/`).
19. **Etiqueta duplicada**: el error de UNIQUE se traga sin avisar; en la API responder 409.
20. **Métricas**: las de Negocio y Dashboard no excluyen archivados en ganado/perdido y mezclan
    `fecha_creacion` y `fecha_cierre_real` según la tarjeta; documentar la definición exacta al portar.
