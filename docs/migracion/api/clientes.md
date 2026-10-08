# API · Clientes

Prefijo `/api/v1`. Convenciones de `backend/API.md`: JSON `{ok:true, …}`, CSRF (`X-CSRF-Token`) en POST/PATCH/DELETE,
errores `{ok:false, msg, error}` con `403 permiso`, `404 no_encontrado` (también **fuera de alcance**), `422 validacion` + `campo`.
Escribir exige siempre `general.editar` además del permiso concreto. Importes en **céntimos** (enteros); sin `ver.importes` llegan `null`.

Código: `backend/src/Modulos/Clientes/*`, rutas en `backend/api/rutas/clientes.php`, tests en `backend/tests/{Unit,Integracion}/Clientes`.

## Clientes

| Método | Ruta | Permiso | Qué hace |
|---|---|---|---|
| GET | `/clientes` | `ver.clientes` | Listado paginado (`limit` ≤ 200, `offset`). Filtros: `q` (prefijo del nombre), `activo=0\|1`, `tipo=<id>\|none`, `con_listas=1`, `actividad=1`. |
| GET | `/clientes/{id}` | sesión + alcance | Forma corta con sus listas (la usan Tareas y la barra lateral). Sin cambios. |
| GET | `/clientes/{id}/ficha` | `ver.clientes` | Hub completo (ver abajo). |
| GET | `/clientes/{id}/ficha/secreto/{cred}` | `ver.clientes` + `ver.credenciales` | `{secreto}` de una credencial del cliente, bajo demanda y auditado. Descifra la bóveda (propósito `cred_cliente`) si está cifrada. |
| GET | `/clientes/{id}/datos` | `ver.clientes` | `{cliente}` con todo lo que edita el formulario. |
| POST | `/clientes` | `clientes.crear` | Alta → `201 {id}`. Crea las 4 listas (TAREAS, ESTRATEGIA, TAREA CLIENTE, INFORMES CLIENTE) y llama a `notif_client_new`. |
| PATCH | `/clientes/{id}` | `clientes.editar` (+ `clientes.portal` si cambia estado/plan/accesos/tareas) | Edición parcial → `{cliente}` (como `/datos`). |
| DELETE | `/clientes/{id}` | `clientes.borrar` | A la papelera → `{papelera_id}`. |
| POST | `/clientes/papelera/{id}/restaurar` | `general.editar` (+ `papelera.restaurar` si lo borró otra persona) | «Deshacer» de un borrado de cliente → `{id}`. 404 si no es un cliente o ya no está. |
| POST | `/clientes/{id}/duplicar` | `clientes.crear` | Copia → `201 {id, password}` (contraseña nueva, se enseña una vez). |
| POST | `/clientes/{id}/password` | `clientes.editar` | Contraseña nueva del portal → `{password}` (una vez). Sube `cred_ver`: cierra sus sesiones. |
| POST | `/clientes/{id}/informe` | `tareas.crear` | (Ya existía) lista de informes del cliente, creada si falta. |

### Listado con `actividad=1`

Cada item: `id, name, iniciales, activo, username, conversiones, tipo_id, tipo_nombre, partner_id` y además
`tareas_abiertas, tickets_abiertos (abierto|en_curso|esperando), pendiente_cobro` (céntimos de facturas no borrador ni pagadas; `null` sin `ver.importes`).

### Cuerpo de POST / PATCH `/clientes`

```json
{ "name": "…", "username": "…", "password": "…", "saludo": "", "iniciales": "LE", "actual": "Junio",
  "login_email": "", "tipo_id": 3, "conversiones": true, "activo": true,
  "fact_nombre": "", "fact_nif": "", "fact_dir": "", "fact_email": "",
  "estado": {"nombre": "", "etiqueta": "", "siguiente": "", "fases": [{"t": "Auditoría", "s": "y arranque", "estado": "done|now|"}]},
  "plan": {"resumen": "", "items": [{"n": "4", "t": "Artículos"}], "detalle": [{"h": "", "p": ""}]},
  "accesos": [{"b": "Figma", "s": "", "u": "https://…", "tipo": "figma|drive|web|looker|generic"}],
  "tareas": [{"mes": "Junio", "completado": [{"t": "", "d": ""}], "pendiente": [{"t": "", "d": ""}]}] }
```

