# API · Trabajo (tareas, inicio, avisos, búsqueda, papelera, actas)

Convenciones de `backend/API.md`: JSON, `{ok:true, …}`, errores `{ok:false, msg, error, campo?}`,
CSRF en `X-CSRF-Token` para POST/PATCH/DELETE. Fechas `AAAA-MM-DD`; dinero en céntimos.
Texto enriquecido (descripción, comentarios, actas) = **formato del ERP con marcadores**, nunca HTML.
Fuera de alcance (tarea o cliente que no se ve) = **404**; sin permiso = **403**; datos = **422**.

Rutas en `backend/api/rutas/tareas.php` y `backend/api/rutas/trabajo.php`.

## Tablero

| Método | Ruta | Permiso | Notas |
|---|---|---|---|
| GET | `/v1/tareas?view=all\|mine\|emp\|cliente&emp&cli&list&fe&fr&mes&limit&offset` | ver.tareas | Igual que antes. `mine`/`emp` cuentan también los asignados de `task_assignees`. Cada tarea trae además `mes`, `etiquetas`, `orden`, `list_tipo`. `mes` filtra en la vista de cliente (listas de informe). |
| POST | `/v1/tareas` | general.editar + tareas.crear | `{client_id, list_id, titulo, estado?, prioridad?, asignados?:[ids], responsable_id?, fecha_inicio?, due_date?, etiquetas?, mes?, descripcion?, visible_cliente?, titulo_cliente?, explicacion_cliente?}` → 201 `{tarea}`. Va al final de la lista (`orden`). Avisa a los asignados y a los mencionados en la descripción. |
| PATCH | `/v1/tareas/{id}` | general.editar + tareas.editar | Campos anteriores; `asignados:[ids]` (el primero = responsable_id; avisa solo a los nuevos); `descripcion` (formato rico, ≤200 000: guarda `descripcion_rich` y el plano `descripcion` para el portal; avisa menciones una vez por persona). Anota el historial y avisa a los dueños al pasar a «en proceso» o al poner fecha. → `{tarea}` |
| DELETE | `/v1/tareas/{id}` | general.editar + tareas.borrar | A la papelera con comentarios, reacciones, checklist (+asignados), adjuntos, asignados e historial. Las horas se quedan. → `{papelera_id}` |
| POST | `/v1/tareas/orden` | general.editar + tareas.editar | `{list_id, ids:[…]}` (solo cuenta las de esa lista que se ven) |
| GET | `/v1/tareas/meses?cli&list` | ver.tareas | `{items:[{mes, n}]}` chips de una lista de informes |

## Ficha de la tarea

