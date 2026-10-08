# Spec 02 · Área TRABAJO (tareas, proyectos, inicio, notificaciones, búsqueda, papelera, actas)

Fuente analizada (solo lectura): `copia-erp/admin/{task,tasks,workspace,listas,proyectos,proyecto,dashboard,notifications,buscar,papelera,actas,duplicate}.php`,
`copia-erp/admin/lib/{rt_editor,proyectos_lib,papelera,publicar_lib,permisos}.php`, `copia-erp/admin/erp_nav.php` (funciones `notif_*`, `task_*`, `chk_*`,
paleta Ctrl+K, sondeo de campana, modal «Nueva tarea»), y lo ya migrado: `backend/API.md`, `backend/src/Modulos/Tareas/*`, `backend/src/Seguridad/Acceso.php`,
`backend/admin/lib/tareas_lib.php`, `backend/database/migrations/*`, `front/src/features/tareas/*`, `front/src/app/*`.

Convención: «LEGADO» = comportamiento del PHP antiguo; «NUEVO» = lo que ya existe en `backend/` + `front/`; **HUECO** = falta en lo nuevo.

---

## 0. Resumen de estado de la migración

| Pantalla legado | Qué es | Estado en lo nuevo |
|---|---|---|
| `workspace.php` | Tablero de tareas (Todas / Mis / De empleado / Cliente→listas) | **Parcial**: listar, cambiar estado/prioridad/responsable/fecha en línea, borrar a papelera + deshacer. Faltan: crear tarea (la API `POST /tareas` existe, no hay UI), multi‑asignados, reordenar tareas/listas, gestión de listas (crear/renombrar/clonar/borrar/listas por defecto), vista de lista tipo *informe* con «Informe del mes», «Publicar al portal», «Ver portal», marcar completada desde menú contextual. |
| `task.php` | Ficha completa de tarea | **Muy parcial**: drawer de solo lectura (`TareaDetalle.tsx`). Falta TODO lo editable (ver §4.1). |
| `tasks.php` | Redirección | Trivial (ya cubierto por la ruta `/tareas`). |
| `listas.php` | **Listas de contactos del CRM** (no listas de tareas) | No migrado. Pertenece al área CRM (ver §4.4). |
| `proyectos.php` / `proyecto.php` | Rentabilidad financiera por proyecto (caja) | No migrado. |
| `dashboard.php` | Inicio | No migrado (`/inicio` → «Próximamente»). |
| `notifications.php` | Bandeja de notificaciones + endpoint `?poll=1` | No migrado (`/notificaciones` → «Próximamente»). `GET /nav` ya da `no_leidas`. |
| `buscar.php` | Búsqueda global + JSON paleta Ctrl+K | No migrado. El Topbar tiene un input y Ctrl+K solo lo enfoca; no busca. |
| `papelera.php` | Papelera global | Solo `POST /papelera/{id}/restaurar` (y únicamente tipo `tarea`). |
| `actas.php` | Actas internas | No migrado (`/actas` → «Próximamente»). |
| `duplicate.php` | Duplica un **cliente** (no tareas) | Fuera de esta área (Clientes). |

---

## 1. Modelo de datos

Tablas creadas por migraciones nuevas (`0001_esquema_base.php`) salvo donde se indica «en caliente» (las crea/altera el PHP legado al abrir la página; **no están en las migraciones nuevas**).

### 1.1 `tasks`
`id`, `client_id INT NOT NULL`, `list_id INT NOT NULL`, `titulo VARCHAR(255)`, `descripcion MEDIUMTEXT` (texto plano derivado; es lo que publica el portal),
`estado VARCHAR(30)` ∈ `pendiente|en proceso|atemporal|completada`, `responsable_id INT NULL` (= primer asignado, «primario»), `prioridad TINYINT` 0..4,
`due_date DATE`, `fecha_inicio DATE`, `visible_cliente TINYINT`, `titulo_cliente VARCHAR(255)`, `explicacion_cliente MEDIUMTEXT`, `mes VARCHAR(40)` (texto libre tipo «Junio 2026»),
`etiquetas VARCHAR(255)` (texto libre, separado por comas), `orden INT`, `created_at`, `updated_at` (ON UPDATE).
- **En caliente (task.php)**: `descripcion_rich MEDIUMTEXT NULL` → descripción enriquecida con marcadores (§5). **HUECO**: no está en migraciones nuevas.
- Etiquetas de estado: `pendiente`=«En espera» `#b0b4bb`, `en proceso`=«En proceso» `#3b82f6`, `atemporal`=«Atemporal» `#e0a000`, `completada`=«Completada» `#12a150`
  (workspace usa otra paleta más oscura: `#64748b/#2563eb/#a16207/#0f7a3d`).
- Prioridades: 0 «Ninguna» `#cfd2d6` (se muestra «Sin prioridad»), 1 «Baja» `#94a3b8`, 2 «Normal» `#3b82f6`, 3 «Alta» `#f59e0b`, 4 «Urgente» `#ef4444`.

### 1.2 `task_lists`
`id`, `client_id`, `nombre VARCHAR(120)`, `es_cliente TINYINT` (si 1, todas sus tareas salen en el portal), `tipo` ∈ `tareas|informe`, `orden`, `created_at`.
Listas por defecto (acción `default_lists`): `TAREAS`(0), `ESTRATEGIA`(1), `TAREA CLIENTE`(2), `INFORMES CLIENTE` tipo `informe` (3). Solo puede haber una de tipo informe por cliente (la UI oculta la opción si ya existe).

### 1.3 `task_assignees` (puente multi‑asignado)
`task_id, admin_id, orden` PK(task_id,admin_id). Fuente de verdad de asignados; `tasks.responsable_id` se sincroniza con el primero. Lectura con *fallback*: si no hay filas, se usa `responsable_id`.
Funciones: `task_asignados($id)`, `task_set_asignados($id,$ids)` (DELETE + INSERT ordenado + UPDATE responsable_id). Ya existen en `backend/admin/lib/tareas_lib.php` (con transacción).

### 1.4 `task_checklist` + `chk_assignees`
`task_checklist`: `id, task_id, texto VARCHAR(500), done TINYINT, responsable_id (primario), orden, created_at`. Orden de lectura: `done DESC, orden, id` (¡las hechas arriba!; en JS, tras marcar, se re‑ordena igual).
`chk_assignees`: `chk_id, admin_id, orden` PK(chk_id,admin_id). Funciones `chk_asignados`, `chk_set_asignados`, `chk_asignados_map($taskId)` — **solo existen en `copia-erp/admin/erp_nav.php`; no en `backend/admin/lib`**.

### 1.5 `task_comments`
`id, task_id, admin_id NULL, cuerpo MEDIUMTEXT` (texto con marcadores §5 + `[[img]]` posicionales), `checklist_json MEDIUMTEXT NULL` (array `[{texto, done:0|1, resp:int|null}]`), `created_at`.
- **En caliente (task.php)**: `reply_to INT NULL` (respuesta tipo WhatsApp). **HUECO**: no está en migraciones nuevas.
- No hay `updated_at` ni marca de «editado».

### 1.6 `task_attachments`
`id, task_id, comment_id NULL` (NULL = adjunto de la tarea; con valor = adjunto del comentario), `filename` (`{taskId}_{12hex}.{ext}`), `orig_name` (≤240), `mime`, `admin_id`, `created_at`.
Carpeta física `uploads/tasks/`. Servidos por `archivo.php?d=tasks&f=<fn>` (exige sesión + `ver.tareas` + alcance de la tarea en `backend/archivo.php`).
Las imágenes incrustadas en la **descripción** se suben a la misma carpeta pero **no** generan fila en `task_attachments` (solo viven como marcador `[[img:FN]]`/`[[file:FN|orig]]`).

### 1.7 `task_comment_reactions`
`id, comment_id, admin_id, emoji VARCHAR(16), created_at`, UNIQUE(comment_id,admin_id,emoji). Toggle por persona+emoji.

### 1.8 `time_entries` (en caliente, `ensure_time_schema()` en erp_nav)
`id, admin_id, task_id NULL, client_id NULL, fecha DATE, minutos INT, importe DECIMAL NULL, concepto VARCHAR(255), created_at`. También añade a `admins`: `es_autonomo, tarifa_hora, iva_pct, irpf_pct`.
El campo «Tiempo» de la ficha gestiona solo la línea `concepto = 'Horas de la tarea'` (constante `TIME_CONCEPTO_TAREA`) de cada persona. **HUECO**: tabla no está en migraciones nuevas (comprobar si otra spec de Finanzas la cubre).

### 1.9 `notifications`
`id, admin_id, tipo VARCHAR(20) DEFAULT 'info', titulo VARCHAR(200), cuerpo VARCHAR(400), url VARCHAR(200), ref VARCHAR(140) NULL, tarea VARCHAR(200), actor VARCHAR(120), leido TINYINT, snooze_until DATETIME NULL, borrado TINYINT, bandeja VARCHAR(12) 'principal'|'otras', created_at`, UNIQUE(admin_id, ref). (Ya en migración 0001.)

### 1.10 `trash` (papelera)
`id, tabla VARCHAR(64), ref_id INT, tipo VARCHAR(40), titulo VARCHAR(220), datos MEDIUMTEXT` (JSON `{fila:{…}, hijos:[{tabla,fk,filas:[…]}]}`), `admin_id, autor, created_at`. Retención `PAP_DIAS = 30`.

### 1.11 `projects` (en caliente en proyectos.php)
`id, nombre VARCHAR(160), color VARCHAR(16) DEFAULT '#2f6df6', client_id INT NULL, activo TINYINT DEFAULT 1, created_at`. Además `accounting.project_id INT NULL` (y `invoices.project_id`).
Paleta: `#2f6df6 #12a150 #e0a341 #e05a4f #8b5cf6 #0ea5a5 #eb5a9a #f59e0b #14b8a6 #a855f7`.

### 1.12 `actas` (en caliente en actas.php)
`id, titulo VARCHAR(220) DEFAULT '', contenido MEDIUMTEXT` (marcadores §5), `admin_id NULL, pinned TINYINT DEFAULT 0, created_at, updated_at ON UPDATE`. INDEX(created_at), INDEX(admin_id).