Alta: `name`, `username` y `password` (≥ 6) obligatorios. PATCH: solo se toca lo que llega; `password` vacía o ausente = no cambiar.
Validación (422 + `campo`): usuario único (sin distinguir mayúsculas, sin espacios), correo de Google único y en minúsculas,
emails válidos, tipo existente, longitudes, fases con estado válido, enlaces de accesos solo `http(s)` (vacío se guarda `#` como el antiguo),
filas vacías descartadas. **Nunca** toca `met_json`, `informes_json`, `servicios_json`, `looker_url`, `partner_id` ni `ga4_*`.

### Ficha (`GET /clientes/{id}/ficha`)

```
cliente:  {id, name, username, iniciales, saludo, conversiones, activo, actual, tipo_id, tipo_nombre, login_email,
           partner_id, contact_id, google(bool), fact:{nombre,nif,dir,email,tel}, faltan_fiscales}
estado, plan            (mismo formato que el cuerpo)
resumen:  {oportunidades:{mes,valor}|null, tareas_en_curso, soporte_abierto, cobrado}
listas:   [{id, nombre, tipo, es_cliente, cnt, pend}]
facturas: {n, cobrado, pendiente, ultimas:[{id, numero, fecha, estado, total}]}   (5 últimas)
tickets:  {abiertos, items:[{id, asunto, estado, prioridad 1-4, fecha}]}            (4)
credenciales: {total, items:[{id, titulo, categoria, usuario, url, tiene_secreto}]} | null sin ver.credenciales  (6, sin secreto)
contacto: {id, nombre, empresa, email, telefono, whatsapp, origen_lead} | null
reuniones: {proximas:[{id, fecha, hora, titulo, estado}], solicitudes}              (crm_meetings del contacto + solicitudes del portal)
```

Totales de factura: línea = `round(q·p, 2)` en MySQL, base = Σ, IVA/IRPF = `round(base·%)`, total = base + IVA − IRPF (entero en céntimos, `Modulos/Clientes/Importes.php`).

### Borrado

En una transacción: comentarios/checklist/adjuntos/reacciones de sus tareas se borran (como antes); `tasks, task_lists,
client_credentials, support_tickets, invoice_schedules, projects, portal_meeting_requests` van a la papelera con el cliente;
las horas reciben un concepto legible y sueltan cliente y tarea; `invoices` (con `cliente_nombre` asegurado), `accounting`,
`contacts` y `deals` sueltan `client_id`. Las respuestas de los tickets se quedan (vuelven con el ticket). Las referencias soltadas
se anotan en `trash.datos.refs` y `POST /clientes/papelera/{id}/restaurar` las vuelve a enlazar.

## Tipos de cliente

| Método | Ruta | Permiso | Qué hace |
|---|---|---|---|
| GET | `/clientes/tipos` | `ver.ajustes` o `ver.clientes` | `{items:[{id, nombre, secciones:{metricas,progreso,informes,como,accesos,plan}, uso}]}` |
| GET | `/clientes/tipos/{id}` | ídem | `{tipo}` |
| POST | `/clientes/tipos` | `tipos.editar` | `{nombre, secciones?, rapido?}` → `201 {tipo, dup:false}`; con `rapido:true` y nombre existente → `200 {tipo, dup:true}`. Sin `secciones`, todas a 1. |
| PATCH | `/clientes/tipos/{id}` | `tipos.editar` | `{nombre?, secciones?}` (las secciones que no lleguen se apagan) |
| DELETE | `/clientes/tipos/{id}` | `tipos.editar` | Sus clientes se quedan sin tipo → `{sin_tipo:n}`. Sin papelera. |

## Servicios (catálogo)