| Método | Ruta | Permiso | Notas |
|---|---|---|---|
| GET | `/v1/tareas/{id}` | ver.tareas + alcance | `{tarea}`: lo de la lista + `descripcion` (rica si existe), `titulo_cliente`, `explicacion_cliente`, `created_at`, `updated_at`, `actividad:[{id,tipo,detalle,actor,created_at}]`, `checklist:[{id,texto,done,orden,asignados:[ids]}]`, `adjuntos:[Adjunto]`, `tiempo:{total_min, reparto:[{persona,minutos}]}`, `permisos:{editar,borrar,comentar,horas}` |
| GET | `/v1/tareas/{id}/comentarios?despues=` | ver.tareas | `{items:[Comentario], n, ultimo}`. `Comentario = {id, autor:Persona\|null, cuerpo, checklist:[{texto,done,resp}], reply_to, editado, created_at, adjuntos:[Adjunto], reacciones:[{emoji,n,mia,quienes}], mio}` |
| POST | `/v1/tareas/{id}/comentarios` | general.editar | multipart (o JSON): `cuerpo`, `reply_to?`, `checklist?` (JSON), `archivos[]` → 201 `{comentario}`. Avisos: mencionados, responsable, a quien se responde (si no se le menciona) y responsables de la checklist. |
| PATCH | `/v1/tareas/{id}/comentarios/{cid}` | general.editar | `{cuerpo?}` solo su autor (403 si no) · `{checklist?}` cualquiera que comente |
| DELETE | `/v1/tareas/{id}/comentarios/{cid}` | general.editar, solo su autor | Borra también sus adjuntos y reacciones |
| POST | `/v1/tareas/{id}/comentarios/{cid}/reacciones` | general.editar | `{emoji}` alterna → `{reacciones}` |
| POST | `/v1/tareas/{id}/comentarios/{cid}/checklist/{idx}` | general.editar | `{done?}` (sin él alterna) → `{checklist}` |
| POST | `/v1/tareas/{id}/checklist` | general.editar + tareas.editar | `{texto, asignados?}` → 201 `{checklist}` (avisa a los asignados) |
| PATCH | `/v1/tareas/{id}/checklist/{chk}` | idem | `{done?, texto?, asignados?}` (avisa al marcar y a los asignados nuevos) |
| DELETE | `/v1/tareas/{id}/checklist/{chk}` | idem | Borra también sus asignados |
| POST | `/v1/tareas/{id}/checklist/orden` | idem | `{ids}` |
| POST | `/v1/tareas/{id}/adjuntos` | idem | multipart `archivos[]` (≤20, ≤25 MB, lista blanca sin SVG) → 201 `{adjuntos}` |
| DELETE | `/v1/tareas/{id}/adjuntos/{aid}` | idem | Borra el fichero si nadie más lo nombra |
| POST | `/v1/tareas/{id}/archivos` | idem | multipart `archivos[]` para incrustar en la descripción → 201 `{archivos:[{fn,nombre,imagen,url}]}` (sin fila) |
| POST | `/v1/tareas/{id}/tiempo` | general.editar + tareas.horas | `{horas:"1,5", admin_id?}` → `{tiempo}`. Solo la línea «Horas de la tarea» de esa persona; corregir conserva su fecha. |

`Adjunto = {id, nombre, filename, url:"archivo.php?d=tasks&f=…", mime, es_imagen, admin_id, created_at}` (url relativa al backend).

## Listas de un cliente

| Método | Ruta | Permiso | Notas |
|---|---|---|---|
| GET | `/v1/tareas/listas?cli=` | ver.tareas | `{listas:[{id,client_id,nombre,es_cliente,tipo,orden}]}` |
| POST | `/v1/tareas/listas` | tareas.crear | `{client_id, nombre, tipo:'tareas'\|'informe', es_cliente?}` o `{client_id, por_defecto:true}` → 201 `{listas, id}`. Una sola de informes. |
| PATCH | `/v1/tareas/listas/{id}` | tareas.editar | `{nombre?, es_cliente?}` → `{lista}` (republica el portal si cambia es_cliente) |
| POST | `/v1/tareas/listas/orden` | tareas.editar | `{client_id, ids}` |
| POST | `/v1/tareas/listas/{id}/clonar` | tareas.crear | «{nombre} (copia)» con sus tareas → 201 `{listas, id}` |
| DELETE | `/v1/tareas/listas/{id}` | tareas.borrar | A la papelera con sus tareas y todo lo de ellas → `{papelera_id}` |
| GET | `/v1/tareas/listas/{id}/informe?mes=` | ver.tareas | `{informe:{mes,texto,publicado,id,updated_at}}` |
| POST | `/v1/tareas/listas/{id}/informe` | tareas.editar | `{mes, texto≤60000}` guarda y publica el «Informe del mes» |
| POST | `/v1/tareas/publicar` | general.editar + clientes.portal | `{client_id}` → publicar_progreso |

## Inicio

| GET | `/v1/inicio` | sesión | `{hoy, kpis:{clientes_activos\|null, cobrado_mes\|null (céntimos, por fecha de cobro; ver.finanzas+ver.importes)}, tareas:{en_proceso, atrasadas, atrasadas_total, completadas}\|null, hoy_tareas, calendario:[{dia,titulo,sub,tipo,hora,url}]}` con alcance |
|---|---|---|---|
| GET | `/v1/inicio/agenda` | sesión | Google Calendar de quien mira: `{conectado, hoy, reuniones, calendario, error?}` |