### 1.13 Otras usadas
`clients.tareas_json` / `clients.informes_json` (escritos por `publicar_progreso`), `settings` (`notifmute_<adminId>`, `auto_<clave>`, `nav_order_<adminId>_<bloque>`), `chat_presence`.

---

## 2. Modelo de notificaciones

### 2.1 Escritura
`notif_add($adminId,$tipo,$titulo,$cuerpo,$url='',$ref=null,$tarea='',$actor='',$bandeja='principal')` → `INSERT IGNORE` (dedup por UNIQUE(admin_id,ref); con `ref=NULL` nunca deduplica).
Silenciado: si `notif_cat($tipo)` es `chat` (tipo `chat`) o `avisos` (tipos `bell|file|inbox|info`) y el destinatario tiene esa categoría en `settings.notifmute_<id>` (CSV), no se inserta. Tareas/leads/facturas/tickets **no** se pueden silenciar.

Presentación en la bandeja: columna 1 = `tarea` si existe, si no `titulo`; línea 2 = `actor` (negrita) + (`titulo` si hay tarea, si no `cuerpo`) + « · cuerpo» si hay tarea y cuerpo.

### 2.2 Catálogo de avisos (tipo · disparador · destinatarios · titulo/cuerpo · url · ref · bandeja)

| Función | tipo | Cuándo | A quién | titulo | cuerpo | url | ref | bandeja |
|---|---|---|---|---|---|---|---|---|
| `notif_task_assigned` | tarea | asignar tarea (nuevo asignado) | el nuevo asignado (nunca a uno mismo) | «te ha asignado esta tarea» | nombre cliente | `task.php?id=N` | NULL | principal |
| `notif_task_activity('start')` | tarea | estado pasa a `en proceso` (desde otro) | `notif_duenos($yo)` = admins con rol que tenga `admin.total`, menos el actor | «ha puesto en marcha una tarea» | cliente | `task.php?id=N` | `taskact:start:{tid}:{Y-m-d}:{uid}` | `principal` si el dueño está asignado, si no `otras` |
| `notif_task_activity('date')` | tarea | cambia `fecha_inicio`/`due_date` a un valor no vacío distinto | igual | «le ha puesto fecha a una tarea · fecha límite dd/mm/aaaa» / «… · inicio dd/mm/aaaa» | cliente | idem | `taskact:date:…` | idem |
| `notif_check_assigned` | tarea | asignar punto de checklist (tarea o comentario) | el asignado (no a uno mismo) | «te asignó un punto de la lista de control: {texto≤90}» | cliente | `task.php?id=N#chk` | NULL | principal |
| `notif_check_done` | tarea | marcar/desmarcar punto checklist | asignados de la tarea + responsable_id + responsable del punto, menos el actor | «completó un punto de la lista de control: {texto}» / «desmarcó …» | cliente | `#chk` | `chkdone:{chkId}:{0/1}:{uid}` | principal |
| `notif_comment_scan` (mención) | tarea | comentario nuevo o editado con `@usuario` | cada mencionado existente (case‑insensitive por username), menos actor | «te ha mencionado: {resumen≤160}» | '' | `task.php?id=N#c{cid}` | `cmt:{cid}:{uid}` | principal |
| `notif_comment_scan` (responsable) | tarea | idem | `responsable_id` de la tarea si no fue ya avisado y ≠ actor | «ha comentado en tu tarea: {resumen}» | '' | idem | `cmt:{cid}:{uid}` | principal |
| respuesta (en task.php) | tarea | comentario con `reply_to` | autor del comentario original (≠ actor) | «te ha respondido: {resumen≤140}» | '' | `#c{cid}` | `reply:{cid}:{origAid}` | principal |
| `notif_desc_scan` | tarea | guardar descripción con `@usuario` | mencionados | «te ha mencionado en una tarea» | '' | `task.php?id=N` | `descmention:{tid}:{uid}` (una vez por persona y tarea) | principal |
| `notif_sync_leads` | lead | sync (cada 120 s por sesión desde sidebar si `auto_lead_reminder`; y al abrir notifications.php) | `contacts.propietario_id` o todos | «Toca contactar a {empresa/nombre}» | «{proxima_accion|Seguimiento pendiente} · dd/mm/aaaa» | `crm.php?open=ID` | `cto:{id}:{fecha_prox}` | principal |
| `notif_sync_invoices` | factura | sync (si `auto_invoice_due`); además pasa `enviada`→`vencida` | todos los admins | «Factura vencida {numero}» | «{cliente} · vencía dd/mm/aaaa» | `facturas.php?v=ID` | `inv:{id}` | principal |
| `notif_sync_meeting_requests` | info | sync | dueños | «Nueva solicitud de reunión» | «{cliente} ha pedido una reunión desde su portal» | `reuniones.php` | `meetreq:{id}` | principal |
| `notif_ticket_assigned` | ticket | asignar ticket | asignado | «te ha asignado este ticket» | cliente/«Sin cliente» | `support.php?t=ID` | `tkassign:{id}:{uid}` | principal |
| `notif_invoice_paid` | info | factura cobrada | dueños menos actor | «factura cobrada {numero}» | cliente | `facturas.php?v=ID` | `invpaid:{id}:{fecha_pago}` | principal |
| `notif_client_new` | info | alta de cliente | dueños menos actor | «nuevo cliente de alta» | nombre | `client.php?id=ID` | `clinew:{id}` | principal |
| `notif_sync_birthdays` | info | 1 vez/día | todos menos el cumpleañero | «🎂 hoy cumple años {user}» | «Felicítale cuando puedas» | `perfil.php?id=ID` | `bday:{id}:{Y-m-d}` | principal |
| chat | chat | (chat.php) | — | — | — | — | — | — (pestaña «Chat») |

Notas: las URL guardadas son rutas del PHP legado (`task.php?id=…#c123`). En React habrá que **traducirlas** (p. ej. `/tareas/123?c=…`) o guardar `entidad/ref_id` aparte. Es un riesgo de compatibilidad (§6c).

### 2.3 Lectura, bandejas y estados
Clasificación de cada fila (en este orden): **Borradas** (`borrado=1`) → **Más tarde** (`snooze_until > NOW()`) → **Chat** (`tipo='chat'`) → **Otras** (`bandeja='otras'`) → **Principal** (resto).
Contador de campana (`notif_unread`): `leido=0 AND borrado=0 AND tipo<>'chat' AND (snooze_until IS NULL OR snooze_until<=NOW())` (incluye Principal + Otras). `GET /api/v1/nav` ya devuelve este número como `no_leidas`.
Acciones: leer/no leer, posponer (a mañana 09:00 o N horas), traer ahora, borrar (soft, `borrado=1`), restaurar, purgar (DELETE, solo si `borrado=1`), marcar todas leídas, enviar leídas a papelera, vaciar papelera; todas en individual y en bloque (`*_ids`).

---

## 3. Permisos relevantes (`lib/permisos.php`)

- Página: `workspace.php|tasks.php|task.php|listas.php` → `ver.tareas`; `proyectos.php|proyecto.php` → `ver.proyectos` (hereda de `ver.tareas`); `actas.php` → `ver.actas` (hereda de `general.editar`); `papelera.php` → `ver.ajustes`. Sin permiso de página: `dashboard.php`, `notifications.php`, `buscar.php`.
- Escritura: todo POST de estas páginas exige `can_edit()` = `general.editar`. Por acción (`perm_de_accion`): `task.php: del→tareas.borrar` (acción inexistente en task.php: efecto nulo), `listas.php: del/del_lista→tareas.borrar` (nombres de acción que tampoco coinciden con los reales `del_list`), `papelera.php: restore→papelera.restaurar, purge→papelera.purgar`, `fin-horas.php: *→tareas.horas`.
- **LEGADO no comprueba** `tareas.crear/editar/borrar/horas` en workspace/task (solo `general.editar`). **NUEVO** sí exige `general.editar`+`tareas.{crear|editar|borrar}`. Recomendado: imputar horas desde la ficha → `tareas.horas`; comentar/reaccionar → `general.editar` (+ `ver.tareas` y alcance); restaurar de papelera lo ajeno → `papelera.restaurar`; purgar → `papelera.purgar`.
- Alcance (`alcance.todos`): sin él, solo tareas donde `responsable_id = yo` o existe fila en `task_assignees` (implementado en `Acceso::veTarea/sqlTareas`). task.php lo aplica con `alcance_exigir_tarea($id)`; workspace con `alcance_sql_tareas`. **Dashboard, buscar.php y la vista `cliente` del workspace NO aplican alcance en el legado** (fuga) — el nuevo debe aplicarlo siempre.
- Importes: dashboard muestra «Cobrado este mes» solo si `can_edit() && puede_importes()` (`ver.importes`). proyectos/proyecto muestran importes sin comprobar `ver.importes` (riesgo).

---

## 4. Pantallas

### 4.1 `task.php` — Ficha de tarea (página completa estilo ClickUp)

**Propósito**: ver y editar todo de una tarea: campos, descripción enriquecida, lista de control, adjuntos, tiempo imputado y panel de actividad/comentarios.

**URL**: `task.php?id={id}[&ret={querystring de workspace urlencoded}][#c{commentId}|#chk|#act]`. Sin tarea → redirige `workspace.php?view=all`.
`ret` sirve para el botón «volver» (por defecto `view=cliente&cli={client}&list={list}`).
**Permisos**: `ver.tareas` + alcance de la tarea. Edición: `general.editar` (todos los POST dentro de `can_edit()`); en modo lectura todos los controles quedan `readonly/disabled`, sin cursores de edición.

