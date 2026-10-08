# API · CRM

Prefijo `/api/v1/crm`. Convenciones de `backend/API.md`: JSON `{ok:true, …}`, CSRF (`X-CSRF-Token`) en POST/PATCH/DELETE,
errores `{ok:false, msg, error}` con `403 permiso`, `404 no_encontrado` (también **fuera de alcance**), `409 conflicto|duplicado`,
`422 validacion` + `campo`. Escribir exige siempre `general.editar` además del permiso concreto.

Código: `backend/src/Modulos/Crm/*`, rutas en `backend/api/rutas/crm.php`, migración `0030_crm_esquema.php`,
tests en `backend/tests/{Unit,Integracion}/Crm`.

## Permisos (03-crm.md §2.3) y alcance

| Acción | Permiso |
|---|---|
| Leer contactos, ficha, negocios, listas, vistas, dashboard, seguimientos, exportar CSV | `ver.crm` |
| Crear contacto, negocio, lista, propuesta, comentario, adjunto, seguimiento manual, importar CSV | `crm.crear` |
| Editar campos, mover de fase, perder/ganar, archivar, etiquetar, asignar, facturación, miembros y orden de listas, acciones de seguimientos, generar, ejecutar resumen, crear/editar etiquetas | `crm.editar` |
| Borrar contacto/negocio (papelera), lote «borrar», propuesta, adjunto, lista, etiqueta | `crm.borrar` |
| Convertir en cliente | `crm.convertir` + `ver.clientes` (y el alta de Clientes exige `clientes.crear`) |
| Fases del embudo, sectores, vistas globales | `admin.total` |
| Borrar un comentario | su autor o `admin.total` |
| Deshacer un borrado | quien borró, o `papelera.restaurar` |

**Alcance**: con `alcance.todos` se ve todo. Sin él, solo los contactos **propios o sin propietario**, y lo que cuelga de ellos
(negocios, comentarios, miembros de listas, seguimientos, cifras del dashboard). Lo demás responde 404. Sin `alcance.todos`
no se puede crear ni asignar un contacto a otra persona (422 `propietario_id`).

**Importes**: euros como número con 2 decimales (DECIMAL en la base, se parsea «a la española»: `"1.234,56"`, `"12,5"`,
`"12.5"`, `"1.200 €"`). Sin `ver.importes` todas las cifras de dinero llegan `null` (valor, importe, métricas, gráficas de
euros), no se puede filtrar ni ordenar por valor y el CSV sale sin la columna de valor rellena.

## Catálogos y configuración

| Método | Ruta | Permiso | Qué hace |
|---|---|---|---|
| GET | `/catalogos` | `ver.crm` | `{fases[], origenes[], servicios[], sectores[], etiquetas[], tipos_comentario[], motivos_perdida[{value,label,meses}], estados_propuesta[], canales[{value,label,color}], estados_reunion[]}`. Fase: `{id,nombre,slug,orden,probabilidad,tipo:abierta|ganada|perdida|pausa,color,estructural}`. |
| POST | `/fases` | `admin.total` | `{nombre, probabilidad?, color?}` → 201 `{fases}`. Slug ASCII único; va detrás de la última abierta. |
| PATCH | `/fases/{id}` | `admin.total` | `{nombre?, probabilidad?, color?}` → `{fases}`. |
| POST | `/fases/orden` | `admin.total` | `{ids:[…todas]}` → `{fases}`. Las abiertas quedan siempre delante de las de cierre. |
| DELETE | `/fases/{id}` | `admin.total` | Solo abiertas no estructurales (`lead_nuevo`, `propuesta`, `ganado`, `perdido`, `pausa` no se borran) → `{fases, destino}`; sus negocios **y contactos** pasan a la primera abierta. 409 si es estructural o la única abierta. |
| POST | `/etiquetas` | `crm.editar` | `{nombre, color?}` → 201 `{etiqueta, etiquetas}`; 409 `duplicado` si ya existe. |
| PATCH | `/etiquetas/{id}` | `crm.editar` | `{nombre?, color?}` → `{etiquetas}`. |
| DELETE | `/etiquetas/{id}` | `crm.borrar` | La quita de contactos y negocios → `{etiquetas}`. |
| PATCH | `/sectores` | `admin.total` | `{sectores:[…]}` → `{sectores}` (settings `crm_sectors`). |
| GET | `/vistas` | `ver.crm` | `{vistas:[{id,nombre,filtros,global,puede_borrar}]}` (propias + globales). |
| POST | `/vistas` | `ver.crm` (`admin.total` si `global`) | `{nombre, filtros:{…}, global?}` → 201 `{vistas}`. Los filtros son un **objeto** (la migración 0030 convierte los query strings del antiguo). |
| DELETE | `/vistas/{id}` | la propia (globales: `admin.total`) | → `{vistas}`. |