## Avisos

| Método | Ruta | Notas |
|---|---|---|
| GET | `/v1/notificaciones?bandeja=principal\|otras\|chat\|tarde\|papelera&limit&offset` | `{items:[{id,tipo,titulo,cuerpo,url,tarea,actor,actor_foto,leido,snooze_until,created_at,tarea_id,tarea_estado}], total, limit, offset, contadores:{bandeja:{total,no_leidas}}}`. `tarea_estado` solo si la tarea entra en su alcance. |
| GET | `/v1/notificaciones/avisos?despues=` | Sondeo ligero: `{no_leidas, ultimo_id, nuevos:[…]}` (con `despues=0` no devuelve avisos: línea base). Corre `notif_sync_todo()` (como mucho cada 10 min). |
| POST | `/v1/notificaciones/acciones` | `{accion: leer\|no_leer\|posponer\|traer\|borrar\|restaurar\|purgar, ids:[…], horas?}` → `{cambiadas, hasta, no_leidas}`. Posponer sin horas = mañana 9:00. Purgar solo lo que está en la papelera. |
| POST | `/v1/notificaciones/leer-todas` · `/leidas-a-papelera` · `/vaciar-papelera` | Globales |

## Búsqueda

`GET /v1/buscar?q=&por_grupo=5` (máx. 40) → `{q, n, grupos:[{g, r:[{t,s,u,i}]}]}`. Mínimo 2 letras. Cada grupo
exige su permiso (`ver.clientes`, `ver.tareas`, `ver.crm`, `ver.finanzas`, `ver.soporte`, `ver.proyectos`,
`ver.actas`) y aplica el alcance; `u` es ruta del front; «Ir a» filtra las páginas por permiso.

## Papelera

| Método | Ruta | Permiso | Notas |
|---|---|---|---|
| GET | `/v1/papelera?limit&offset` | ver.ajustes | Solo tipos de módulos que se ven → `{items:[{id,tipo,tipo_label,titulo,ref_id,autor,mio,created_at,caduca}], total, dias, permisos:{restaurar,purgar}}` |
| POST | `/v1/papelera/{id}/restaurar` | general.editar (+ papelera.restaurar si lo borró otro) + permiso del módulo | Cualquier tipo → `{id, tipo, msg, url}` |
| DELETE | `/v1/papelera/{id}` · `/v1/papelera` | general.editar + papelera.purgar | Purgar uno / vaciar (`{borrados}`) |

## Actas

| Método | Ruta | Permiso | Notas |
|---|---|---|---|
| GET | `/v1/actas?q&autor` | ver.actas | `{items:[{id,titulo,extracto,autor,fijada,created_at,updated_at}], autores:[{persona,n}], puede_editar}` |
| GET | `/v1/actas/{id}` | ver.actas | `{acta:{…, contenido, puede_editar}}` |
| POST | `/v1/actas` | + general.editar | `{titulo≤220, contenido}` (título o contenido) → 201 |
| PATCH | `/v1/actas/{id}` | + general.editar | `{titulo?, contenido?, version?}`: `version` = `updated_at` leído; si otro la cambió → 409 |
| POST | `/v1/actas/{id}/fijar` | + general.editar | `{fijada?}` (sin tocar updated_at) |
| DELETE | `/v1/actas/{id}` | + general.editar | A la papelera → `{papelera_id}` |

## Cron

`Croilab\Modulos\Trabajo\Cron::ejecutar(PDO): string[]` — sincronizaciones de avisos (facturas vencidas, leads,
solicitudes de reunión, cumpleaños), purga de la papelera (`pap_purga(true)`, con limpieza de respuestas de
tickets huérfanas y archivos sin dueño) y limpieza de marcas de «pospuesto» vencidas.