#### Layout
- Menú lateral recogido (`side-collapse`, se despliega al acercar el cursor).
- **Miga** (`tk-crumb`): [← volver a `backHref`] `Clientes` / `{cliente}` (→client.php) / `{lista}` (→workspace lista).
- **Móvil (≤940px)**: pestañas «Detalles» | «Actividad {n}»; swipe horizontal (>70px y 1,5× vertical) cambia de pestaña. En escritorio, columna izquierda (padding-right 580px) + panel fijo derecho de 540px (fondo `#f6f7f8`).
- **Columna izquierda**:
  1. Título: input grande (27px, 600) placeholder «Nombre de la tarea»; `onchange` → `set_field titulo` (vacío se ignora).
  2. **Rejilla de campos** 2 columnas (label 120px con icono):
     - **Estado**: pastilla rellena del color del estado con texto blanco y MAYÚSCULAS («EN PROCESO ⌄»); «En espera» en gris (`#f1f2f4`/`#6b7079`). Popover con los 4 estados (punto de color + etiqueta).
     - **Asignados**: pila de avatares (iniciales 2 letras, color `avatar_color(username)`) + nombre si es 1 o «N asignados»; vacío: «＋ Asignar» (lectura: «Sin asignar»). Popover multiselección con ✓ (toggle inmediato, guarda en cada clic).
     - **Fechas**: `[📅 Inicio] › [⚑ Límite]` con datepicker propio (formato visible dd/mm/aa, parsea `d/m/aa`, `d-m-aaaa`, `d.m.aa`). Fecha límite coloreada: rojo `#e5484d` si vencida, ámbar `#e0a000` si ≤ hoy+2 días; sin color si completada. Se recalcula al cambiar estado o fecha.
     - **Tiempo**: botón «X,5 h» o «Añadir tiempo» (lectura: «Sin tiempo registrado»). Popover (262px): «Horas en esta tarea»; selector «de quién» (Tú + asignados + quien ya tenga horas, con sus horas al lado); input decimal (coma o punto) + «h» + «Guardar» (Enter guarda); bloque de reparto por persona (avatar, nombre, «X h», «Tú» resaltado) y «Total de la tarea X h»; nota: «Elige arriba **de quién** son las horas. Cada persona tiene las suyas y se facturan a su tarifa en € Finanzas › Horas» (enlace `fin-horas.php?u={id}`).
     - **Prioridad**: punto + etiqueta coloreada, o «Sin prioridad». Popover con 5 opciones.
     - **Etiquetas**: input libre, placeholder «Vaciar», `onchange` guarda.
     - **Mes (informe)**: input libre, placeholder «Vaciar», `onchange` guarda.
  3. Sección **«DESCRIPCIÓN»** (`tk-sec` 11px uppercase): editor contenteditable «lienzo» (placeholder «Añade una descripción… texto, imágenes, archivos y listas de control.»). Barra: [Bloques ▾] [Subir imagen/archivo] [Lista de control] [Emoji] | [B] [I] [U] [S] [Enlace] [Código]. En lectura: render estático o «Sin descripción.».
  4. Barra: «← Volver a la lista» + «Guardado ✓» (verde, aparece 1,3 s tras cada guardado correcto).
  5. Sección **«LISTA DE CONTROL»** con contador `hechos/total`: filas con checkbox, texto (tachado animado si hecho; destello verde al marcar), *face‑pile* de responsables (hasta 3 + «+N»; si no tiene, 2 avatares atenuados + «+») que abre popover multiselección, botón ✕ («¿Borrar elemento?»). Fila de alta: «＋ Añadir elemento…» + botón asignar (multi); Enter o blur crea (blur no crea si se está eligiendo responsable).
  6. Sección **«ADJUNTOS»** con contador: rejilla de miniaturas 92×92 (imágenes, abren lightbox) o chips de archivo (icono + nombre truncado 180px); ✕ «¿Quitar adjunto?». Zona de subida punteada: «Sube imágenes, vídeos o archivos» / «Haz clic aquí o **arrastra archivos a cualquier parte** de la pantalla».
- **Panel derecho «Actividad {n}»**:
  - Primera línea sistema: «• Tarea creada · {fecha relativa}». Vacío: «Sin comentarios todavía.»
  - Cada comentario = burbuja blanca: cabecera (avatar 22px, usuario en negrita — «Sistema» si no hay autor —, fecha relativa), cita si responde a otro (barra izquierda acento, autor + extracto 90c; clic → scroll + destello amarillo), checklist del comentario (si hay; casillas pequeñas, tachado, avatar del responsable), cuerpo renderizado con imágenes intercaladas en las posiciones `[[img]]`, adjuntos restantes debajo (✕ solo para el autor), pie: botón 👍 (icono gris sin reacciones; «👍 N» con reacciones; morado si es mía), chips de otras reacciones «😄 2», espacio, «↩ Responder».
  - Herramientas al pasar el ratón (esquina): [Reaccionar (picker emoji global)] [⋯ Más]. Menú contextual (clic derecho o ⋯): «Responder», «Reaccionar…», «Añadir checklist» / «Editar checklist», y si es mío: «Editar», separador, «Eliminar» (confirm «¿Borrar este comentario?»).
  - **Compositor** (`cbox`): barra «respondiendo a…» (autor + extracto + ✕ «Cancelar respuesta»), filas de checklist en composición (casilla, input «Elemento…», asignar, ✕; Enter añade otra fila), editor (placeholder «Escribe un comentario…»), chips de archivos no‑imagen, barra: [Adjuntar] [Emoji] [@ Mencionar] [Lista de control] [Bloques] | [B][I][U][S][Enlace][Código] … [**Comentar**]. Enter envía, Shift+Enter salto de línea.
- **Overlays globales**: `#dropOverlay` «Suelta para adjuntar» / «En el cuadro de comentario → al comentario · en el resto → a la tarea»; `#actDropHint` «Suelta aquí para adjuntar al comentario»; lightbox con blur (Esc cierra).

#### Acciones POST (todas a `task.php?id={id}`, `x-www-form-urlencoded` salvo subidas)
| action | Parámetros | Validación | Efectos | Respuesta |
|---|---|---|---|---|
| `set_field` | `field` ∈ titulo,descripcion,estado,prioridad,fecha_inicio,due_date,etiquetas,mes; `val` | estado desconocido→`pendiente`; prioridad int; fechas ''→NULL; titulo vacío se ignora | `UPDATE tasks`; `publicar_progreso(cli)`; si estado→`en proceso` desde otro: `notif_task_activity(start)`; si fecha nueva no vacía y distinta: `notif_task_activity(date, "fecha límite dd/mm/aaaa"|"inicio dd/mm/aaaa")` | JSON `{ok:1}` |
| `save_task` | todos los campos de golpe (formulario clásico) | titulo no vacío | UPDATE de 9 campos, publicar, aviso de asignación y de actividad | redirect |
| `set_resp` | `rid` | — | `responsable_id`, `task_set_asignados([rid])`, publicar, aviso si cambia | `{ok:1}` |
| `set_asignados` | `ids[]` | ints únicos >0 | `task_set_asignados`, publicar, `notif_task_assigned` solo a los nuevos | `{ok:1, ids}` |
| `time_set` | `horas` (decimal, coma admitida), `who` (admin id; default yo; si no existe → yo) | — | `DELETE time_entries WHERE task_id AND admin_id=who AND concepto='Horas de la tarea'`; si min>0 `INSERT (who, task, client, hoy, min, NULL, concepto)`. **Nunca toca otras líneas** (las de Finanzas›Horas) | texto `ok` |
| `toggle_check` | `chkid` | debe pertenecer a la tarea | `done = 1-done`; `notif_check_done` | `{ok:1, done}` |
| `check_resp` | `chkid, rid` | — | responsable único del punto + puente; `notif_check_assigned` | `{ok:1}` |
| `check_asig` | `chkid, ids[]` | — | `chk_set_asignados`; avisa solo a los nuevos | `{ok:1, ids}` |
| `add_check` | `texto, rid, rids[]` | texto no vacío | INSERT con `orden = MAX+1`; asignados; avisos a cada asignado | redirect `#chk` |
| `del_check` | `chkid` | — | DELETE (hard; no papelera; no borra `chk_assignees` → huérfanos) | redirect `#chk` |
| `set_desc` | `rich` (≤200 000 chars) | — | `descripcion_rich=rich`, `descripcion=desc_to_plain(rich)`; `notif_desc_scan`; `publicar_progreso` | `{ok:1}` |
| `desc_upload` | multipart `file` | extensión en lista blanca | guarda `uploads/tasks/{id}_{12hex}.{ext}`, `img_optimizar` (reduce fotos); **sin fila en BD** | `{ok, fn, orig, img:bool, url}` o `{ok:0, err:'no-file'|'ext'|'move'}` |
| `add_comment` | multipart: `cuerpo`, `files[]`, `checklist` (JSON), `reply_to` | se crea si hay cuerpo, archivos o checklist | INSERT comment; `store_files` (adjuntos con comment_id; descarta extensiones no permitidas en silencio); `notif_comment_scan`; aviso «te ha respondido»; `notif_check_assigned` por cada `resp` del checklist | redirect `#act` (el JS usa la respuesta HTML para refrescar el feed) |
| `comment_check` | `cid, idx` | — | conmuta `done` del ítem idx del `checklist_json` | `{ok:1}` |
| `comment_setchk` | `cid, checklist` (JSON) | limpia textos vacíos | reemplaza `checklist_json`; avisa solo a responsables nuevos | `{ok:1}` |
| `edit_comment` | `cid, cuerpo` | solo autor (`AND admin_id=yo`), no vacío | UPDATE; re‑escanea menciones (dedup por ref) | redirect |
| `del_comment` | `cid` | solo autor | borra adjuntos del comentario (archivo + fila; **los borra aunque el comentario no sea mío**, la query de adjuntos no filtra autor) y el comentario (hard, sin papelera, reacciones huérfanas) | redirect |
| `react` | `cid, emoji` | emoji no vacío | toggle en `task_comment_reactions` | `{ok:1}` |
| `upload_att` | multipart `files[]` | lista blanca | adjuntos de la tarea | redirect |
| `del_att` | `aid` | debe ser de la tarea | unlink archivo + DELETE fila (hard) | redirect |

Lista blanca de extensiones (`upload_extensiones_ok`): `jpg jpeg png gif webp avif bmp pdf doc docx xls xlsx ppt pptx odt ods txt csv rtf mp4 webm mov m4v mp3 wav m4a zip rar 7z` (SVG excluido a propósito). Imagen = `jpg jpeg png gif webp avif bmp`.