## Contactos

Filtros (query o JSON): `q` (nombre/empresa/email/teléfono), `sector`, `origen`, `fase`, `servicio`, `prop` (id o `sin`),
`tag`, `vmin`, `vmax`, `fdesde`, `fhasta` (`AAAA-MM-DD`, sobre la fecha de alta), `quick` = `sin_contactar|act7|act30|vencidas|perdido`.
Orden: `sort` = `nombre|empresa|sector|valor|fase|ult|prox|creado`, `dir` = `asc|desc` (por defecto alta descendente).

**ContactoFila**: `{id, nombre, empresa, sector, email, telefono, whatsapp, linkedin, web, origen_lead, servicios[], valor, fase,
proxima_accion, fecha_prox, accion_vencida, propietario_id, ultima_actualizacion, fecha_creacion, fecha_ultimo_contacto, client_id, etiquetas[{id,nombre,color}]}`.

| Método | Ruta | Permiso | Qué hace |
|---|---|---|---|
| GET | `/contactos` | `ver.crm` | Filtros + `limit` (≤ 1000, def. 300) / `offset` → `{items, total, total_sin_filtros, filtros, n_filtros, negocios_coincidentes[{id,nombre,valor,fase,contact_id,contacto}] (con q, máx. 8), limit, offset}`. |
| POST | `/contactos` | `crm.crear` | `{nombre*, empresa, sector, email, telefono, origen_lead, propietario_id, …}` → 201 `{contacto}`. Fase `lead_nuevo`, actividad «Contacto creado». Avisa al propietario si es otra persona. |
| GET | `/contactos/{id}` | `ver.crm` | Ficha: `{contacto, facturacion{razon_social,cif,direccion,cp,ciudad,provincia,pais,email_facturacion,iban}, comentarios[{id,autor_id,autor,tipo,contenido,fecha,puede_borrar}], propuestas[], adjuntos[{id,nombre,url,mime,fecha}], listas[], reuniones[{id,fecha,hora,titulo,estado,notas,docs[{title,url}]}] (solo lectura), actividad[60], negocios[], cliente{id,name}\|null}`. |
| PATCH | `/contactos/{id}` | `crm.editar` | Campos editables: textos de la fila, `valor`, `fecha_prox`, `fecha_ultimo_contacto`, `propietario_id`, `servicios: string[]`, `fase` (debe existir; actividad «Fase cambiada a X»). Email validado. → `{contacto}`. |
| DELETE | `/contactos/{id}` | `crm.borrar` | A la papelera con **todo** lo suyo (etiquetas, actividad, comentarios, facturación, propuestas, adjuntos, listas, seguimientos, reuniones, negocios y sus etiquetas) → `{papelera_id}`. |
| POST | `/papelera/{id}/restaurar` | quien borró o `papelera.restaurar` | Deshacer el borrado de un contacto o un negocio → `{tipo, id}`. |
| POST | `/contactos/lote` | según `op` | `{ids[≤1000], op}`: `asignar {propietario_id}` · `etiquetar {tag_id}` · `a_lista {list_id}` · `nueva_lista {nombre}` (estática) · `borrar` (cada uno a la papelera) → `{n, list_id?, papelera_ids?}`. Los ids fuera de alcance se ignoran. |
| GET | `/contactos/exportar` | `ver.crm` | `?ids=1,2` o los filtros → `{nombre, csv}` (UTF-8 con BOM; celdas que empiezan por `= + - @` neutralizadas con `'`). |
| GET | `/contactos/plantilla` | `ver.crm` | `{nombre, csv}` plantilla de importación. |
| POST | `/contactos/importar` | `crm.crear` | multipart: `csv` (≤ 2 MB, ≤ 5.000 filas), `prueba=1` (solo valida), `mapeo` (JSON `{columna: campo}`), `omitir_duplicados=1`. → `{cabecera, mapeo, separador, total_filas, validas, a_importar, omitidas[], duplicados[], avisos[], muestra[], insertados, n_*}`. Importa en una transacción. |
| PATCH | `/contactos/{id}/facturacion` | `crm.editar` | Campos de facturación (email e IBAN validados) → `{facturacion}`. |
| POST / DELETE | `/contactos/{id}/etiquetas/{tag}` | `crm.editar` | Poner / quitar → `{contacto}`. |
| POST | `/contactos/{id}/comentarios` | `crm.crear` | `{tipo: nota\|llamada\|whatsapp\|email\|reunion, contenido}` → 201 ficha. Interacciones ponen «último contacto» = hoy. **Avisa a los @mencionados** (`notif_add`, url `/crm/contactos/<id>`). |
| DELETE | `/comentarios/{id}` | autor o `admin.total` | → ficha. |
| POST | `/contactos/{id}/actividad` | `crm.editar` | `{tipo, descripcion}`: botones Email/Llamar/WhatsApp de la ficha. |
| POST | `/contactos/{id}/propuestas` | `crm.crear` | `{nombre, importe, estado, fecha_envio, url_archivo (http/https)}` → 201 `{propuestas}`. |
| PATCH / DELETE | `/propuestas/{id}` | `crm.editar` / `crm.borrar` | Editar (estado, url…) / borrar → `{propuestas}`. |
| POST | `/contactos/{id}/adjuntos` | `crm.crear` | multipart `archivo` (lista blanca de extensiones, ≤ 25 MB, nombre aleatorio en `uploads/crm/`, imágenes reducidas) → 201 `{adjuntos}`. Se descargan por `archivo.php?d=crm&f=…` (con sesión y `ver.crm`). |
| DELETE | `/adjuntos/{id}` | `crm.borrar` | Borra fila y fichero → `{adjuntos}`. |
| POST | `/contactos/{id}/convertir` | `crm.convertir` + `ver.clientes` | Lead → cliente con `ClientesServicio::crear` (validación, 4 listas, `notif_client_new`) y enlaza `clients.contact_id`, `contacts.client_id`, `deals.client_id` → `{ya, cliente_id, usuario, password, msg}` (contraseña **una sola vez**). Si ya era cliente: `ya:true`. |