| Método | Ruta | Permiso | Qué hace |
|---|---|---|---|
| GET | `/clientes/servicios` | `ver.ajustes` o `ver.clientes` | `{servicios:[{nombre, desc, video(bool), uso}], abiertos, total}` (`uso` incluye a los clientes sin lista, que ven todos) |
| PATCH | `/clientes/servicios` | `servicios.editar` | `{servicios:[{nombre, desc, orig}]}` (catálogo entero; `orig` = nombre al cargar, '' en filas nuevas) → lo mismo que GET + `{renombrados, clientes}`. Renombra/quita en `clients.servicios_json`; conserva el vídeo al renombrar. Nombres únicos, al menos uno. |

## Marca blanca

| Método | Ruta | Permiso | Qué hace |
|---|---|---|---|
| GET | `/clientes/agencias` | `ver.ajustes` | `{agencias:[{id, nombre, color, logo_url, web, email, whatsapp, meeting_url, telefono, uso}], clientes:[{id, name, iniciales, partner_id}] (con alcance), casa}` |
| POST | `/clientes/agencias` | `marca.editar` | Alta → `201 {agencia}` |
| PATCH | `/clientes/agencias/{id}` | `marca.editar` | Edición → `{agencia}` |
| DELETE | `/clientes/agencias/{id}` | `marca.editar` | Sus clientes vuelven a la casa |
| PATCH | `/clientes/{id}/agencia` | `marca.editar` + alcance | `{partner_id: id\|null}` |

Validación: nombre obligatorio, color `#rgb/#rrggbb`, logo y enlace de reunión solo `https?://`, email válido, WhatsApp solo dígitos.

## Métricas de Google de un cliente

| Método | Ruta | Permiso | Qué hace |
|---|---|---|---|
| GET | `/clientes/{id}/google` | `ver.clientes` | `{cliente:{id,name,conversiones}, site, prop, eventos:{ll,wa,fo}, por_defecto:{ll,wa,fo}, conectado, sync_at, meses:[{mes,ll,wa,fo,vi,ap,ctr,total}]}` |
| PATCH | `/clientes/{id}/google` | `clientes.editar` | `{site?, prop?, eventos?:{ll:[],wa:[],fo:[]}}`. `site` = `https://…` o `sc-domain:…`; `prop` solo dígitos; eventos `[A-Za-z][A-Za-z0-9_]*`, cada uno en un solo tipo. |
| GET | `/clientes/{id}/google/eventos?prop=` | `ver.clientes` | Eventos de GA4 de 90 días `{eventos:[{name,n}]}`. **409 `google_sin_conectar`** (sin conectar o sin credenciales) o **409 `google_revocado`**; 502 `google` si Google falla. |
| POST | `/clientes/{id}/google/sync` | `clientes.editar` | Trae mes actual y anterior → GET + `{msg}` («Con avisos · …» si alguna fuente falló pero el resto se guardó). 409/502 igual. |

Google va por `Croilab\Google\Metricas` sobre la instancia compartida `$c->unico('google', fn() => new GoogleOAuth())` (tokens y secretos descifrados de la bóveda); ya no se usa `admin/lib/google_metrics.php`.

## Datos avanzados (data.php)

La contraseña se confirma con la reautenticación común de Equipo: `POST /v1/auth/reconfirmar {password, zona:"datos"}`
(30 min). En el front, `useReauth` de `features/equipo`.

| Método | Ruta | Permiso | Qué hace |
|---|---|---|---|
| GET | `/clientes/{id}/avanzado` | `ver.clientes` + `datos.avanzado` + zona `datos` confirmada | `{datos:{id,name, estado_json…servicios_json (texto bonito), looker_url, fact_tel, orden}}`. **403 `{error:"reauth", zona:"datos"}`** si no se ha confirmado (`Reautenticacion::exigir('datos')`). |
| PATCH | `/clientes/{id}/avanzado` | ídem + `general.editar` | Campos presentes. Cada bloque tiene que ser JSON válido con su raíz (objeto/lista) y los que tienen pantalla pasan por su validador; vacío = NULL. |
| POST | `/clientes/avanzado/bloquear` | sesión | Cierra la zona `datos` («Bloquear otra vez»). |