#### Comportamientos JS
- **Autosave descripción**: `input` → debounce 700 ms → `set_desc`; también en `blur`. Checkboxes de la descripción también guardan.
- **Guardado de campos**: si la cuenta es de solo lectura → toast «Tu cuenta es de solo lectura: este cambio no se guarda.»; si la respuesta no es OK → «No se ha podido guardar. Recarga la página.»
- **@menciones** (comentario y descripción): al escribir `@` + texto (regex `@([\p{L}0-9_.\-]*)$` antes del caret) aparece popup junto al cursor, filtrado por subcadena (prefijos primero, luego alfabético), ↑/↓, Enter/Tab inserta, Esc cierra. Inserta *chip* no editable `<span class="mention" data-mention="user">` con mini‑avatar; se serializa como `@username`. Se renderizan en morado `#5b5fc7` con fondo suave; menciones a usuarios inexistentes en estilo «plain».
- **Pegar**: archivos → subida (descripción) o inserción en compositor; HTML → `sanitizePaste` conserva estructura (h1‑h6→h1‑h3, listas, cita, tabla, pre/code, enlaces http(s), b/i/u/s incluidos estilos inline) y descarta scripts, estilos, imágenes HTML; texto → insertText.
- **Drag & drop**: sobre el panel de actividad → al compositor (imágenes inline con preview y ✕; otros como chips); en el resto de la pantalla → sube como adjunto de la tarea (submit del form); dentro del editor de descripción → `desc_upload` e inserta imagen/archivo.
- **Bloques**: menú «＋» con «Texto normal ¶, Título grande H1, Título mediano H2, Título pequeño H3 | Lista con viñetas •, Lista numerada 1., Lista de control ☑ | Cita ❝, Bloque de código {}, Tabla ▦, Divisor —». Tablas: Tab/Shift+Tab entre celdas (Tab en la última crea fila), barra flotante «＋col ＋fila －col －fila». Enter sale del formato en línea; espacio/letra al final de `code` sale del código.
- **Atajos**: Ctrl+B/I/U (execCommand), Enter=enviar comentario, Esc cierra lightbox/menciones.
- **Comentar sin recargar**: `fetch` POST con FormData, se parsea el HTML devuelto y se reemplaza `.act .feed` y el contador; si falla, submit clásico.
- **Polling**: cada 5 s GET de la propia página; si cambia el nº o el último id de comentarios, reemplaza el feed (solo baja el scroll si ya estabas al final). (Muy costoso: renderiza la página entera.)
- **Reacción**: animación «pop» inmediata, POST, luego re‑GET de la página para refrescar el feed.
- **Llegada desde notificación** (`#c{id}`): scroll al comentario, resaltado azul `#e9f2ff` mantenido 2,5 s y retirada suave.
- **Fechas relativas**: «justo ahora», «hace N minuto(s)», «hace N hora(s)» (mismo día), «ayer a las 8:10 pm», «27 de jul. a las 8:10 pm».
- Edición de comentario: sustituye el cuerpo por un textarea con el texto **en bruto** (marcadores) + Guardar/Cancelar (Cancelar recarga).

#### Campos de visibilidad al cliente
`visible_cliente`, `titulo_cliente`, `explicacion_cliente` **no aparecen en la ficha** de task.php. Solo se escriben desde `workspace.php save_task` (el modal no tiene esos inputs, así que quedan a 0/''), «Informe del mes» y clonación de listas. El portal publica: tareas con `visible_cliente=1` o de listas `es_cliente=1`, con título = `titulo_cliente` o `titulo`, texto = `explicacion_cliente`. La nueva API ya acepta los tres campos en POST/PATCH (no hay UI).

#### Diferencias con lo NUEVO (HUECOS)
- `GET /tareas/{id}` devuelve `descripcion` (texto plano), no `descripcion_rich`; ni `reply_to`, reacciones, adjuntos por comentario, `checklist_json` de comentarios, asignados de checklist, tiempo imputado. Drawer de solo lectura.
- `PATCH /tareas/{id}` con `responsable_id` deja un único asignado: **no hay multi‑asignación** ni `set_asignados`.
- **Notificaciones no se crean en el backend nuevo**: `TareasServicio` llama a `notif_task_assigned`/`notif_task_activity` solo `if (function_exists(...))`, y esas funciones viven en `copia-erp/admin/erp_nav.php`, que `backend/` no incluye. Además el nuevo no emite el aviso de cambio de fecha.
- API.md dice que `descripcion` y `cuerpo` son «HTML del editor»: **incorrecto**, son texto con marcadores tipo markdown (§5). El front los pasa por `DOMParser`, lo que deja visibles `**`, `[[img:…]]`, `[[chk:1]]`, etc.

### 4.2 `tasks.php`
Solo redirige: con `?cli=N` → `workspace.php?view=cliente&cli=N`; si no → `workspace.php?view=all`. Permiso `ver.tareas`. Nada que migrar más allá de alias de ruta.

### 4.3 `workspace.php` — diferencias frente al tablero nuevo
Ya cubierto en lo nuevo: vistas `all|mine|emp|cliente`, filtros `fe` (estado) y `fr` (responsable, solo en `all`), ocultar completadas en vistas generales, agrupación por cliente (plegable con memoria local) y por estado en la de cliente, edición en línea de estado/prioridad/responsable/fecha, borrar a papelera con «Deshacer», pestañas de listas del cliente.

**HUECOS respecto al legado**:
1. **Crear tarea**: fila «＋ Añadir tarea…» al final de cada grupo de estado (solo título → `save_task` con estado `pendiente`; si vacío abre el modal) y botón «＋ Añadir tarea» que abre modal: título (obligatorio), descripción, Estado, Asignado (uno), Inicio, Fecha límite, Prioridad, Etiquetas, «Mes (informe)» (placeholder «Ej: Junio»), botones «Cancelar» / «Crear tarea». El modal solo existe en la vista de cliente con lista.
2. **Multi‑asignados** en la fila (`set_asignados`, popover con ✓).
3. **Reordenar tareas** dentro de su estado arrastrando (`reorder_tasks`: `order[]` = ids en orden; escribe `tasks.orden`), **reordenar listas** (`reorder_lists`), **reordenar carpetas de cliente** (`reorder_clients` → `clients.orden`).
4. **Gestión de listas** (menú contextual sobre la pestaña o el sidebar): «Abrir lista», «Renombrar» (input inline), «Clonar (con sus tareas)» (`dup_list`: copia lista «{nombre} (copia)» y sus tareas **sin** comentarios/checklist/adjuntos/asignados), «Borrar lista» (confirm «Se borran también todas sus tareas.»; `del_list`: limpia hijos de las tareas *sin* guardarlos, guarda la lista + tareas en papelera tipo `lista`, borra). «＋ Añadir» → menú «Lista» / «Informe de cliente» (solo si no existe) + nombre. Sin listas: botón «Crear listas por defecto (Tareas · Estrategia · Tarea cliente · Informes)».
5. **Lista tipo informe**: chips de meses (`mes` de las tareas; por defecto el mes actual «Junio 2026»), editor **«Informe de {mes}»** («Lo que el cliente lee en su Portal › Informes», píldora «Publicado/Sin publicar», textarea, «Se publica al guardar · el cliente lo ve al instante», botón «Guardar y publicar» → `save_informe_mes {list_id, mes, texto≤60000}`: crea/actualiza tarea especial con título fijo «Informe del mes», estado `atemporal`, `explicacion_cliente=descripcion=texto`), tabla «Tareas de {mes} que el cliente también ve» y «Añadir tarea del mes». `?informe=1` crea la lista de informes si falta (ya existe `POST /clientes/{id}/informe`).
6. **«Publicar al portal»** (`publicar`, confirm «¿Publicar ahora al Progreso del cliente?», flash «Progreso publicado en el portal del cliente.») y **«Ver portal»** (`../index.php?cli=N`, nueva pestaña).
7. Menú contextual de tarea: «Abrir tarea», «Marcar completada», «Borrar tarea» (confirm «Se borran también sus comentarios y sus horas.» — falso: las horas no se borran).
8. Badge «cliente» en filas con `visible_cliente=1`; etiqueta de lista en vistas generales.
9. Selector «Ir a un cliente…».
10. Acciones legado sin UI visible: `toggle_list_cliente` (alterna `es_cliente`), `toggle_activo` (alterna `clients.activo`, usado desde el sidebar).
11. Modal global **«Nueva tarea»** (`erpTarea()` en erp_nav, abierto desde `client.php`): Título («¿Qué hay que hacer? (Enter para crear)»), Descripción opcional, Lista* (si no hay: «(sin listas — créala en Tareas del cliente)» y botón deshabilitado), Prioridad (**0 «Normal», 2 «Media», 3 «Alta»** — etiquetas incoherentes con el resto), Fecha límite, Responsable; POST `workspace.php save_task ajax=1` → `{ok,id}` o `{ok:0,msg:'Falta el título o la lista.'}`; toasts «Escribe un título», «¿A qué lista? Elígela para guardar», «Tarea creada ✓».