## Negocios

**Negocio**: `{id, contact_id, contacto{nombre,empresa,sector}, nombre, valor, servicio, fase, tipo_fase, probabilidad, fecha_cierre_prevista,
fecha_entrada_fase, dias_en_fase (solo abiertas), fecha_cierre_real, motivo_perdida, motivo_perdida_txt, fecha_reactivacion, propietario_id,
archivado, client_id, invoice_id, etiquetas[]}`.

| Método | Ruta | Permiso | Qué hace |
|---|---|---|---|
| GET | `/negocios` | `ver.crm` | `?archivados=1` → `{items, metricas\|null}`. Métricas (solo activos): `abiertos, valor_pipeline` (no archivados) · `ganado_mes, n_ganado_mes, conversion, ticket_medio, n_ganados, n_perdidos` (archivados incluidos). |
| POST | `/negocios` | `crm.crear` | `{contact_id*, nombre?, valor?, fecha_cierre_prevista?}` → 201 `{negocio}` (fase `lead_nuevo`, servicio y propietario del contacto). |
| GET | `/negocios/{id}` | `ver.crm` | `{negocio}`. |
| PATCH | `/negocios/{id}` | `crm.editar` | `nombre, valor, servicio, fecha_cierre_prevista, propietario_id, archivado` (desarchivar incluido) → `{negocio}`. |
| POST | `/negocios/{id}/mover` | `crm.editar` | `{fase, indice?}` (posición en la columna). A una fase de tipo perdida exige `motivo` (422 `motivo`); con `{motivo, comentario}` la marca perdida. Ganada/perdida ponen cierre real; volver a abierta lo quita y anula la reactivación. Sincroniza la fase del contacto. |
| POST | `/negocios/{id}/perder` | `crm.editar` | `{motivo*, comentario}` → reactivación = hoy + meses del motivo. |
| DELETE | `/negocios/{id}` | `crm.borrar` | Papelera (con etiquetas y seguimientos) → `{papelera_id}`. |
| POST / DELETE | `/negocios/{id}/etiquetas/{tag}` | `crm.editar` | → `{negocio}`. |
| POST | `/negocios/{id}/convertir` | `crm.convertir` | Convierte su contacto (como arriba). |

Factura desde un negocio: **no hay endpoint aquí**. El front navega a `/finanzas/facturas/nueva?negocio=<id>` y Finanzas
prellena el borrador (`GET /v1/finanzas/facturas/desde-negocio/{id}`) y guarda el vínculo en `invoices.deal_id`; el `invoice_id` del negocio se lee de ahí. `pu_negocio_a_factura()` ya no crea nada.

## Listas

| Método | Ruta | Permiso | Qué hace |
|---|---|---|---|
| GET | `/listas` | `ver.crm` | `{listas:[{id,nombre,descripcion,tipo:activa\|estatica,condiciones,fecha_creacion,fecha_congelado,n}]}` en su orden. |
| POST | `/listas` | `crm.crear` | `{nombre*, descripcion, tipo: activa\|estatica\|manual, condiciones{q,sector,origen,fase,servicio,prop,vmin,vmax,quick:sin_contactar\|act30}, ids[] (manual, ≥1)}` → 201 detalle. |
| GET | `/listas/{id}` | `ver.crm` | `{lista, miembros:[ContactoFila + forzado]}` (activa = condiciones ∪ añadidos a mano; respeta el alcance). |
| PATCH | `/listas/{id}` | `crm.editar` | `{nombre?, descripcion?}`. |
| DELETE | `/listas/{id}` | `crm.borrar` | Borra la lista y sus miembros (sin papelera, como antes). |
| POST | `/listas/{id}/congelar` | `crm.editar` | Activa → estática con todos sus contactos actuales. |
| POST / DELETE | `/listas/{id}/miembros/{contacto}` | `crm.editar` | Añadir / quitar a mano. |
| POST | `/listas/orden` | `crm.editar` | `{ids}` orden del menú lateral. |
| GET | `/listas/{id}/exportar` | `ver.crm` | `{nombre, csv}`. |

## Dashboard, seguimientos y resumen diario

| Método | Ruta | Permiso | Qué hace |
|---|---|---|---|
| GET | `/dashboard` | `ver.crm` | `?desde&hasta` → `{rango, kpis{contactos,hay_rango,abiertos,valor_pipeline,ganado,n_ganados,conversion,n_perdidos,ticket_medio,cierres_previstos}, meses[], series{embudo, valor_fase, ganados_perdidos, conversion_mes, valor_ganado_mes, contactos_mes, origen, sector, motivos, propietarios, servicios, fase_contactos, dias_fase}}`; cada serie `{labels, series[{label,data,color}]}`. |
| GET | `/seguimientos` | `ver.crm` | `{hoy, grupos[{canal,titulo,color,items[Seguimiento]}], pendientes_hoy, hechos_hoy, proximos[40], ultimo_resumen, cron{estado,ultima,tareas[],linea}}`. |
| POST | `/seguimientos` | `crm.crear` | Manual `{contact_id, canal, descripcion, fecha}` → bandeja. |
| POST | `/seguimientos/{id}/hecho` · `/posponer` `{dias 1-90}` · `/omitir` | `crm.editar` | 409 si ya no está pendiente. «Hecho» guarda `fecha_hecha`, anota la interacción (`llamada`, no `llamar`) y pone «último contacto». |
| POST | `/seguimientos/generar` | `crm.editar` | Reglas A/B/C → `{creados, …bandeja}`. No repite un seguimiento que ya existió (aunque se omitiera) en el mismo ciclo. |
| GET | `/resumen-diario` | `ver.crm` | Datos de la vista previa (no genera nada). |
| POST | `/resumen-diario/ejecutar` | `crm.editar` | Forzado: genera, avisa (notificación `bell` → `/crm/reporting`) y manda **correo** (`Correo\Fabrica`) a quien tiene `admin.total` y email; anota `email_log` → `{resultado{sent,acciones,reason,correos}, …bandeja}`. |

**Cron**: `Croilab\Modulos\Crm\Cron::ejecutar(PDO): array` hace `followups`, `daily_digest` (L–V, una vez al día) y
`lead_reminder` (`notif_sync_leads`), cada una si `settings.auto_<clave>` ≠ `'0'`; devuelve `[clave => ['ok'=>bool,'detalle'=>string]]`.