### 4.4 `listas.php` — Listas de **contactos del CRM** (no de tareas)
Aclaración: **no gestiona `task_lists`**. Es el «generador de listas» del CRM (rail activo `crm`), aunque su permiso de página sea `ver.tareas`. La gestión de listas de tareas vive en workspace (§4.3.4). Se documenta resumido; debería ir a la spec de CRM.
- URL: `listas.php[?id=N][&new=1][&export=N]`. Tablas: `lists (id, nombre, descripcion, tipo 'activa'|'estatica', condiciones JSON, fecha_creacion, fecha_congelado)`, `list_members (list_id, contact_id)`.
- Condiciones: `q` (nombre/empresa/email LIKE), `sector`, `origen` (origen_lead), `fase`, `servicio` (servicio_json LIKE), `prop` (propietario_id), `vmin/vmax` (valor), `quick` ∈ `sin_contactar` (fecha_ultimo_contacto NULL) | `act30` (sin contacto 30+ días).
- Miembros: estática = `list_members`; activa = contactos que cumplen ∪ añadidos a mano.
- UI: cabecera «Listas» + «Nueva lista»; cabecera de lista (nombre, «N contactos», «Añadir contacto», «Exportar CSV», «Convertir a estática» si activa, borrar); línea «Condiciones: …»; tabla Nombre · Empresa · Sector · Email · Teléfono · Embudo (badge color de fase) · ✕ (solo añadidos a mano). Vacíos: «Aún no hay listas. Crea la primera con «Nueva lista».», «Ningún contacto coincide.»
- POST: `new_list` (tipo `activa|estatica|manual`; manual exige ≥1 contacto «Elige al menos un contacto para la lista»), `freeze`, `del_list` (hard), `rename_list`, `add_member`, `del_member`. Export CSV con BOM: Nombre, Empresa, Sector, Email, Teléfono, WhatsApp, Origen, Valor, Embudo, Propietario.
- **Nota**: `perm_de_accion('listas.php')` mapea `del`/`del_lista`, pero la acción real es `del_list` → borrar no exige `tareas.borrar`.

### 4.5 `proyectos.php` — Rentabilidad por proyecto
**Propósito**: proyectos internos como centros de coste; la caja (`accounting.project_id`) es la única fuente. No tiene relación con tareas.
**URL**: `proyectos.php?y={año|all}` (por defecto año actual). **Permiso**: `ver.proyectos`; escribir `general.editar`.
**Layout**: título «Proyectos»; selector de años (años con movimientos + actual) + «Histórico»; texto «Rentabilidad interna por proyecto ({año}). Asigna facturas y gastos a un proyecto desde Facturas y Contabilidad; aquí ves lo que gana cada uno. No aparece en las facturas del cliente.»; 3 KPIs (Ingresos, Costes, Beneficio verde/rojo); tarjeta «Rentabilidad por proyecto» + «＋ Crear proyecto» (fila inline: nombre «Nombre del proyecto (ej: Web de Cliente X)…», Crear/Cancelar; color asignado por rotación de paleta); tabla Proyecto (punto color, nombre→ficha, badge «archivado», «N mov.») · Ingresos · Costes · Beneficio · Margen (barra ingresos/gastos + %) · ⋯; fila final gris «Sin proyecto» si hay movimientos sin proyecto. Vacío: «Aún no hay proyectos» / «Crea el primero y luego asígnalo a tus facturas y gastos.». Móvil: tabla→tarjetas.
Menú ⋯ (también clic derecho): «Ver proyecto», «Renombrar» (prompt), «Color» (10 muestras), «Archivar/Activar», «Borrar» («¿Borrar el proyecto? Los movimientos quedan sin proyecto.»).
**Consulta**: `SELECT p.*, SUM(ingreso) ing, SUM(gasto) gas, COUNT(a.id) nmov FROM projects p LEFT JOIN accounting a ON a.project_id=p.id [AND YEAR(a.fecha)=y] GROUP BY p.id ORDER BY activo DESC, ing DESC, nombre`.
**POST**: `add {nombre,color}`, `rename {id,nombre}` (sin validar vacío), `color {id,color}`, `toggle {id}`, `del {id}` (desvincula accounting e invoices, DELETE hard, sin papelera).

### 4.6 `proyecto.php` — Ficha de proyecto
**URL**: `proyecto.php?id=N[&y=año|all][&frag=vinc&q=texto]`. Sin proyecto → `proyectos.php`.
**Layout**: «← Proyectos»; título con punto de color + badge «archivado»; selector de años; menú ⋯ (Renombrar, Color, Archivar/Activar, «Borrar proyecto» con confirm «¿Borrar el proyecto «X»? Los movimientos y facturas quedan sin proyecto (no se borran).»); texto explicativo; 4 KPIs: Ingresos, Costes, Balance, Pendiente («facturas sin cobrar»).
- Tarjeta **«Movimientos de caja»** + «＋ Añadir ingreso» / «＋ Añadir gasto» (filas inline: concepto, importe «0,00 €» formato ES, fecha (hoy), Guardar/Cancelar). Tabla Fecha · Concepto (+ chip «Factura nº X» → facturas, «Documento» → descarga, o «Manual») · Ámbito · Importe (+/− coloreado) · desvincular (🔗, confirm «¿Desvincular este movimiento del proyecto? El apunte de caja se conserva, solo deja de contar aquí.»). Clic en fila abre factura/documento; clic derecho «Ver factura/Ver documento» + «Desvincular». Vacío: «Sin movimientos» / «Añade un ingreso o un gasto, o vincula una factura cobrada.»
- Tarjeta **«Facturas vinculadas»** + «Vincular factura» (modal con buscador «Buscar por número o cliente…», lista HTML vía `?frag=vinc&q=` con debounce 200 ms; filas número · cliente · estado (Borrador/Enviada/Pagada/Vencida) · total · «Ya vinculada» o ＋). Tabla Nº · Cliente · Fecha · Estado · Total · Situación («En caja» si pagada / «Pendiente») · desvincular.
**Datos** (`proyectos_lib.php`): `proj_balance` (SUM por tipo en accounting), `proj_movimientos` (accounting + invoices + invoice_uploads), `proj_facturas` (invoices.project_id; total = Σ(cantidad·precio)·(1+iva−irpf)), `proj_pendiente` (facturas no pagadas), `proj_invoices_vinculables(client_id, q, 20)` (sin proyecto o del cliente).
**POST**: `add_mov {tipo,concepto,importe(num_es),fecha}` → `proj_add_mov` (INSERT accounting: categoria Cliente/Gasto, metodo transferencia, legal 1, ambito empresa, notas «Añadido desde el proyecto»); `unlink_acc {acc_id}`; `link_invoice {invoice_id}` (pone project_id en invoice y en sus apuntes); `unlink_invoice`; `rename`; `color`; `toggle`; `del`.
También existe el **combobox de proyecto** reutilizable (`proj_combobox`, endpoint `facturas.php?proj_search=1&client=&q=` → `{ok, items:[{id,nombre,color,activo,client_id,is_client,nmov}]}`, grupos «Del cliente»/«Otros|Activos», opción «Crear «X»»), usado en Facturas/Contabilidad.

### 4.7 `dashboard.php` — Inicio
**Permiso**: ninguno (cualquier sesión). **Sin alcance** (riesgo).
**Layout**:
1. Saludo «Buenos días/Buenas tardes/Buenas noches, {user} 👋» (<12h / <20h / resto) + «Aquí tienes el resumen de tu agencia.»; a la derecha KPIs enlazados: «Clientes activos» (→index.php) y «Cobrado este mes» (→contabilidad; solo `general.editar` + `ver.importes`).
2. **Accesos rápidos** (4 tarjetas con icono de color, título, descripción, «Abrir ›»): Bóveda de credenciales («Accesos y contraseñas de clientes»), Contabilidad («Ingresos, gastos y resultado», solo editores), CRM · Ventas («Contactos, negocios y seguimiento»), Facturas («Emitir y controlar cobros», solo editores).
3. Fila de 3 tarjetas:
   - **Hoy** («Ver día» → `calendar.php?view=dia`): eventos de Google Calendar de hoy («todo el día» / «HH:MM · reunión») + tareas no completadas con `due_date=hoy` («vence hoy · {cliente}», avatares de asignados, orden prioridad desc). Vacío: «Día despejado» / «Sin reuniones ni vencimientos para hoy.»
   - **Próximas reuniones** («Ver todas» → reuniones.php): `gcal_meetings_range(hoy, +30d)`, máx 6, cliente emparejado por email de `contacts`, chip «Meet», enlace al evento. Vacío: «Sin reuniones próximas» / «Cuando agendes una, aparecerá aquí.» o, sin Google: «Calendario sin conectar» / «Conéctalo en Integraciones para ver tus reuniones.»
   - **Calendario** del mes (L‑D, hoy resaltado, punto en días con cosas: ámbar solo tareas, gris con eventos); hover → popover oscuro «{día} de {mes}» con hasta 6 ítems (+N más); clic → `calendar.php?view=dia&d=ISO`.
4. Tarjeta ancha **Tareas** con pestañas «En proceso» | «Atrasadas (N)» | «Completadas»: filas con fecha (día + mes abreviado; rojo si pasada, verde en completadas; «—» sin fecha), título, cliente, tag «Urgente» si prioridad ≥3 (no completadas), avatar del responsable. Vacíos: «Nada en proceso / No hay tareas en curso ahora mismo.», «Nada atrasado / Ninguna tarea se ha pasado de fecha.», «Nada completado aún / Aquí verás las tareas que vayáis terminando.»
**Consultas**:
- En proceso: `estado='en proceso' ORDER BY (due_date IS NULL), due_date, prioridad DESC LIMIT 8`.
- Atrasadas: `estado<>'completada' AND due_date<CURDATE() ORDER BY due_date LIMIT 8` (el contador de la pestaña es de esas 8, máx 8).
- Completadas: `estado='completada' ORDER BY updated_at DESC LIMIT 8`.
- Calendario: tareas no completadas con due_date en el mes en curso + eventos Google.
- Clientes activos: `COUNT(*) FROM clients WHERE COALESCE(activo,1)=1`.
- Cobrado este mes: Σ facturas `pagada` con `fecha` (de emisión, no de cobro) en el mes: base·(1+iva/100−irpf/100).

### 4.8 `notifications.php` — Bandeja de entrada
**URL**: `notifications.php`; JSON: `notifications.php?poll=1` → `{unread:int, latest:{id,titulo,cuerpo,url,actor}|null}` (último no leído, no borrado, no chat, no pospuesto). Al abrir la página corre `notif_sync_leads()`.
**Layout**: H1 «Bandeja de entrada»; pestañas con subtítulo y contador: «Principal · para ti» (rojo `#ef4444`), «Otras · del equipo» (gris), «Chat · equipo» (verde), «Más tarde · pospuestas» (ámbar), «Borradas · N en papelera». Barra: izquierda «Seleccionar» (entra en modo selección: casilla «Todo», «N seleccionada(s)», botones según pestaña: «Marcar leídas», «No leídas», «Posponer», «Traer ahora», «Restaurar», «Borrar», «Borrar definitivo», «Cancelar»); derecha (no en papelera) ✓ «Marcar todas leídas», «Enviar leídas a papelera»; en papelera «Vaciar papelera».
Lista agrupada por fecha: «Hoy», «Ayer», «Últimos 7 días», luego por mes («Julio», «Junio 2025» si no es el año actual). Fila (grid 22px · 150‑300px · 1fr · auto): círculo de **estado real de la tarea** enlazada (se extrae `task.php?id=N` de la url; mismos SVG que el tablero) o círculo con borde por tipo (tarea azul, lead morado, factura celeste); columna tarea/título (negrita si no leída); avatar del actor + línea; derecha: punto rojo si no leída, «🕒 dd/mm HH:MM» si pospuesta, hora (HH:MM hoy, «Ayer», dd/mm/aa), acciones al hover (posponer, traer ahora, restaurar, borrar ✕, purgar). Leídas con fondo gris suave. Clic: marca leída y navega a `url` (si no hay url, solo marca). Vacíos: global «Todo al día / No tienes notificaciones.»; por pestaña «Nada para ti por ahora.», «Sin actividad del equipo por ahora.», «No hay mensajes del chat de equipo.», «No has pospuesto ninguna notificación.», «La papelera está vacía.».
Toasts: «Movida a la papelera», «Restaurada», «Pospuesta a mañana 9:00», «Traída a Principal», «Todas marcadas como leídas», «Leídas enviadas a la papelera», «N marcadas como leídas/…».
Confirmaciones: «¿Vaciar la papelera?» / «Se borrarán definitivamente las notificaciones de la papelera. Esto no se puede deshacer.»; «¿Enviar las leídas a la papelera?» / «Las que aún no has leído se quedan. Podrás recuperarlas desde «Borradas».»; «¿Borrar definitivamente?».
**POST** (`action`, `id` o `ids[]`) → siempre `{ok:1}`: `read`, `unread`, `read_all` (no borradas ni pospuestas), `snooze` (`horas` opcional; si no, mañana 09:00), `unsnooze`, `del` (soft), `restore`, `del_all` (leídas no borradas→papelera), `purge` (solo si borrado=1), `purge_all`, y en bloque `read_ids, unread_ids, del_ids, restore_ids, snooze_ids, unsnooze_ids, purge_ids` (**`purge_ids` borra aunque no esté en papelera**). Todo filtrado por `admin_id = yo`. Sin paginación (carga todas).
**Global (erp_foot)**: sondeo cada 5 s (y al volver a la pestaña) de `?poll=1`; primer sondeo fija la base; si llega un id mayor → popup apilado arriba‑derecha (máx 6, ~6,5 s, se pausa con hover, clic navega, ✕ cierra; título «{actor} · {titulo}», cuerpo + «· +N más»), latido del globo y bip WebAudio. Globo «9+». (El chat tiene su propio sondeo `chat.php?ping=1` y notificaciones del navegador; fuera de esta área.)

### 4.9 `buscar.php` — Búsqueda global y paleta Ctrl+K
**URL**: `buscar.php?q=texto` (página) y `buscar.php?q=texto&json=1` (paleta). Mínimo 2 caracteres. Sin permiso de página y **sin filtrar por permisos ni alcance** (riesgo grave: un usuario limitado ve facturas, negocios, clientes ajenos…).
**Motor** (`LIKE %q%`, por grupo: 5 en JSON, 40 en página), orden de grupos: Clientes (name/username/fact_nombre/fact_nif; «@usuario · dado de baja») → Equipo (username/email; rol «Dueño/Editor/Solo lectura/Miembro» · email; →perfil.php) → Tareas (titulo/descripcion; «{cliente} · En espera|En proceso|…», no completadas primero, `updated_at` desc) → Contactos (nombre/empresa/email/telefono) → Negocio (deals nombre/servicio/contacto; valor «1.234 €» o fase) → Facturas (numero/cliente_nombre/cliente_nif/notas; «Pagada · dd/mm/aaaa») → Soporte (asunto/cuerpo) → Proyectos (→`proyectos.php#pID`) → «Ir a» (23 páginas estáticas con título/subtítulo/icono). Si `|q|≤4` el grupo «Ir a» pasa primero.
**JSON**: `{ok:true, q, n, grupos:[{g:'Clientes', r:[{t, s, u, i}]}]}` (`u` = URL legado, `i` = clave de icono: clients, user, check, crm, trend, file, ticket, layers, home, inbox, usercheck, cal, euro, chart, clock, calc, chat, vault, building, list, flag, settings, bolt, bell).
**Paleta** (erp_nav): Ctrl/⌘+K o «/» (si no estás escribiendo) abre overlay; input «Buscar clientes, tareas, contactos, negocios, facturas…» + «Esc»; debounce 180 ms; descarta respuestas tardías; secciones con cabecera; última fila «Ver todos los resultados · Página completa de búsqueda»; ↑/↓ circular, Enter abre seleccionado (o la página completa), Esc/clic fuera cierra; pie «↑↓ moverse · Enter abrir · Esc cerrar». Mensajes: «Escribe al menos dos letras.», «Nada coincide con «q».», «No se ha podido buscar ahora mismo.».
**Página**: H1 «Buscar», caja de búsqueda (GET), «N resultado(s) para «q»», grupos con filas (icono, título ≤120c, subtítulo). Vacíos: «Escribe al menos dos letras. También puedes abrir esta búsqueda desde cualquier página con **Ctrl + K**.» / «Nada coincide con «q».»

### 4.10 `papelera.php` — Papelera global
**Permiso**: `ver.ajustes` (página); `restore` exige `can_edit()` (+ `papelera.restaurar` por mapa de acción); `purge` exige `can_edit()` (+ `papelera.purgar`).
**Layout**: H1 «Papelera» + «Vaciar papelera» (confirm «¿Vaciar la papelera?» / «Se eliminarán definitivamente los N elementos de la papelera. Esto ya no tiene vuelta atrás.»); nota «Lo que borras en el ERP pasa por aquí y se puede devolver a su sitio. Pasados 30 días se elimina solo.»; lista (hasta 300, recientes primero): icono por tipo, título (o «{Tipo} #id»), «Borrado por {autor} · dd/mm/aaaa HH:MM», chip de tipo, «↩ Restaurar», 🗑 (confirm «¿Eliminar del todo?» / «Se elimina definitivamente. Ya no se podrá recuperar.»). Vacío: «La papelera está vacía. Nada que recuperar.». Flash por `?flash=&fok=`.
Tipos (`pap_tipos`): tarea, cliente, contacto (CRM), negocio, factura, ticket, lista (la URL apunta a `listas.php?id=` aunque las listas borradas son de **tareas** → enlace erróneo), acta.
**POST**: `restore {tid[, json]}` → `pap_restaurar` `{ok, id, tipo, msg}` («Tarea restaurada.», «Eso ya no está en la papelera.», «Eso ya existía otra vez, no hacía falta restaurarlo.», «No se ha podido devolver el registro.»); `purge {tid?}` (sin tid vacía todo) → `{ok:true,msg:'Eliminado definitivamente.'|'Papelera vaciada.'}`.
**Lib**: `pap_borrar(tabla,id,tipo,titulo,hijos)` foto JSON → borra hijos y madre → inserta en trash → `pap_purga` (>30 días). `pap_restaurar` reinserta con id original (salta columnas que ya no existan), republica portal si tabla tasks/task_lists/clients. `pap_vaciar` + barrido de **archivos huérfanos** de `uploads/{tasks,crm,chat,facturas,avatars}` (>48 h, buscando el nombre en todas las columnas de texto de la BD). `pap_undo_flash` + `toastUndo` en el pie: toast «{msg} · Deshacer» 7 s que llama a `papelera.php` con `json=1`.
**Borrado de tarea** (workspace `del_task`, y NUEVO `DELETE /tareas/{id}`): reacciones se borran (no se guardan); comentarios, checklist y adjuntos (filas) van en la foto. **No** se guardan `task_assignees`, `chk_assignees`, `time_entries` (quedan huérfanos y no vuelven al restaurar).

### 4.11 `actas.php` — Actas internas del equipo
**Permiso**: `ver.actas`; escribir/pinear/borrar `general.editar` (`require_can_edit()`). No sale al portal.
**URL / vistas**: `actas.php` (LISTA) · `actas.php?id=N` (LECTURA) · `actas.php?id=N&edit=1` (EDICIÓN) · `actas.php?nueva=1` (NUEVA). Sin permiso de edición, `nueva/edit` redirigen a lectura/lista. `&guardada=1` → toast «Acta guardada».
- **Lista**: H1 «Actas» + «＋ Nueva acta»; subtítulo «Notas y actas internas del equipo. No se ven en el portal del cliente.»; buscador en vivo «Buscar en las actas…» (título + extracto, en cliente) y chips por autor («Todos» + autores, solo si >1); tarjetas: badge «⚑ Fijada», título («(Sin título)»), extracto 2 líneas (`rt_excerpt` 200c), avatar + autor («Equipo» si sin autor) · fecha relativa (tooltip con fecha exacta «23 sep 2026, 18:40»); acciones hover: fijar/desfijar, borrar («¿Borrar esta acta? Se puede recuperar desde la papelera.»). Orden `pinned DESC, updated_at DESC, id DESC`. Vacíos: «Aún no hay actas» / «Crea la primera y escríbela con el mismo editor que las tareas: títulos, listas, tablas y pegar de la web conservando el formato.»; «No hay actas que coincidan con la búsqueda.»
- **Lectura**: «← Todas las actas», H1 título, filas meta: Autor (avatar), «Última edición» (relativa · exacta), «Creada»; acciones «✎ Editar», «⚑ Fijar/Fijada», «🗑 Borrar»; cuerpo renderizado (checklist no editable) o «Esta acta está vacía.»
- **Edición**: miga («Todas las actas» o «Volver al acta»), input título «Título del acta…» (máx 220), editor `rt_editor` (placeholder «Escribe aquí… títulos, listas, lista de control, cita, código, tablas y pega de la web conservando el formato.») con barra `rt_editor_toolbar`; barra inferior «✓ Guardar acta», «Cancelar/Volver», «Última edición hace…». Validación cliente: título o contenido no vacío («Escribe un título o algo de contenido»). Sin autosave.
- Fechas relativas: «hace un momento», «hace N minuto(s)/hora(s)», «hoy», «ayer», «hace N días», «hace N semana(s)», «hace N mes(es)», «hace N año(s)».
**POST**: `guardar {id?, titulo≤220, contenido}` (INSERT con `admin_id=yo` o UPDATE de cualquiera — no se comprueba autor) → redirect a lectura; `pin {id, ret?}` (`updated_at=updated_at` para no contar como edición); `borrar {id}` → papelera tipo `acta` + toast con Deshacer.

### 4.12 `duplicate.php`
Duplica un **cliente** (no tareas): POST `id`, alcance del cliente, `clientes.crear`; nuevo `username` `{u}_copia[N]`, nombre «{name} (copia)», copia credenciales/JSONs del portal; redirige a `client.php?id=new&dup=1`. **No copia listas ni tareas.** Pertenece a la spec de Clientes.

---

## 5. Formato de texto enriquecido (descripción, comentarios, actas)

Se guarda **texto con marcadores** (no HTML) y se renderiza en servidor con `rt_blocks()` + `rt_format()` (escapan todo y solo emiten etiquetas propias). El editor es un `contenteditable` que se serializa en JS (`descSerialize`/`cmSerialize`/`rtSerialize`).
- **Bloques** (por línea): `# / ## / ###` encabezados; `- ` o `• ` viñetas; `N. ` / `N) ` numeradas; `> ` cita; ```` ``` ```` código en bloque; `---` divisor; tablas markdown (`| a | b |` + fila `| --- |`, la 1ª es cabecera; `|` dentro de celda se sustituye por `/`); `[[chk:0]] texto` / `[[chk:1]] texto` lista de control (solo descripción y actas); línea vacía = párrafo vacío.
- **En línea**: `**negrita**`, `__subrayado__`, `*cursiva*`, `~~tachado~~`, `` `código` ``, `[texto](https://url)`, URLs sueltas autoenlazadas, `@usuario` (mención si existe).
- **Medios**: descripción `[[img:FN]]`, `[[file:FN|nombre original]]` (FN debe casar `^[A-Za-z0-9_.\-]+$`); comentarios `[[img]]` posicional (la n‑ésima imagen adjunta del comentario se pinta en el n‑ésimo marcador; el resto va debajo).
- `desc_to_plain()` deriva el texto que publica el portal: quita vallas de código, filas separadoras, encabezados, marcadores de formato y de medios; checklist/viñetas/numeradas → «• »; divisor → «—»; tabla → celdas separadas por espacios.
- Emojis Apple: spans `.ap-e` con `data-e` que se serializan como el carácter.
- **Recomendación React**: componente `RichText` (render) que parsee este formato a nodos React (sin `dangerouslySetInnerHTML`), y `RichEditor` (TipTap/ProseMirror o Lexical) con serializador/parseador a este mismo formato para mantener compatibilidad con el portal y los datos existentes. Portar `rt_blocks`/`desc_to_plain` a TS con tests de ida y vuelta contra muestras reales.

---

## 6. Recomendaciones

### (a) Endpoints que faltan en el backend nuevo (`/api/v1`)

**Tareas — ficha**
| Método | Ruta | Propósito |
|---|---|---|
| GET | `/tareas/{id}` (ampliar) | Añadir `descripcion_rich`, `created_at`, `asignados` completos, checklist con `asignados[]` y `orden`, adjuntos de tarea con `url`, `mime`, `es_imagen`, `admin_id`; comentarios con `reply_to`, `checklist`, `adjuntos[]`, `reacciones:[{emoji,n,mia}]`, `editable` (autor=yo); `tiempo:{total_min, mio_min, reparto:[{admin_id,username,min}]}`. O separar en subrecursos paginables. |
| PUT | `/tareas/{id}/asignados` | `{ids:[...]}` multi‑asignación (avisa solo a nuevos; sincroniza responsable_id). |
| PUT | `/tareas/{id}/descripcion` | `{rich}` (≤200 000) → guarda rich + plano, avisos de mención, publicar portal. |
| POST | `/tareas/{id}/archivos` | Subida multipart (`file`) para incrustar en descripción → `{fn, orig, url, es_imagen}`. |
| POST | `/tareas/{id}/adjuntos` | Subida multipart `files[]` de adjuntos de tarea → lista creada. |
| DELETE | `/tareas/{id}/adjuntos/{aid}` | Quitar adjunto (recomendado: papelera o al menos auditoría). |
| GET | `/tareas/{id}/comentarios?after={id}` | Feed incremental (sustituye el polling de página completa). |
| POST | `/tareas/{id}/comentarios` | Multipart: `cuerpo`, `files[]`, `checklist` (JSON), `reply_to` → comentario creado; avisos menciones/responsable/respuesta/checklist. |
| PATCH | `/tareas/{id}/comentarios/{cid}` | Editar cuerpo (solo autor) y/o `checklist` (cualquiera con edición; avisa nuevos responsables). |
| DELETE | `/tareas/{id}/comentarios/{cid}` | Borrar (solo autor) con sus adjuntos y reacciones. |
| POST | `/tareas/{id}/comentarios/{cid}/checklist/{idx}/toggle` | Marcar ítem del checklist de un comentario. |
| POST | `/tareas/{id}/comentarios/{cid}/reacciones` | `{emoji}` toggle → reacciones actualizadas. |
| POST | `/tareas/{id}/checklist` | `{texto, asignados:[ids]}` crea punto (orden MAX+1, avisos). |
| PATCH | `/tareas/{id}/checklist/{chkId}` | `{done?, texto?, orden?}` (done → `notif_check_done`). |
| PUT | `/tareas/{id}/checklist/{chkId}/asignados` | `{ids}` (avisa nuevos). |
| DELETE | `/tareas/{id}/checklist/{chkId}` | Borrar punto (+ `chk_assignees`). |
| PUT | `/tareas/{id}/tiempo` | `{horas, admin_id?}` → línea «Horas de la tarea» de esa persona (permiso `tareas.horas`). |
| GET | `/tareas/{id}/actividad` (opcional) | Si se quiere historial real (hoy solo «Tarea creada»). |

**Tareas — tablero y listas**
| Método | Ruta | Propósito |
|---|---|---|
| POST | `/tareas/orden` | `{client_id, ids:[...]}` reordenar tareas (`tasks.orden`). |
| POST | `/clientes/{id}/listas` | Crear lista `{nombre, tipo, es_cliente?}`; con `{por_defecto:true}` crea las 4 listas por defecto. |
| PATCH | `/listas/{id}` | Renombrar / `es_cliente`. |
| POST | `/clientes/{id}/listas/orden` | Reordenar listas. |
| POST | `/listas/{id}/clonar` | Clonar lista con tareas. |
| DELETE | `/listas/{id}` | A papelera con sus tareas (y sus hijos, para que Deshacer sea completo). |
| PUT | `/listas/{id}/informe/{mes}` | `{texto}` «Informe del mes» (crea/actualiza la entrada especial). |
| GET | `/listas/{id}/meses` | Meses con entradas (chips) — o incluirlo en `GET /tareas?view=cliente`. |
| POST | `/clientes/{id}/publicar` | Forzar `publicar_progreso` (permiso `clientes.portal`). |
| POST | `/clientes/orden` | Reordenar carpetas de cliente (si el sidebar lo necesita). |

**Notificaciones**
| Método | Ruta | Propósito |
|---|---|---|
| GET | `/notificaciones?bandeja=principal|otras|chat|tarde|papelera&limit&offset` | Lista paginada + contadores por bandeja + estado de la tarea enlazada (`tarea_estado`) + destino traducido a ruta del front. |
| GET | `/notificaciones/resumen` | `{unread, latest}` para el sondeo (o SSE). |
| POST | `/notificaciones/acciones` | `{accion: leer|no_leer|posponer|traer|borrar|restaurar|purgar, ids:[...], horas?}`. |
| POST | `/notificaciones/leer-todas`, `/notificaciones/leidas-a-papelera`, `/notificaciones/vaciar-papelera` | Acciones globales. |
| — | Servicio PHP `Notificador` en `backend/src` | Portar `notif_add`, `notif_task_*`, `notif_check_*`, `notif_comment_scan`, `notif_desc_scan`, `notif_duenos` para que la API nueva **sí** genere avisos; y mover los *sync* (leads, facturas, reuniones, cumpleaños) a cron. |

**Búsqueda, papelera, inicio, proyectos, actas**
| Método | Ruta | Propósito |
|---|---|---|
| GET | `/buscar?q=&por_grupo=5` | Mismo contrato que el JSON legado pero **filtrando por permisos (`ver.*`) y alcance**, con rutas del front. |
| GET | `/papelera?limit&offset` | Listado (filtrar por lo que el usuario puede restaurar). |
| POST | `/papelera/{id}/restaurar` (generalizar) | Hoy solo acepta tipo `tarea`; ampliar a todos los tipos con su permiso de módulo. |
| DELETE | `/papelera/{id}` · DELETE `/papelera` | Purgar uno / vaciar (`papelera.purgar`). |
| GET | `/inicio` | `{kpis:{clientes_activos, cobrado_mes?}, hoy:[…], reuniones:[…], calendario:{dia:[…]}, tareas:{en_proceso, atrasadas, completadas}}` con alcance aplicado. |
| GET | `/proyectos?y=` · POST `/proyectos` · PATCH `/proyectos/{id}` · DELETE `/proyectos/{id}` | Listado con KPIs, crear, renombrar/color/archivar, borrar. |
| GET | `/proyectos/{id}?y=` | Ficha: balance, movimientos, facturas, pendiente. |
| POST | `/proyectos/{id}/movimientos` · DELETE `/proyectos/{id}/movimientos/{accId}` | Añadir apunte / desvincular. |
| GET | `/proyectos/{id}/facturas-vinculables?q=` · POST/DELETE `/proyectos/{id}/facturas/{invId}` | Vincular/desvincular facturas. |
| GET | `/proyectos/buscar?q=&client=` | Combobox de proyecto. |
| GET | `/actas?q=&autor=` · GET `/actas/{id}` · POST `/actas` · PATCH `/actas/{id}` · POST `/actas/{id}/fijar` · DELETE `/actas/{id}` | CRUD de actas (borrar → papelera). |

**Migraciones necesarias**: `tasks.descripcion_rich`, `task_comments.reply_to` (+ índice), `time_entries` (+ columnas de `admins`), `projects` + `accounting.project_id` + `invoices.project_id`, `actas`, `lists`/`list_members` si se migra CRM. Hoy las crea el PHP legado al vuelo.

### (b) Pantallas y componentes React

- **`TareaPage`** (ruta `/tareas/:id`, con `?c=` / `#c` para resaltar comentario): sustituye/evoluciona `TareaDetalle` (drawer RO) — o drawer ancho editable con pestañas Detalles/Actividad en móvil.
  - `TituloEditable`, `CamposTarea` (rejilla) con `EstadoPicker` (pastilla rellena), `AsignadosPicker` (multi), `RangoFechas` + `DatePicker` (dd/mm/aa, colores de vencimiento), `TiempoPopover` (selector de persona + reparto), `PrioridadPicker`, `CampoTexto` (Etiquetas, Mes).
  - `VisibilidadCliente` (nuevo: `visible_cliente`, `titulo_cliente`, `explicacion_cliente`) — hoy no hay UI en ningún sitio; decidir si va aquí.
  - `RichEditor` (descripción con autosave 700 ms, subidas, menciones, bloques, tablas, pegado limpio) + `RichText` (render seguro) + `MentionPopup` + `BlockMenu` + `TableToolbar` + `EmojiPicker`.
  - `Checklist` (`ChecklistItem` con `FacePile` y `MultiPersonPicker`, alta con Enter/blur, animación de tachado, orden hechas arriba).
  - `Adjuntos` (`AttachmentGrid`, `Dropzone` de página completa con dos destinos, `Lightbox`).
  - `ActividadPanel`: `ComentarioItem` (cita, checklist de comentario editable, imágenes intercaladas, `ReaccionesBar` 👍 + chips, `ComentarioMenu` contextual), `Compositor` (respuesta, checklist inline, adjuntos/imágenes inline, menciones, Enter envía), polling incremental o SSE, resaltado por ancla.
- **Tablero** (`TareasPage`, ampliar): `NuevaTareaFila` (alta rápida por grupo), `NuevaTareaModal` (también invocable desde ficha de cliente), multi‑asignación en fila, *drag & drop* de filas (dnd‑kit) y de pestañas de lista, `ListaMenu` contextual (abrir, renombrar, clonar, borrar), `NuevaListaMenu` (Lista / Informe de cliente), `ListasPorDefectoCTA`, `TareaMenu` contextual (abrir, marcar completada, borrar), badge «cliente», botones «Publicar al portal» / «Ver portal», selector «Ir a un cliente…».
- **`InformeListaView`**: chips de meses, `InformeMesEditor` (estado Publicado/Sin publicar, «Guardar y publicar»), tabla de entradas del mes, «Añadir tarea del mes».
- **`NotificacionesPage`**: pestañas con contadores, `NotifRow` (círculo de estado de tarea/tipo, actor, hora), agrupación por fecha, modo selección + acciones en bloque, acciones globales, vacíos por pestaña; **`NotifToaster`** global (popups apilados, sonido, badge) alimentado por `GET /notificaciones/resumen` (o SSE).
- **`CommandPalette`** (Ctrl+K y «/»): conectar el input del `Topbar` a `GET /buscar`, navegación por teclado, «Ver todos los resultados»; **`BuscarPage`** (`/buscar?q=`).
- **`PapeleraPage`** (en Ajustes) + toast «Deshacer» genérico (ya existe para tareas en `useToast`).
- **`InicioPage`** (`/inicio`): `Saludo` + KPIs, `AccesosRapidos`, `HoyCard`, `ProximasReunionesCard`, `MiniCalendario` con popover, `TareasTabsCard`.
- **`ProyectosPage`** + **`ProyectoPage`**: `SelectorAño`, `KpiCard`, `TablaRentabilidad` (→tarjetas en móvil), `ProyectoMenu` (renombrar/color/archivar/borrar), `NuevoMovimientoFila`, `TablaMovimientos`, `TablaFacturasVinculadas`, `VincularFacturaModal`, `ProyectoCombobox` (reutilizable en Finanzas).
- **`ActasPage`** (lista con búsqueda y chips de autor) + **`ActaPage`** (lectura) + **`ActaEditor`** (reusa `RichEditor`/`RichText`).

### (c) Riesgos y ambigüedades

1. **Notificaciones rotas en la API nueva**: las funciones `notif_*` no existen en `backend/` (`function_exists` falla en silencio). Hoy, asignar desde React **no avisa a nadie**. Prioridad alta.
2. **Formato de texto**: API.md y el front tratan `descripcion`/`cuerpo` como HTML; son marcadores propios. Además la ficha nueva lee `descripcion` (plano) y no `descripcion_rich`: si se edita la descripción en lo nuevo escribiendo solo `descripcion`, la versión rica quedará desincronizada (task.php prioriza `descripcion_rich` si no está vacía → el cambio «desaparece»). Hay que escribir ambas siempre.
3. **Columnas creadas al vuelo** (`descripcion_rich`, `reply_to`, `time_entries`, `projects`, `actas`, `accounting.project_id`) no están en las migraciones nuevas; en una instalación limpia el backend nuevo fallará al leerlas.
4. **URLs de notificación** guardadas como rutas PHP (`task.php?id=…#c…`, `crm.php?open=…`, `facturas.php?v=…`). Durante la convivencia, el front necesita un traductor o el backend debe guardar entidad+id. Los `ref` de deduplicación deben mantenerse idénticos para no duplicar avisos ya emitidos.
5. **Alcance no aplicado en el legado** en dashboard, buscar.php, vista de cliente del workspace (acceso por URL a otra lista) y notificaciones con estado de tarea: replicar «tal cual» filtraría datos. El nuevo debe filtrar siempre (y por `ver.*` en la búsqueda).
6. **Permisos más estrictos en lo nuevo** (`tareas.editar` etc.) que en el legado (solo `general.editar`): roles que hoy editan en PHP podrían no poder hacerlo en React. Revisar `perm_herencia`/roles reales. Definir permisos para comentar, reaccionar e imputar horas (`tareas.horas`).
7. **Borrados duros sin papelera** en el legado: comentarios, puntos de checklist, adjuntos (borra el archivo físico), proyectos, listas del CRM, notificaciones purgadas. Decidir si se mantiene. `del_comment` borra adjuntos de un comentario ajeno aunque no borre el comentario (bug). Borrar tarea/lista deja huérfanos `task_assignees`, `chk_assignees`, `time_entries` y no los restaura.
8. **Tiempo**: el campo de la ficha sustituye solo la línea «Horas de la tarea» de esa persona con fecha = hoy (cambia la fecha de la línea en cada corrección → afecta a qué mes se factura). El texto del menú de borrar tarea dice «Se borran también sus comentarios y sus horas» pero las horas no se tocan.
9. **Prioridades incoherentes** en el modal global «Nueva tarea» (0 «Normal», 2 «Media», 3 «Alta») frente al resto (0 Ninguna, 1 Baja, 2 Normal, 3 Alta, 4 Urgente). Dashboard marca «Urgente» con prioridad ≥3 (incluye Alta). Unificar.
10. **Colores de estado distintos** entre task.php/erp_nav (`#b0b4bb/#3b82f6/#e0a000/#12a150`) y workspace (`#64748b/#2563eb/#a16207/#0f7a3d`). Elegir una paleta.
11. **Campos de visibilidad al cliente sin UI** en el legado (solo `visible_cliente` vía badge). Ambiguo si deben editarse en la ficha nueva; el portal depende de ellos (y de `task_lists.es_cliente`, cuya acción `toggle_list_cliente` no tiene botón).
12. **«Informe del mes»** es una tarea con título mágico «Informe del mes»: renombrarla rompe el editor; `mes` es texto libre («Junio 2026» vs «Junio») → los chips de meses pueden duplicarse. Conviene normalizar (`YYYY-MM`) o mantener compatibilidad con `mes_label()`.
13. **Polling caro**: task.php re‑renderiza la página completa cada 5 s por pestaña abierta; la campana sondea cada 5 s. En React usar endpoints incrementales (`after=`) o SSE, y pausar en pestaña oculta.
14. **`listas.php` no es de tareas** (es CRM) pese a su permiso `ver.tareas`; y `perm_de_accion` de `listas.php`/`task.php` apunta a acciones que no existen (no protege nada). La papelera enlaza las listas borradas (de tareas) a `listas.php?id=` (destino erróneo).
15. **Proyectos** muestran importes sin `ver.importes` y el permiso efectivo (`ver.proyectos`) se define dos veces en `perm_de_pagina` (gana el último). `rename` acepta nombre vacío.
16. **Dashboard**: «Cobrado este mes» suma por fecha de **emisión** de facturas pagadas, no por fecha de cobro; el contador «Atrasadas (N)» está topado en 8.
17. **Notificaciones** `purge_ids` borra definitivamente aunque no estén en la papelera; la página carga todas las notificaciones sin paginar.
18. **Menciones** se resuelven por `username` en minúsculas y regex `[\p{L}0-9_.\-]+`: usernames con espacios no se pueden mencionar; renombrar un usuario rompe menciones antiguas (quedan en estilo «plain»).
19. **Archivos**: `desc_upload` no crea fila en `task_attachments`; el barrido de huérfanos depende de encontrar el nombre en cualquier texto de la BD (si se migra el formato de la descripción a JSON/HTML, mantener el nombre de archivo literal o se borrarán imágenes).
20. **Actas**: cualquiera con edición puede reescribir actas ajenas; no hay historial de versiones ni autosave (se puede perder texto al salir).
