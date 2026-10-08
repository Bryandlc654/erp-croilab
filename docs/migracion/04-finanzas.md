# 04 · Finanzas — especificación del legado (copia-erp) para la migración a React + API

Ámbito: `admin/facturas.php`, `admin/programaciones.php`, `admin/contabilidad.php`, `admin/contabilidad-analisis.php`,
`admin/fin-horas.php`, `admin/fin-resumen.php`, `admin/fin-ajustes.php`, `factura.php` (portal), `admin/cron.php`,
`admin/lib/cron_lib.php`, `admin/lib/fin_prog.php`, `admin/lib/puentes.php`. Se citan también las piezas que
estas pantallas usan de otros sitios (`settings.php › Facturación`, `lib/permisos.php`, `erp_nav.php`,
`lib/proyectos_lib.php`, `lib/papelera.php`, `auth.php::num_es`, `negocio.php`, `task.php`, `index.php` del portal).

Fuentes leídas: todo el código de los archivos anteriores. Las referencias `archivo:línea` son de `copia-erp/`.
Estado del backend nuevo (`backend/`): **no existe ningún endpoint de finanzas**; `backend/api/rutas.php` solo tiene
auth, nav, equipo, clientes y tareas. `backend/admin/lib/fin_prog.php` es la misma librería con dos cambios: los
`*_ensure()` no hacen nada si las migraciones están al día, y el contador usa
`INSERT … ON DUPLICATE KEY UPDATE ultimo=LAST_INSERT_ID(ultimo+1)` (atómico de verdad en MySQL).

---

## 0. Conceptos clave del dominio (leer antes que nada)

| Concepto | Qué es en el legado |
|---|---|
| **Emisor** | Autónomo que factura (no una empresa). Lista configurable en `settings.emisores_lista` (por defecto `victor`=«Víctor», `gavi`=«Gabi»). La **clave** (≤15 car.) es inmutable y se graba en `invoices.emisor`, `invoice_schedules.emisor`, `invoice_uploads.emisor` y `accounting.ambito`. |
| **Ámbito contable** | `accounting.ambito` ∈ {`empresa`} ∪ claves de emisor. «Hub» = vista `empresa` = todos los apuntes con `personal=0` (de cualquier ámbito). |
| **Criterio de caja** | `accounting` solo contiene lo **cobrado/pagado**. Una factura emitida apunta su ingreso únicamente cuando pasa a `pagada` (y se retira si deja de estarlo). |
| **Criterio de devengo** | `fin-resumen.php` suma **todas** las facturas por su `fecha` (cobradas o no). Por eso Contabilidad y Resumen no cuadran (se avisa en pantalla). |
| **Efectivo («en B»)** | `invoices.efectivo=1`: IVA e IRPF forzados a 0, forma de pago «Efectivo», apunte con `metodo='efectivo'`, `legal=0`. Texto en la factura: «Operación cobrada en efectivo. Factura sin IVA.» |
| **Personal** | Factura/ingreso que no cuenta en la contabilidad de empresa (`personal=1` → excluido del Hub). En gastos subidos, la casilla significa «Deducible — me lo desgravo (gasto del socio)» y pone `personal=1` **y** `deducible=1`. |
| **Snapshot del emisor** | Al **crear** una factura se guarda `invoices.emisor_json` con los datos fiscales del emisor de ese momento (no retroactivo). |
| **Serie / numeración** | Por defecto `{PREFIJO}-{AÑO}-{NNN}` (p. ej. `V-2026-001`), con contador atómico en `invoice_counters`. Serie libre opcional. |
| **Subidas** | Documentos (PDF/imagen) de gastos o ingresos externos (`invoice_uploads`), que crean su propio apunte en `accounting` al instante (no pasan por «pagada»). |

---

## 1. Permisos

Catálogo (`admin/lib/permisos.php:43-116`):

| Permiso | Etiqueta | Descripción | Requisitos (`perm_requisitos`) | Herencia al migrar |
|---|---|---|---|---|
| `ver.finanzas` | Facturas | «Facturas, programaciones y resumen mensual.» | — | — |
| `ver.conta` | Contabilidad | «Las cuentas y el análisis. Va aparte: se puede facturar sin ver la contabilidad.» | — | — |
| `ver.horas` | Horas del equipo | «Cuántas horas echa cada uno y en qué.» | — | de `ver.finanzas` |
| `tareas.horas` | Imputar horas | «Apuntar el tiempo dedicado, que va a la contabilidad.» | `ver.horas`, `general.editar` | de `general.editar` |
| `finanzas.emitir` | Emitir facturas | «Crear facturas y enviarlas.» | `ver.finanzas`, `general.editar` | — |
| `finanzas.cobrar` | Marcar cobros | «Dar una factura por pagada. Es lo que mueve la contabilidad.» | idem | de `finanzas.emitir` |
| `finanzas.borrar` | Borrar facturas | «Eliminar facturas emitidas.» | idem | de `finanzas.emitir` |
| `finanzas.programar` | Facturas recurrentes | «Crear y cambiar las que se emiten solas cada mes.» | idem | de `finanzas.emitir` |
| `conta.editar` | Tocar la contabilidad | «Añadir o cambiar apuntes de gastos e ingresos.» | `ver.conta`, `general.editar` | de `general.editar` |
| `finanzas.emisores` | Datos fiscales propios | «El NIF y el IBAN con los que facturáis, y quién factura.» | `ver.ajustes`, `general.editar` | de `ajustes.editar` (es permiso de **configuración**) |
| `ver.importes` | Ve las cantidades de dinero | Tapa con `·····` (`eur_vis()`) los euros que aparecen «de pasada» (panel, ficha de cliente, listado). No da acceso a Finanzas. | — | todos |

Aplicación real en el legado:

- **Por pantalla** (`perm_de_pagina`, aplicado en `require_admin()`): `facturas.php`, `programaciones.php`, `fin-resumen.php`,
  `fin-ajustes.php`, `pricing.php` → `ver.finanzas`; `contabilidad.php`, `contabilidad-analisis.php` → `ver.conta`;
  `fin-horas.php` → `ver.horas` (la clave aparece dos veces en el array literal; gana la última, `ver.horas`).
- **Por acción POST** (`perm_de_accion`, por `$_POST['action']`):
  - `facturas.php`: `save`→`finanzas.emitir`, `del`→`finanzas.borrar`, `estado`/`pagar`→`finanzas.cobrar`.
    **Ojo:** la acción real se llama `set_estado`, que **no está mapeada** → marcar pagada solo exige `general.editar`.
    Tampoco están mapeadas `dup`, `set_project`, `save_cli_fiscal`, `upload_doc`, `del_doc` (solo `can_edit()`).
    Además `save` con `estado=pagada` cobra sin pasar por `finanzas.cobrar`.
  - `programaciones.php`: `*`→`finanzas.programar` (incluye «Generar ahora» `run`).
  - `contabilidad.php`: `*`→`conta.editar` (pero la pantalla no tiene ningún POST: es de solo lectura).
  - `fin-horas.php`: `*`→`tareas.horas`. Dentro, `$mgr = can_edit()` decide «gestor» (ve a todos, cambia tarifas, vuelca a gasto).
  - `settings.php`: `section=emisores|em_del`→`finanzas.emisores`, **pero el código exige además `is_owner()`** (= `admin.total`).
- Todas las escrituras exigen `can_edit()` (= `general.editar`). El rol «Solo lectura» ve las pantallas sin botones de acción.
- **Alcance de clientes** (`alcance.todos`): solo se aplica en el hub «Facturas por cliente» (`alcance_sql('i.client_id')`)
  y en la previsualización de `factura.php?cli=`. **No** se aplica en el detalle `?cli=ID`, ni en los hubs por emisor,
  ni en contabilidad/resumen (fuga de alcance a corregir en la API).
- Menú lateral Finanzas (`erp_nav.php:1767-1813`) visible con `ver.finanzas`. Grupos: **General** (Resumen mensual,
  Proyectos, Horas equipo, Calculadora de precios) · **Facturas** (Todas, una entrada por emisor `facturas.php?em=k`,
  Por cliente, Programaciones) · **Contabilidad** (Hub, una por emisor, «Análisis / gráficas») · **Ajustes**
  («Facturación de clientes» → `fin-ajustes.php`).

Recomendación para la API: mapear explícitamente
`POST /facturas`/`PATCH` → `finanzas.emitir`; cambio de estado a/desde `pagada` → `finanzas.cobrar`;
`DELETE` → `finanzas.borrar`; programaciones → `finanzas.programar`; subidas de gastos/ingresos → `conta.editar`
(crean apuntes contables); emisores → `finanzas.emisores` (+ decidir si se mantiene `admin.total`); horas → `tareas.horas`
(y «gestor» = `finanzas.emitir` o un permiso nuevo `horas.gestionar`, en vez de `general.editar`).

---

## 2. Modelo de datos

El esquema se crea «al vuelo» con `CREATE TABLE IF NOT EXISTS` + `ALTER TABLE ADD COLUMN` en cada carga
(`facturas.php:13-43`, `fin_prog.php::prog_ensure`, `fin_counters_ensure`, `erp_nav.php::ensure_time_schema`,
`puentes.php::ensure_puentes_schema`). En el backend nuevo las crea la migración `0002_modulos.php`, **salvo**
`invoice_uploads`, `projects` y `time_entries`, que solo nacen en `facturas.php`/`proyectos_lib`/`erp_nav.php`
(y `time_entries.acc_id` no se añade si la tabla no existe todavía) → **hay que añadir una migración** para ellas.

### 2.1 `invoices` (facturas emitidas)

| Columna | Tipo | Default | Notas |
|---|---|---|---|
| `id` | INT PK AI | | |
| `numero` | VARCHAR(30) | | Número completo con serie. Índice **UNIQUE `uq_invoices_numero`** (se intenta crear; si hay duplicados previos, falla en silencio). Editable a mano. |
| `emisor` | VARCHAR(15) NOT NULL | `'victor'` | Clave de emisor. |
| `emisor_json` | TEXT | | Snapshot `fin_emisor_data()` al crear: `{key,name,nif,dir,email,phone,iban,banco,iva,irpf,venc}`. No se actualiza al editar (aunque cambie el emisor). |
| `client_id` | INT NULL | | FK lógica a `clients.id`. |
| `cliente_nombre` | VARCHAR(200) | | Si llega vacío y hay `client_id`, se rellena con `clients.name`. |
| `cliente_nif` | VARCHAR(40) | `''` | |
| `cliente_dir` | VARCHAR(300) | `''` | |
| `cliente_email` | VARCHAR(160) | `''` | |
| `cliente_tel` | VARCHAR(40) NOT NULL | `''` | |
| `fecha` | DATE | | Fecha de expedición. Por defecto hoy. Determina el año del número. |
| `fecha_venc` | DATE NULL | | Vencimiento (opcional). Lo usa el cron para pasar a `vencida`. |
| `periodo_ini`, `periodo_fin` | DATE NULL | | Período facturado (chip en la factura). |
| `cond_pago` | VARCHAR(60) NOT NULL | `'Contado'` | Texto libre «Vencimiento (texto)». |
| `estado` | VARCHAR(15) | `'borrador'` | `borrador` · `enviada` · `pagada` · `vencida`. |
| `fecha_pago` | DATE NULL | | Fecha de cobro (= fecha de caja). Se fija a hoy al pasar a pagada si estaba vacía; se pone NULL en cualquier otro estado. No hay UI para elegirla. |
| `iva_pct` | DECIMAL(5,2) | 21 | |
| `irpf_pct` | DECIMAL(5,2) | 0 | |
| `efectivo` | TINYINT NOT NULL | 0 | Fuerza IVA/IRPF = 0 en servidor. |
| `personal` | TINYINT NOT NULL | 0 | Excluida del Hub y del resumen «empresa». |
| `notas` | TEXT | | Se imprimen al pie. Las de programación: «Generada por programación mensual». |
| `project_id` | INT NULL | | Proyecto interno (no sale en la factura). |
| `created_at` | TIMESTAMP | now | |

Colores/etiquetas de estado (`facturas.php:56`): `borrador` «Borrador» `#9aa0a8` · `enviada` «Enviada» `#3b82f6` ·
`pagada` «Pagada» `#12a150` · `vencida` «Vencida» `#ef4444` (en `fin-resumen` hay una variante: `#98a2b3`, `#2f6df6`, `#12854a`, `#e5484d`).

### 2.2 `invoice_items` (líneas)

`id`, `invoice_id` (INDEX), `concepto` VARCHAR(300), `cantidad` DECIMAL(10,2) default 1, `precio` DECIMAL(12,2) default 0.
Sin IVA por línea, sin descuento, sin orden explícito (se ordena por `id`). Al guardar se **borran todas y se reinsertan**.
Se descartan las líneas con concepto vacío. Importe de línea = `cantidad*precio` (no se guarda).

### 2.3 `invoice_counters` (numeración)

`serie` VARCHAR(60) PK, `ultimo` INT default 0. Clave = prefijo completo **sin** el número (p. ej. `V-2026-`, o la serie libre tal cual).

### 2.4 `invoice_schedules` (programaciones / recurrentes)

`id`, `emisor` VARCHAR(15) default `'victor'`, `serie` VARCHAR(20) NOT NULL default `''`, `client_id`, `cliente_nombre`(200),
`cliente_nif`(40), `cliente_dir`(300), `cliente_email`(160), `cliente_tel`(40), `lineas_json` MEDIUMTEXT
(`[{c:concepto,q:cantidad,p:precio}]`), `iva_pct` (21), `irpf_pct` (0; el formulario nuevo propone **7**), `cond_pago` ('Contado'),
`dia` TINYINT 1..28, `activo` TINYINT (1), `start_ym` VARCHAR(7) `YYYY-MM`, `last_ym` VARCHAR(7) (último mes emitido), `created_at`.
No hay vínculo factura→programación (las facturas generadas no guardan `schedule_id`).

### 2.5 `invoice_uploads` (documentos subidos: gastos e ingresos externos)

`id`, `emisor` VARCHAR(15), `tipo` VARCHAR(10) `gasto|ingreso`, `concepto`(250), `proveedor`(200; en ingresos = «Cliente»),
`importe` DECIMAL(12,2) (total tal cual, sin desglose de IVA), `fecha` DATE, `filename` (nombre aleatorio `hex16.ext` en
`uploads/facturas/`), `orig_name`, `mime`, `acc_id` (→ `accounting.id`), `efectivo`, `personal`, `created_at`. INDEX(emisor), INDEX(tipo).
Archivos servidos por `archivo.php?d=facturas&f=…[&dl=1]`. El barrido de huérfanos de la papelera los conoce (`papelera.php:271`).

### 2.6 `accounting` (caja: apuntes)

| Columna | Tipo / default | Uso |
|---|---|---|
| `id`, `created_at` | | |
| `fecha` | DATE | Fecha de caja. |
| `tipo` | VARCHAR(10) `'gasto'` | `ingreso` / `gasto`. |
| `concepto` | VARCHAR(250) | Facturas: `Factura {numero} · {cliente_nombre}`. Subidas: `{concepto|Gasto|Ingreso}[ · {proveedor}]`. Horas: `Horas {usuario} · dd/mm/aaaa – dd/mm/aaaa`. |
| `categoria` | VARCHAR(80) | `Cliente` (ingresos), `Gasto` (gastos subidos), `Equipo` (horas). No hay catálogo ni UI para cambiarla. |
| `importe` | DECIMAL(12,2) | Siempre positivo; el signo lo da `tipo`. En facturas = total (base+IVA−IRPF). |
| `metodo` | VARCHAR(20) `'transferencia'` | `efectivo`·`transferencia`·`tarjeta`·`bizum`·`domiciliado` (solo se generan los dos primeros). |
| `legal` | TINYINT 1 | 0 si efectivo. |
| `ambito` | VARCHAR(15) `'empresa'` | Clave de emisor o `empresa`. |
| `deducible` | TINYINT 0 | 1 en gastos subidos marcados «Deducible» y en gastos de horas. |
| `personal` | TINYINT 0 | Migración única: `UPDATE accounting SET personal=1 WHERE deducible=1` (flag `acc_personal_migrated`). |
| `client_id` | INT NULL | Facturas: el de la factura. Horas: el cliente si todas las horas son de uno. |
| `invoice_id` | INT NULL | Apunte generado por una factura (1 como máximo). |
| `project_id` | INT NULL | |
| `admin_id` | INT NULL | Persona cuyas horas originan el gasto. |
| `notas` | VARCHAR(300) | `Subida en Facturas`, `{h} h del equipo`. |

### 2.7 `time_entries` (horas)

`id`, `admin_id` NOT NULL, `task_id` NULL, `client_id` NULL, `fecha` DATE NOT NULL, `minutos` INT default 0,
`importe` DECIMAL(10,2) NULL (extra en euros), `concepto` VARCHAR(255), `created_at`, `acc_id` INT NULL (ya volcada a un gasto).
Origen: `task.php` (campo «Tiempo» de la tarea: una línea por persona y tarea con `concepto='Horas de la tarea'`,
se reemplaza al corregir) y `fin-horas.php` (extras manuales, sin tarea ni cliente).

Columnas en `admins`: `es_autonomo` TINYINT, `tarifa_hora` DECIMAL(10,2), `iva_pct` DECIMAL(5,2), `irpf_pct` DECIMAL(5,2).

### 2.8 Otras tablas/columnas tocadas

- `clients`: `fact_nombre`, `fact_nif`, `fact_dir`, `fact_email`, `fact_tel` (datos fiscales que se autorrellenan).
- `billing_data` (CRM, por `contact_id` UNIQUE): `razon_social`, `cif`, `direccion`, `cp`, `ciudad`, `provincia`, `pais`, `email_facturacion`, `iban`.
- `deals.invoice_id`, `deals.client_id`; `projects` (`id`, `nombre`, `color`, `client_id`, `activo`, `created_at`).
- `notifications` (avisos `factura vencida` y `factura cobrada`), `cron_log`, `trash`.

### 2.9 Claves de `settings` usadas

| Clave | Valor | Quién la escribe / lee |
|---|---|---|
| `emisores_lista` | JSON `[{k, nombre}]` (orden = orden de pantalla) | `settings.php` (Ajustes › Facturación) / todo Finanzas |
| `emisor_{k}_name` `_nif` `_dir` `_email` `_phone` `_banco` `_iban` | texto | Ajustes › Facturación / snapshot y vista |
| `emisor_{k}_iva` (def. `21`), `_irpf` (def. `0`), `_venc` (def. `Contado`) | defaults de factura nueva | idem |
| `emisor_{k}_serie` | prefijo (mayúsculas, ≤10). Si falta: inicial del nombre. Nunca se borra si llega vacío. | idem / `fin_next_numero` |
| `emisor_por_defecto` | clave | emisor de las facturas creadas por el sistema (negocio→factura) |
| `serie_{k}` | última serie libre tecleada en el editor para ese emisor | editor de facturas (`save`), `pu_negocio_a_factura` |
| `fin_limpieza_ingresos` | `1` | limpieza única de ingresos no cobrados |
| `acc_personal_migrated` | `1` | migración personal=deducible |
| `auto_invoice_recurring`, `auto_invoice_due` | `1`/`0` (def. `1`) | interruptores del cron |
| `cron_key`, `huerfanos_ultima` | | cron |

---

## 3. Lógica de negocio central (`admin/lib/fin_prog.php`)

### 3.1 Emisores
- `fin_emisores()` → `[clave => nombre]` desde `emisores_lista`; si falta/corrupta → `victor`/`gavi`.
- `fin_emisor_ok($k,$def)` normaliza: clave existente, o `$def`, o la **primera** de la lista.
- `fin_emisor_slug($nombre,$usadas)`: minúsculas ASCII, sin símbolos, ≤15, sufijo numérico si se repite.
- `fin_emisor_serie($k)`: `emisor_{k}_serie` o inicial del nombre en mayúsculas, o `F`.
- `fin_emisor_uso($k)`: nº de facturas, programaciones y apuntes; si `total>0` **no se puede borrar**.
- `fin_emisores_guardar($filas)`: conserva claves existentes, genera clave+prefijo a los nuevos (`emisor_{k}_serie` = inicial), no deja la lista vacía.
- `fin_emisor_data($k)`: `{key, name (fiscal o visible), nif, dir, email, phone, iban, banco, iva='21', irpf='0', venc='Contado'}`.
- Ajustes › Facturación (`settings.php`, `section=emisores`, solo Dueño): guarda los 10 campos por emisor, prefijo
  (solo si no vacío), `emisor_por_defecto`, renombres (`ren[k]`) y altas (`nuevo[]`). `section=em_del&k=`:
  errores «Ese emisor ya no existe.», «Tiene que quedar al menos alguien que facture.»,
  «No se puede quitar a {N}: tiene {n facturas, n programaciones, n apuntes de contabilidad}. Su histórico es contabilidad real y debe poder consultarse.»
  Emisores dados de baja con histórico aparecen como «{clave} (dado de baja)» / «{clave} (baja)».

### 3.2 Numeración — `fin_next_numero($serie, $emisor, $fecha)`
1. `año` = año de `$fecha` (o de hoy).
2. `clave` = `$serie` si no está vacía, si no `fin_emisor_serie(emisor) . '-' . año . '-'`.
3. Si no existe fila en `invoice_counters` para `clave`: se inicializa con el **mayor número final** (`/(\d+)\s*$/`) de
   `invoices.numero LIKE clave%`.
4. Incremento atómico y lectura → `n`. Si algo falla, `n = (int)date('His')` (último recurso).
5. Devuelve `clave . str_pad(n, 3, '0', STR_PAD_LEFT)` → `V-2026-001`, `V-2026-1000`…

Consecuencias: con serie por defecto el contador **se reinicia cada año**; con serie libre (p. ej. `F`) **no** se reinicia
(`F001`, `F002`… para siempre) salvo que el usuario incluya el año. Los borradores **consumen número** al crearse.
No se valida que la fecha sea ≥ la de la factura anterior de la serie.

### 3.3 Contabilización — `fin_sync_accounting($invoiceId)`
Siempre: `DELETE FROM accounting WHERE invoice_id=?`. Si `estado='pagada'`: inserta
`{fecha: fecha_pago ?: fecha, tipo:'ingreso', concepto:'Factura {numero} · {cliente_nombre}', categoria:'Cliente',
importe: base*(1+iva/100−irpf/100), metodo: efectivo?'efectivo':'transferencia', legal: efectivo?0:1, ambito: emisor,
deducible:0, personal, project_id, client_id, invoice_id}`. Errores solo a `error_log`.

`fin_limpiar_ingresos_no_cobrados()`: una sola vez (flag `fin_limpieza_ingresos`) borra los ingresos de facturas no pagadas;
se ejecuta en cada GET de la lista de facturas hasta que el flag existe (migración de datos → mover a migración del backend).

### 3.4 Recurrentes — `prog_run()` / `prog_gen_invoice($s, $ym)`
- Para cada programación `activo=1`: `ym` inicial = mes siguiente a `last_ym`, o `start_ym`, o mes actual.
- Mientras `ym <= mes actual` (máx. 60 iteraciones): si es el mes actual y hoy < `dia` (acotado 1..28) → parar.
  Si no: genera la factura del mes, `last_ym = ym`, siguiente mes. **Recupera meses atrasados** (genera facturas con fechas pasadas).
- `prog_gen_invoice`: `fecha = ym-dia`; número `fin_next_numero(serie, emisor, fecha)`; inserta con `estado='enviada'`,
  `efectivo=0`, `personal=0`, datos del cliente de la programación, `iva/irpf/cond_pago` de la programación, `emisor_json` actual,
  `notas='Generada por programación mensual'`; sin `fecha_venc`, sin período, sin proyecto. Líneas desde `lineas_json`.
  No se apunta ingreso (nace sin cobrar).
- Disparo: cron (`invoice_recurring`) y botón «Generar ahora». Ya **no** se ejecuta al abrir páginas (corrección P1-06).
- Sin bloqueo entre ejecuciones concurrentes ni transacción entre «crear factura» y «actualizar last_ym».

---

## 4. Pantalla: Facturas (`admin/facturas.php`)

Una sola URL con varios modos según parámetros. Cabecera `erp_head('fact', …)`.

| Modo | URL | Qué muestra |
|---|---|---|
| Nivel 1 – Hubs por emisor | `facturas.php` | Una tarjeta grande por emisor (+ dados de baja con facturas). |
| Nivel 2 – Ingresos/Gastos | `?em={k}` | Dos tarjetas: Ingresos / Gastos del emisor. |
| Nivel 3 – Carpetas por mes | `?em={k}&tipo=ingreso|gasto` | Una carpeta por mes con documentos. |
| Nivel 4 – Lista del mes | `?em={k}&tipo=…&mes=YYYY-MM` | Tabla de documentos (facturas emitidas + subidas). |
| Hub por cliente | `?clientes=1` | Tarjeta por cliente con facturas. |
| Cliente | `?cli={id}` [`&mes=YYYY-MM`] | Carpetas por mes del cliente / tabla del mes; formulario de datos fiscales. |
| Vista imprimible | `?v={id}` | Hoja de factura (PDF vía imprimir). |
| Editor nuevo | `?new=1` [`&em={k}`] [`&cli={id}`] | Formulario + vista previa en vivo. |
| Editor existente | `?edit={id}` | Igual, con datos cargados. Sin `can_edit()` → redirige a `?v=` o a la lista. |
| JSON proyectos | `?proj_search=1&q=&client=` | Buscador del combobox de proyectos. |

Nota: `?cli=` en modo editor (`new=1&cli=`) es **prefill de cliente**, mientras que `?cli=` sin `new` es el detalle del hub por cliente.

### 4.1 Nivel 1 — Hubs por emisor
- Título «Facturas». Botón «Nueva factura» (`can_edit`) → `?new=1`.
- Rejilla 2 columnas (1 en móvil, en fila compacta). Cada tarjeta (`<a href="?em=k">`): icono documento, nombre del emisor,
  «{n} factura(s)», dos stats: **Cobrado** y **Pendiente**, chevron.
- Cálculo (`facturas.php:788-794`) sobre **todas** las facturas: `total = base*(1+iva/100−irpf/100)`;
  `n++`; `pagada` → `cob += total`; `estado ∉ {borrador, pagada}` → `pen += total`.

### 4.2 Nivel 2 — Ingresos / Gastos (`?em=k`)
- Migas: «Facturas › {Emisor}». Botón «Nueva factura» (→ `?new=1&em=k`).
- Dos tarjetas: **Ingresos** (icono verde `#12854a`) y **Gastos** (rojo `#c0392b`); «{n} documento(s)», stat «Total».
- Documentos del emisor: ingresos = facturas emitidas (todas, incluidos borradores; importe = total con IVA/IRPF; `neto` = base)
  + subidas `tipo=ingreso`; gastos = subidas `tipo=gasto` (importe tal cual).

### 4.3 Nivel 3 — Carpetas por mes (`&tipo=`)
- Migas «Facturas › {Emisor} › {Ingresos|Gastos}». Botones «Subir factura» (abre panel de subida) y, si `tipo≠gasto`, «Nueva factura».
- Rejilla `minmax(210px)`. Carpeta: icono carpeta, `mes_label` («Marzo 2026»), «{n} doc(s)», total, «Neto {base}».
  Orden: mes descendente. Vacío: «Aún no hay {ingresos|gastos} de {Emisor}.»

### 4.4 Nivel 4 — Lista del mes (`&mes=`)
Tabla (grid `110px 1fr 120px 120px 120px 40px`), cabecera: Documento · Detalle · Fecha · Importe · (tipo) · ().
- **Fila factura** (clic → `?v=id`): número en negrita; avatar (inicial, color `avatar_color`) + cliente + insignia de estado con punto;
  fecha `d/m/Y`; importe total; chevron. **Menú contextual con clic derecho** (solo `can_edit`):
  «Ver / Imprimir», «Editar», «Asignar proyecto…», «Duplicar», ─, «● Marcar pagada», «● Marcar enviada», ─, «Borrar factura»
  (confirmación título «¿Borrar la factura {num}?», texto «Se borra también su apunte de contabilidad.», peligro).
  No hay opción de volver a borrador/vencida desde el menú (sí desde el editor).
- **Fila subida** (clic → vista previa si hay archivo): «Subida» con icono; concepto + proveedor (small) + etiquetas
  `EFECTIVO` (verde) y `DEDUCIBLE`/`PERSONAL` (azul); fecha; importe; tipo de archivo «PDF»/«Imagen» o «sin archivo»;
  botón kebab «⋯». Menú: «Editar», «Vista previa» y «Descargar» (si hay archivo), ─, «Borrar documento»
  (confirmación «¿Borrar este documento?» / «También se quita de contabilidad.»).
- Vacío: «No hay {ingresos|gastos} en {Mes Año}.»

### 4.5 Panel «Subir factura» (subidas de gastos/ingresos)
Panel desplegable (dos columnas: previsualización + formulario; una en ≤900px) y **arrastrar-soltar en toda la ventana**
(overlay «Suelta el archivo para subir la factura» / «PDF o imagen · {Ingresos|Gastos} de {Emisor}»).
- Previsualización: barra con nombre («Sin archivo seleccionado») e insignia PDF/Imagen; zona vacía «Arrastra o haz clic para subir»;
  botones «Elegir archivo», «Quitar». PDF renderizado con pdf.js 3.11.174 (hasta 10 páginas en canvas; si falla, `<iframe>`).
- Campos: **Concepto** (placeholder «Hosting, gestoría…» en gastos / «Servicio SEO…» en ingresos), **Proveedor** (gastos) o
  **Cliente** (ingresos), **Importe (€)** (texto, «0,00», se parsea con `num_es`: «1.234,56»→1234.56), **Fecha** (datepicker, hoy),
  casillas «Efectivo (pagado en B · sin IVA/IRPF)» y «Deducible — me lo desgravo (gasto del socio)» (gastos) /
  «Personal — no cuenta en contabilidad» (ingresos), **Proyecto** «· uso interno, para su rentabilidad» (combobox, placeholder
  «Sin proyecto — buscar o crear…»). Botones «Cancelar», «Guardar».
- POST `action=upload_doc`: `emisor`, `tipo`, `edit_id` (vacío = alta), `concepto`, `proveedor`, `importe`, `fecha`, `file`,
  `efectivo`, `personal`, `project_id_sel` | `project_name`.
  - Archivo opcional; extensiones `pdf,jpg,jpeg,png,webp` (sin límite de tamaño ni comprobación de MIME real); nombre aleatorio;
    imágenes reducidas a 3000 px (`img_optimizar`). Al editar con archivo nuevo se **borra el antiguo del disco**.
  - Apunte contable (insert o update del `acc_id`): `fecha`, `tipo`, `concepto = (concepto|Gasto|Ingreso)[ · proveedor]`,
    `categoria = gasto?'Gasto':'Cliente'`, `importe`, `metodo = efectivo?'efectivo':'transferencia'`, `legal = !efectivo`,
    `ambito = emisor`, `deducible = (gasto ? personal : 0)`, `personal`, `project_id`, `notas='Subida en Facturas'`.
  - Redirige a `?em={k}&tipo={tipo}`.
- POST `action=del_doc&id=`: **borrado físico** (archivo + apunte + fila; no pasa por la papelera, a diferencia de las facturas).

### 4.6 Hub por cliente (`?clientes=1`) y cliente (`?cli=id[&mes=]`)
- Hub: título «Facturas por cliente», botón «Nueva factura». Tarjetas por cliente (orden alfabético): avatar 2 iniciales,
  nombre, «{n} factura(s)», total (todas las facturas, **incluidos borradores**) y «Neto {Σ base}». Filtrado por alcance.
  Vacío: «Aún no hay facturas» / «Cuando emitas facturas a tus clientes, aparecerán aquí.»
- Cliente: migas «Por cliente › {Cliente}[ › {Mes}]». Botones «Datos de facturación» (despliega formulario) y «Nueva factura»
  (enlaza `?new=1` **sin** `&cli=`: no precarga el cliente — mejorar). Formulario `action=save_cli_fiscal`, `cli`, `fact_nombre`
  («Nombre fiscal / razón social», placeholder = nombre), `fact_nif`, `fact_tel`, `fact_email`, `fact_dir` («Dirección fiscal»),
  botón «Guardar datos de facturación» → sobrescribe los 5 campos (también vacíos) → `?cli=id`.
- Sin `mes`: carpetas por mes (n, total, neto). Vacío «Este cliente no tiene facturas» / «Emite la primera cuando quieras.» / «Emitir factura».
- Con `mes`: tabla Nº · Emisor · Fecha · Total · Estado · › (clic → `?v=id`). Vacío «No hay facturas en {Mes}.»

### 4.7 Editor de factura (`?new=1` / `?edit=id`) — «estilo Holded con preview en vivo»
Layout: grid `1fr | 470px` (una columna ≤1150px). Izquierda: tarjetas plegables (clic en el título, flecha ▾). Derecha
(sticky): «Vista previa» con la hoja en miniatura. Pie sticky: «Cancelar» → `facturas.php`, «Guardar factura».
Botón «Volver» arriba, `<h1>` «Nueva factura» / «Editar factura».

1. **Cliente**: select «Cliente del portal (autorrellena sus datos)» (`— Manual —` + clientes; cada `<option>` lleva `data-*` fiscales);
   al elegir, rellena Nombre (`fact_nombre` o `name`), NIF, Dirección, Email, Teléfono **solo con valores no vacíos** y refresca el
   combobox de proyectos para ese cliente. Campos: «Nombre / razón social», «NIF / CIF», «Teléfono», «Email», «Dirección» (ancho completo).
2. **Datos de emisión** (3 columnas): «Emitida por» (select de emisores; en factura nueva al cambiarlo copia IVA, IRPF y Vencimiento
   del emisor y pone placeholder `{PFX}-{AAAA}-001`), «Serie (prefijo)» (placeholder «Ej: F, SE-, 2026-»; en nueva se precarga con
   `serie_{emisor}`; en edición vacío), «Nº (vacío = auto por serie)» (placeholder «auto»), «Estado» (4 estados).
3. **Fechas y cobro**: «Fecha» (datepicker → hidden ISO), «Fecha vto. (opcional)»; «Período de facturación» con segmentado
   «Mes vista» (desde = día 1 del mes actual, hasta = día 1 del mes siguiente, fecha = día 1 del mes actual), «Mes vencido»
   (desde = día 1 del mes anterior, hasta = día 1 del mes actual, fecha = día 1 del mes actual), «Sin período» (vacía); dos
   datepickers «Desde» → «Hasta»; «Vencimiento (texto)» (placeholder «Contado»).
   (Rareza: «hasta» es el día 1 del mes siguiente, no el último día del mes.)
4. **Líneas**: cabecera Detalle · Cant. · Precio · Total · ✕ (grid `1fr 72px 100px 100px 30px`). Cada fila: concepto (texto,
   «Concepto…»), cantidad (number step .01 min 0, def. 1), precio (number step .01 min 0, def. 0), total calculado (`q*p`, formato
   es-ES con «€»), botón ✕. «＋ Añadir línea». En nueva, una línea vacía.
5. **Impuestos**: «IVA %», «IRPF %» (number step .01); casillas «Efectivo (en B) · sin IVA ni IRPF» (al marcar guarda los valores
   previos, pone 0 y deshabilita los inputs; al desmarcar los restaura) y «Ingreso personal (mío · fuera de la contabilidad de empresa)».
6. **Notas**: textarea «Condiciones, comentarios…».
7. **Proyecto** «· uso interno, no sale en la factura»: combobox (búsqueda en servidor `?proj_search`, crear «…» en línea).

Comportamiento JS: `feCalc()` recalcula totales de línea y `feRender()` repinta la vista previa; además
`setInterval(feRender, 600)` (para capturar cambios del datepicker). Formato `eurf(n)` = redondeo a 2 decimales + `toLocaleString('es-ES')` + « €».
La vista previa omite líneas sin concepto ni precio; si no hay, «Sin líneas». Número mostrado = valor o placeholder.

**POST `action=save`** (`facturas.php:68-126`): campos `id`, `emisor`, `efectivo`, `personal`, `numero`, `serie`, `client_id`,
`cliente_nombre|nif|dir|email|tel`, `fecha`, `fecha_venc`, `periodo_ini`, `periodo_fin`, `cond_pago`, `estado`, `iva_pct`, `irpf_pct`,
`notas`, `project_id_sel` | `project_name`, `it_concepto[]`, `it_cant[]`, `it_precio[]`.
1. `emisor` normalizado. Si `serie` no vacía → `settings.serie_{emisor} = serie` (recordatorio).
2. `numero` vacío → `fin_next_numero(serie, emisor, fecha)`. Si se teclea, se usa tal cual (sin validar formato ni unicidad;
   un duplicado revienta contra el índice UNIQUE sin mensaje amable).
3. `efectivo` → IVA=IRPF=0; si no, `(float)` de lo enviado (def. 21/0). **Sin validación de rango**.
4. Proyecto: `project_id_sel` o `proj_get_or_create(project_name, client_id)`.
5. `fecha` vacía → hoy. `estado` fuera de la lista → `borrador`.
6. Nueva: `emisor_json` = snapshot actual. Edición: **no** se actualiza el snapshot (aunque cambie el emisor).
7. Si `cliente_nombre` vacío y hay `client_id` → `clients.name`.
8. INSERT/UPDATE de `invoices`. Con `client_id`: copia a `clients.fact_*` los datos **no vacíos** del formulario.
9. Borra y reinserta líneas (cantidad/precio por `num_es`; descarta concepto vacío).
10. `pagada` → `fecha_pago = COALESCE(fecha_pago, hoy)`; otro estado → `fecha_pago = NULL`. `fin_sync_accounting(id)`.
11. Redirige a `?v={id}`.
No se puede editar una factura «cerrada»: cualquier factura (enviada, pagada) se puede editar, renumerar y cambiar de emisor.

### 4.8 Otras acciones POST de facturas
| `action` | Parámetros | Efecto | Respuesta |
|---|---|---|---|
| `set_estado` | `id`, `estado` | Igual que el paso 10 de `save` + `fin_sync_accounting`. Si pasa a `pagada` y antes no lo estaba → `notif_invoice_paid` (aviso «factura cobrada {numero}» a los Dueños salvo quien cobra; `ref=invpaid:{id}:{fecha_pago}`). | JSON `{ok:1}` (fetch desde el menú; luego `location.reload()`) |
| `del` | `id`, `ret` | Copia a la papelera (`pap_borrar_flash('invoices', id, 'factura', '{num} · {cliente}', hijos: invoice_items y accounting por invoice_id, msg «Factura {num} eliminada»)`) y borra líneas, apunte y factura. Deshacer disponible (toast). | redirect `ret` |
| `dup` | `id` | Copia la factura con `estado=borrador`, `fecha_pago=NULL`, `created_at=ahora` y **número nuevo** con la serie recuperada quitando los dígitos finales del número original (`V-2025-014` → serie `V-2025-`, ¡del año original!). Conserva fecha, cliente, emisor_json, proyecto… Copia líneas. | redirect `?edit={nuevo}` |
| `set_project` | `id`, `project_id_sel`/`project_name`, `ret` | `invoices.project_id` y `accounting.project_id` de su apunte. | redirect `ret` |
| `save_cli_fiscal` | ver 4.6 | | redirect |
| `upload_doc`, `del_doc` | ver 4.5 | | redirect |

Modal «Asignar proyecto» (clic derecho → «Asignar proyecto…»): combobox «Buscar o crear proyecto…», «Cancelar», «Guardar».

### 4.9 Vista imprimible (`?v=id`)
- Migas (no se imprimen): «Facturas › [Proyecto] › Factura {num}». Barra: «Facturas» (volver a `?em={k}&tipo=ingreso`),
  «Editar» (`can_edit`), «Descargar / Imprimir» (`window.print()`).
- **Hoja** (papel blanco siempre, también en modo oscuro; máx. 680px; `@page{margin:0}`, en impresión padding 16mm×15mm, se ocultan
  menús): cabecera «FACTURA» (36px, 850) + píldora «Nº {numero}»; a la derecha chips «Período: **dd/mm/aaaa** a **dd/mm/aaaa**»
  (si hay) y «Fecha: **dd/mm/aaaa**».
- Bloque de partes (2 columnas con borde): «DATOS DEL CLIENTE» (nombre o «—»; NIF, teléfono, dirección, email en líneas) y
  «DATOS AUTÓNOMO» (nombre, NIF, email, teléfono, dirección). Datos del emisor = `emisor_data(emisor)` **sobrescrito** por el snapshot `emisor_json`.
- Tabla: Detalle · Cantidad · Precio · Total (cabecera oscura). Cantidad sin ceros sobrantes (`1`, `1,5`). Vacío «Sin líneas.»
- Totales (caja 330px): «Base imponible», «IVA (+21%)», «IRPF (−15%)» con «−{importe}» (siempre visible, aunque sea 0),
  bloque oscuro «TOTAL».
- «INFORMACIÓN DE PAGO»: Banco (si hay), Titular (nombre emisor), Forma de pago («Efectivo»/«Transferencia»),
  Vencimiento («{cond_pago}[ · dd/mm/aaaa]»), IBAN (si no es efectivo y hay IBAN).
- Notas: «Operación cobrada en efectivo. Factura sin IVA.» (si efectivo) y las notas de la factura (con saltos de línea).
- El estado no se muestra en la hoja del admin.

### 4.10 Endpoint JSON
`GET facturas.php?proj_search=1&q={texto}&client={id}` → `{ok:1, items:[{id, nombre, color, activo, is_client, nmov}]}`.
Con `q` vacío usa `proj_suggest(client, 8)` (del cliente primero, activos, por actividad reciente); con texto `proj_search(q, client, 12)`.

---

## 5. Pantalla: Programaciones (`admin/programaciones.php`)

URL: `programaciones.php` [`?new=1` | `?edit={id}` | `?gen={n}`]. Permiso de página `ver.finanzas`; acciones `finanzas.programar`.

- Cabecera «Programaciones», botones «⚡ Generar ahora» (POST `run`) y «Nueva programación».
- Nota azul: «Cada mes, en el día que indiques, el sistema genera automáticamente la factura del cliente (lo hace el cron). También
  puedes emitir lo pendiente a mano con «Generar ahora».» + si `?gen`: «**Se generaron {n} factura(s).**»
- **Formulario** (encima de la lista, si `new`/`edit` y `can_edit`), título «Nueva programación»/«Editar programación», rejilla 3 col.:
  «Emisor», «Serie (prefijo)» («Ej: F, SE-, 2026-»), «Cliente» (opciones con sufijo « — sin datos» si no tiene `fact_nombre`),
  «Día de emisión (1-28)», «Nombre / razón social», «NIF / CIF», «Teléfono», «Email», «Dirección».
  Aviso naranja si el cliente no tiene datos: «Este cliente aún no tiene datos de facturación. Rellénalos abajo (se guardarán en su
  ficha al guardar la programación) o edítalos en Facturación de clientes.» Líneas Concepto · Cant. · Precio · ✕ (sin total por línea,
  sin preview), «＋ Añadir línea». «IVA %», «IRPF %», «Vencimiento (texto)», «Empezar en (mes)» (texto `YYYY-MM`), casilla «Activa».
  Botones «Cancelar», «Guardar programación». Defaults nuevos: emisor = primero, IVA 21, **IRPF 7**, Contado, día 1, mes actual, activa.
- **Lista** (grid 7 columnas): punto verde/gris (Activa/Pausada); Cliente + sub «IVA x% · IRPF y% · {cond} · última: {last_ym|—}»;
  Concepto (primera línea + «+N»); Emisor; «día N»; «Total / mes» = total con impuestos + «base {base}»; acciones «Editar»,
  «Pausar»/«Activar», «Borrar» (confirm «¿Borrar programación?»). Orden: activas primero, id desc.
  Vacío: «Sin programaciones. Crea la primera →».
- POST:
  - `save`: líneas por `num_es`; `dia` acotado 1..28; `start_ym` validado `^\d{4}-\d{2}$` (si no, mes actual); copia a la ficha del
    cliente solo los datos fiscales no vacíos. INSERT/UPDATE. **No** reinicia `last_ym` al cambiar `start_ym`.
  - `del`: borrado físico. `toggle`: `activo = 1-activo`. `run`: `prog_run()` → `?gen={n}`.

---

## 6. Pantalla: Contabilidad — Movimientos (`admin/contabilidad.php`)

URL `contabilidad.php?em={empresa|k}&y={año}[&export=csv|xls]`. Permiso `ver.conta`. **Solo lectura** (los movimientos nacen en
Facturas/Horas). Cabecera:
- `<h1>` «Contabilidad» + lead: «Criterio de **caja**: aquí solo cuenta lo que se ha **cobrado** de verdad. El total **facturado**
  (emitido, esté cobrado o no) está en Resumen mensual — por eso las dos cifras no tienen por qué coincidir.»
- Segmentado de páginas «Movimientos | Análisis»; segmentado de ámbito «Hub | {emisores…}»; selector de año (años con apuntes +
  año actual); «Exportar ▾» → «Excel (.xls)», «CSV (.csv)».

Filas: Hub → `YEAR(fecha)=y AND personal=0`; emisor → `YEAR(fecha)=y AND ambito=k` (incluye personales). Orden fecha desc, id desc.

**KPIs** (4 tarjetas):
| Tarjeta | Valor | Subtexto |
|---|---|---|
| Ingresos brutos | `ing = Σ importe (ingreso)` | «{ingLegal} legal · {ingEfectivo} efectivo» (efectivo = `metodo='efectivo' OR legal=0`) |
| Ingresos netos | `netoIng = Σ (invoice_id ? base de la factura : importe)` | «Sin IVA ni IRPF» |
| Gastos | `gas = Σ importe (gasto)` | Hub: «de empresa»; emisor: «{ded} deducibles» |
| Beneficio neto | `netoBenef = netoIng − gas` (verde ≥0, rojo <0) | «bruto {ing−gas} · {round(netoBenef/netoIng·100)}% margen» |

Solo en vista de emisor, franja de 3: «De tu bolsillo · gastos personales» (`Σ gasto personal=1`), «Gastos de empresa · los paga la
empresa» (`Σ gasto personal=0`), «Beneficio real tuyo · solo con tus gastos» (`netoIng − gasPersonal`).

**Tabla** (min 920px; en móvil tarjetas): Movimiento (avatar + concepto + etiqueta «Personal»/«Empresa» en gastos; sub
«Cliente · {nombre}» / «Proveedor · {proveedor}» o la categoría) · Tipo (punto verde «Ingreso» / rojo «Gasto») · Ámbito (insignia) ·
Proyecto (insignia o «—») · Fecha · Marcas («Legal», «Deduc.») · Importe (gastos con «−») · lápiz: factura → `facturas.php?edit={id}`
(«Editar factura»); subida → `facturas.php?em&tipo&mes` («Ver en Facturas»). Vacío: «Sin movimientos en {año} · {ámbito}.»
Enlace inferior «＋ Añadir más…» → `facturas.php`. Pie: «Los movimientos se registran creando o subiendo facturas. Aquí solo se
consultan; usa el lápiz para editar la factura de origen. Marca **Personal** = gasto del socio (fuera de empresa) · **Legal** = con
factura · **Deduc.** = el socio se lo desgrava.»

**Exportación** (mismas filas): nombre `contabilidad_{ámbito}_{año}`.
- CSV: UTF-8 con BOM, separador `;`, columnas `Fecha;Concepto;Tipo;Categoría;Ámbito;Proyecto;Método;Legal;Deducible;Personal;Importe (€)`
  (importe `1.234,56`), línea en blanco y filas «Ingresos», «Gastos», «Beneficio» (bruto).
- XLS: tabla HTML con `application/vnd.ms-excel`, título «Contabilidad · {ámbito} · {año}», cabecera oscura, filas alternas,
  importes verdes/rojos, ✓ en marcas, totales Ingresos/Gastos/Beneficio.

---

## 7. Pantalla: Contabilidad — Análisis (`admin/contabilidad-analisis.php`)

URL `contabilidad-analisis.php?em=&y=`. Misma selección de filas y cabecera (sin export ni lead). Solo lectura.

- **KPIs** (4): Ingresos (verde; sub «{legal} legal · {efectivo} efectivo»; etiqueta «Ingresos (empresa)» en Hub); Gastos (rojo;
  sub Hub «sin gastos personales» / emisor «{ded} deducibles»); Beneficio `ing−gas` («{margen}% margen» sobre **brutos**, no netos
  como en Movimientos); Hub: «Deducible socios» = Σ deducibles por emisor (sub «Víctor X · Gabi Y»); emisor: «Gastos deducibles»
  («que {emisor} se desgrava»).
- **Gráficas** (Chart.js 4.4.1): «Ingresos vs Gastos por mes» (barras agrupadas Ene…Dic, verde `#12a150cc` / rojo `#e05a4fcc`,
  eje «{v} €»); «Gastos por categoría» (donut `cutout 62%`, solo si hay ≥2 categorías; si 0: «Aún no hay gastos registrados.»;
  si 1: «Todos los gastos están en una sola categoría (**X**). Cuando registres gastos en categorías distintas, aquí verás el reparto.»).
- **Resumen por trimestres**: filas «1T · Ene-Mar», «2T · Abr-Jun», «3T · Jul-Sep», «4T · Oct-Dic» con Ingresos, Gastos, Beneficio,
  Margen (`round(b/i·100)`), pie «Total {año}».
- **Desglose de gastos por categoría**: lista con barra proporcional al máximo («Sin gastos este año.»).
- «Legal vs Efectivo (ingresos)»: Declarado / legal, Efectivo, «Ratio declarado» `round(legal/ing·100)%`.
- «Deducible de socios (año)»: una fila por emisor (consulta propia `tipo='gasto' AND deducible=1 GROUP BY ambito` sin filtro de
  ámbito de vista), «Total deducible», nota «Gastos personales que cada socio se desgrava (fuera de empresa).»
- Fórmulas: mes = `n` de `fecha`; trimestre `intdiv(m−1,3)`; categoría vacía → «Sin categoría».

---

## 8. Pantalla: Resumen mensual (`admin/fin-resumen.php`)

URL `fin-resumen.php?em={empresa|k}&m=YYYY-MM` (por defecto mes actual). Permiso `ver.finanzas`. Criterio **devengo**.
- Cabecera «Resumen mensual» + lead: «Basado en lo **facturado** (devengo): cuenta todas las facturas emitidas del mes, se hayan cobrado
  o no. Lo **realmente cobrado** (caja) está en Contabilidad — por eso las dos cifras no tienen por qué coincidir.»
  Segmentado «Hub | emisores»; navegador de mes «‹ Marzo 2026 ›» + «Hoy» si no es el actual.
- `fr_month(ym)`: facturas del mes (`fecha` entre día 1 y último) — Hub: `personal=0`; emisor: `emisor=k` — **todos los estados,
  incluidos borradores**: `base = Σ base`, `iva = Σ base·iva%`, `irpf = Σ base·irpf%`, `n`. Gastos: `Σ accounting.importe tipo='gasto'`
  (Hub `personal=0`; emisor `ambito=k`). Derivados: `cobrado = base+iva−irpf`, `impuestos = iva+irpf`, `neto = base − gastos − irpf`.
- **3 tarjetas**: «Ha entrado» (`cobrado`; sub «Cobrado en cuenta · {n} factura(s)»; insignia de variación vs mes anterior solo si
  ambos >0: `round((M−P)/P·100)`), «Para Hacienda» (`impuestos`; «IVA x · IRPF y»), «Neto (limpio)» (`neto`; «Tras impuestos y gastos»;
  variación si ambos ≠0: `round((M−P)/|P|·100)`).
- **Gráfica** «Ingresos por mes» · «Últimos 12 meses · base»: línea con relleno degradado, tensión .35, eje Y en «K».
- **Facturas del mes** (contador): lista avatar + cliente + «{numero} · dd/mm» + total + estado coloreado → `facturas.php?v=id`.
  Vacío «Sin facturas en {Mes}.»
- **«De lo facturado a lo que te queda»** (cascada): Base imponible · + IVA repercutido · − IRPF retenido · **Cobrado en cuenta** ·
  − IVA a Hacienda · − Gastos del mes · **Neto estimado**. Nota: «Estimación. El IVA se cobra pero se ingresa a Hacienda; el IRPF
  retenido es un adelanto de tu IRPF. Para cifras oficiales, consulta con tu gestoría.»
- Inconsistencia: «Ha entrado / Cobrado en cuenta» en realidad es facturado (devengo) e incluye borradores.

---

## 9. Pantalla: Horas de equipo (`admin/fin-horas.php`)

URL `fin-horas.php?u={adminId}&m=YYYY-MM[&flash=&fok=]`. Permiso página `ver.horas`; POST `tareas.horas`.
`$mgr = can_edit()`: el gestor elige persona (`?u=`) y ve todo; el resto solo se ve a sí mismo. Fondo gris «canvas» estilo Apple.

- Cabecera: avatar + `<h1>` usuario + «{tarifa} / hora» o «Sin tarifa configurada» + « · autónomo». Select de miembro (gestor; muestra
  « · autónomo»). Navegador de mes «‹ Mes ›» + «Hoy».
- Cálculo del mes (todas las `time_entries` de la persona entre día 1 y último):
  `minTot = Σ minutos`, `impExtra = Σ importe (no nulos)`, `horas = minTot/60`, `base = horas·tarifa + impExtra`,
  `iva = base·iva%`, `irpf = base·irpf%`, `total = base + iva − irpf`.
- **Tarjetas**: «Horas del mes» (`horas` con 2 decimales «h»; «{n} tarea(s) · {m} extra(s)»), «Base» («{h} h × {tarifa}[ + {extras}
  extras]» o «Configura la tarifa»), «Total a cobrar» (chips «Base», «IVA x% +», «IRPF y% −»).
- **Puente a contabilidad** (gestor): pendientes = entradas sin `acc_id`; `pendEur = Σ importe + (Σ minutos/60)·tarifa`.
  Textos: «Estas horas todavía no están en Contabilidad» / «{n} registro(s) pendiente(s) · {hh h mm m} · {€} se apuntará como gasto
  de «Equipo» con fecha {último día del mes}.»; «Este mes ya está apuntado como gasto» / «Las horas de {Mes} ya se pasaron a un gasto.
  Si añades más horas después, aparecerá otra vez el botón para volcar solo las nuevas.»; «Nada que volcar a Contabilidad» /
  «En cuanto se registren horas este mes podrás pasarlas a Contabilidad de un clic.» Botones «Ver en Contabilidad» y «Pasar a gasto»
  (confirm «¿Pasar las horas a Contabilidad?» / «Se apuntará {€} como gasto de «Equipo» con fecha {dd/mm/aaaa}. Las horas quedan marcadas
  para no cobrarlas dos veces.» / OK «Apuntar el gasto»).
- **Horas por tarea** (total `hhmm`): agrupado por tarea → enlace `task.php?id=`, «{cliente} · {n} registro(s)», horas y € a la tarifa.
  Vacío: «Aún no hay tiempo registrado en tareas este mes. Se registra en el campo «Tiempo» de cada tarea.»
- **Extras manuales** (contador): concepto, fecha, horas+€ o importe; papelera (dueño o gestor; confirm «¿Eliminar este extra?»).
  Vacío «Sin extras este mes.» Formulario: «Concepto (ej: reunión, desplazamiento…)» (required), segmentado «Horas | Importe €»,
  valor (texto decimal, unidad «h»/«€»), fecha, «Añadir».
- **Tarifa y fiscalidad de {usuario}** (gestor): casilla «Autónomo por horas», «Tarifa / hora (€)», «IVA %», «IRPF %», «Guardar».
  Nota: «Para un autónomo típico en España: IVA 21%, IRPF 15%. Déjalo a 0 si solo quieres horas × tarifa. Estimación orientativa,
  no sustituye a tu gestoría.»
- POST: `rate_save` (gestor; `UPDATE admins` con `num_es`) · `extra_add` (gestor o propio; `tipo=horas` → `minutos=round(valor·60)`,
  `tipo=importe` → `importe=valor`; requiere concepto y valor>0 / importe) · `entry_del&eid` (gestor o propio; **sin** comprobar si ya
  está volcada) · `to_expense` (gestor) → `pu_horas_a_gasto(target, mesIni, mesFin)` → redirect con `flash`/`fok` (toast).
- `pu_horas_a_gasto`: suma entradas sin `acc_id`; importe por fila = `importe ?? minutos/60·tarifa`; si total ≤0: «Esas horas suman 0 €:
  revisa la tarifa por hora de {u} en Equipo.»; si no hay: «No hay horas pendientes de volcar en ese periodo.»; inserta gasto
  `{fecha: fin, tipo:'gasto', concepto:'Horas {u} · {ini} – {fin}', categoria:'Equipo', importe: round(total,2), metodo:'transferencia',
  legal:1, ambito:'empresa', deducible:1, client_id (si único), notas:'{h} h del equipo', admin_id}` y marca `time_entries.acc_id`.
  Mensaje «Gasto de {€} € apuntado ({h} h).» El gasto es la **base** (sin IVA/IRPF del autónomo).

---

## 10. Pantalla: Facturación de clientes (`admin/fin-ajustes.php`)

URL `fin-ajustes.php[?ok=cli]`. Permiso `ver.finanzas`. `<h1>` «Facturación de clientes»; lead «Los datos fiscales de cada cliente.
Se rellenan solos al crear una factura o una programación. ¿Buscas los datos de los autónomos que emiten (IBAN, IVA, IRPF)? Están en
Ajustes › Facturación.» Insignia «{n} sin datos» (sin `fact_nombre`). Nota de éxito «Datos del cliente guardados.»
Rejilla de botones por cliente: punto verde «Completo» / amarillo «Faltan datos». Modal: título = nombre; «Razón social / Nombre
fiscal» (placeholder nombre), «NIF / CIF» («B12345678»), «Teléfono» («600 000 000»), «Email» («correo@cliente.com»), «Dirección fiscal»
(«C/ …, CP, ciudad»); «Cerrar», «Guardar» (deshabilitado sin `can_edit`).
POST `action=save_cliente`: `cid`, `fn`, `fi`, `fd`, `fe`, `ft` → comprueba que existe y sobrescribe los 5 campos (`trim`).
Vacío: «Aún no tienes clientes. Crear el primero →».

---

## 11. Factura del portal del cliente (`factura.php`, raíz)

URL `factura.php?id={id}[&print=1]` (cliente con sesión) o `factura.php?id={id}&cli={clientId}` (admin, previsualización; exige
`alcance_ve_cliente`). Página HTML independiente (sin layout del ERP), título «Factura {numero}».
- Consulta **siempre** `WHERE id=? AND client_id=? AND estado IN ('enviada','pagada','vencida')` (nunca borradores ni ajenas).
- Datos del emisor **solo** del snapshot `emisor_json` (sin respaldo de settings); por defecto «Croilab».
- Diferencias con la hoja del admin: barra con «Volver» (→ `index.php`), insignia de estado coloreada (Enviada/Pagada/Vencida) y
  «Descargar / Imprimir»; chips «Fecha» y «Vencimiento» (`fecha_venc`) — **no** muestra el período; bloque «EMITIDA POR» (en vez de
  «Datos autónomo»); fila IRPF solo si >0; en pago, «Condiciones» = `cond_pago` (en vez de «Vencimiento»); nota «Operación cobrada en
  efectivo.» (sin «Factura sin IVA»). `?print` → `window.print()` a los 250 ms.
- No encontrada: «No encontramos esa factura» / «Puede que ya no esté disponible o que no corresponda a tu cuenta. Vuelve a tus facturas
  e inténtalo de nuevo.»
- Lista en el portal (`index.php:223-235`, `2747-2765`): facturas del cliente no borrador con
  `total = ROUND(base·(1+iva/100−irpf/100), 2)`; fila «Factura {numero}», fecha larga + « · vence {fecha}», estado, importe; vacío
  «Todavía no tienes facturas. Cuando emitamos alguna aparecerá aquí para que la veas y la descargues.»

---

## 12. Cron (`admin/cron.php` + `lib/cron_lib.php`)

- Entrada: CLI `php admin/cron.php` o URL `cron.php?key={cron_key}` (comparación `hash_equals`; si falla 403 «Clave de cron incorrecta.»).
  La clave (32 hex) se genera sola en `settings.cron_key` y se regenera desde Automatizaciones.
- Tareas (cada una en su try; se saltan si `auto_{clave}='0'`; resultado en `cron_log {tarea, ok, detalle≤250, ms, origen cli|url}`,
  purga > 60 días; fila final `tarea='ciclo'`):
  1. `invoice_recurring` «Facturas recurrentes»: `prog_run()` → «{n} factura(s) emitida(s)» / «nada que emitir».
  2. `followups`, 3. `daily_digest`, 4. `lead_reminder` (CRM, fuera de ámbito).
  5. `invoice_due` «Avisos de facturas»: `notif_sync_invoices()` → `UPDATE invoices SET estado='vencida' WHERE estado='enviada' AND
     fecha_venc < CURDATE()` y para cada vencida una notificación a **todos** los admins (tipo `factura`, título «Factura vencida {numero}»,
     cuerpo «{cliente} · vencía dd/mm/aaaa», url `facturas.php?v=id`, `ref=inv:{id}`, `INSERT IGNORE`). No toca contabilidad.
  6. `monthly_report`, 7. `trash_purge` (purga papelera >30 días: afecta a facturas borradas), 8. `uploads_sweep` (archivos huérfanos
     de `uploads/facturas` incluidos, 1×día).
- Salida texto: «{Agencia} ERP · cron dd/mm/aaaa HH:MM:SS (cli|url)», líneas «✓ / ✗ / – {tarea}: {detalle} ({ms} ms)», «Total: {ms} ms».
- `cron_lib`: `cron_ultima()`, `cron_por_tarea()`, `cron_hace()` («hace 20 min»…), `cron_ruta()`, `cron_url()` (para la UI de Automatizaciones).
- Descripciones de los interruptores (settings.php): «Facturas vencidas — Marca como «vencida» las facturas enviadas que pasan su fecha
  de vencimiento y avisa.»; «Facturas recurrentes — Emite sola cada factura programada el día del mes que le toca.»

---

## 13. Puentes (`admin/lib/puentes.php`) relevantes para Finanzas

- **Negocio → factura** `pu_negocio_a_factura($dealId)` (llamado desde `negocio.php`, POST `action=to_invoice`, respuesta JSON
  `{ok, id, numero, ya, msg}`): si `deals.invoice_id` ya existe → «Este negocio ya tenía la factura {numero}.» (`ya:true`). Si no:
  datos fiscales por prioridad ficha del cliente (`fact_*`) → `billing_data` del contacto → contacto (empresa/nombre, email, teléfono);
  emisor = `emisor_por_defecto` normalizado; serie = `settings.serie_{emisor}`; número `fin_next_numero(serie, emisor, hoy)`; factura
  `estado='borrador'`, fecha hoy, IVA/IRPF/venc del emisor, snapshot; una línea `concepto = nombre del negocio [· servicio]` (≤300),
  `cantidad 1`, `precio = deals.valor` (el valor del negocio se toma como **base imponible**); `deals.invoice_id`; actividad CRM
  «Factura {n} generada desde el negocio». Mensaje «Factura {n} creada en borrador.»
- **Lead → cliente** `pu_lead_a_cliente` (copia `billing_data` a `clients.fact_*`; dirección = `direccion, cp ciudad, provincia, pais`).
- **Horas → gasto** `pu_horas_a_gasto` (ver §9).
- Otros consumidores de facturas fuera de Finanzas: `dashboard.php` (KPI «cobrado» del mes = Σ total de pagadas por `fecha`, no por
  `fecha_pago`), `client.php` (bloque Facturas: n, «Cobrado», «Pendiente», últimas 5; enlaces `facturas.php?new=1&cli=`, `?cli=`),
  `proyecto.php` (`link_invoice`/`unlink_invoice`, balance desde `accounting.project_id`, pendiente = facturas del proyecto no pagadas).

---

## 14. Fórmulas (resumen único)

```
línea.importe        = cantidad × precio                         (sin redondeo intermedio)
base (subtotal)      = Σ línea.importe
cuota IVA            = base × iva_pct / 100
retención IRPF       = base × irpf_pct / 100
total factura        = base + IVA − IRPF  = base × (1 + iva/100 − irpf/100)
efectivo             ⇒ iva_pct = irpf_pct = 0
redondeo             = solo al mostrar (number_format 2 / toLocaleString) y al guardar en DECIMAL(12,2) (accounting.importe);
                       portal: ROUND(total, 2) en SQL
hub emisor           : cobrado = Σ total(pagada); pendiente = Σ total(enviada|vencida)
cliente/mes/hub cli. : total = Σ total (todos los estados); neto = Σ base
contabilidad (caja)  : ing, gas, ingLegal, ingEfectivo(metodo=efectivo ∨ ¬legal), ded, gasPersonal, gasEmpresa
                       netoIng = Σ(invoice_id ? base(factura) : importe);  netoBenef = netoIng − gas;  margen = round(netoBenef/netoIng·100)
análisis             : benef = ing − gas; margen = round(benef/ing·100); trimestres; categorías; ratio declarado = round(legal/ing·100)
resumen (devengo)    : cobrado = base+iva−irpf; impuestos = iva+irpf; neto = base − gastos − irpf; Δ% vs mes anterior
programación/mes     : base = Σ q·p (lineas_json); total = base·(1+iva/100−irpf/100)
horas                : base = (Σmin/60)·tarifa + Σimporte; total = base·(1+iva/100−irpf/100); gasto volcado = base (redondeado a 2)
```

---

## 15. (a) Endpoints recomendados para la API v1

Convenciones de `backend/API.md` (JSON `{ok,…}`, CSRF en POST/PATCH/DELETE, 403 `permiso`, 422 `validacion`+`campo`, paginación
`limit/offset`, fechas ISO). Importes en JSON como **string decimal** (`"1234.56"`) o céntimos enteros, nunca float.

**Emisores y configuración**
| Método | Ruta | Uso | Permiso |
|---|---|---|---|
| GET | `/v1/finanzas/emisores` | Lista `[ {clave, nombre, prefijo, baja:boolean, uso:{facturas,programaciones,apuntes}, defaults:{iva,irpf,venc}} ]` (+ dados de baja con histórico) | `ver.finanzas` |
| GET | `/v1/finanzas/emisores/{clave}` | Datos fiscales completos (NIF, IBAN, banco…) | `finanzas.emisores` |
| PUT | `/v1/finanzas/emisores` | Guardar lista (renombres, altas, `emisor_por_defecto`) | `finanzas.emisores` |
| PATCH | `/v1/finanzas/emisores/{clave}` | Datos fiscales, prefijo (no vacío), defaults | `finanzas.emisores` |
| DELETE | `/v1/finanzas/emisores/{clave}` | Baja; 409 con el uso si tiene histórico; 409 si es el último | `finanzas.emisores` |

**Facturas**
| Método | Ruta | Uso | Permiso |
|---|---|---|---|
| GET | `/v1/facturas` | Filtros `emisor`, `client_id`, `estado` (multi), `desde`, `hasta`, `mes`, `q` (número/cliente), `project_id`, `personal`; devuelve `{items:[{id,numero,emisor,cliente_nombre,client_id,fecha,fecha_venc,estado,base,iva,irpf,total,efectivo,personal,project}], total, totales:{base,total,cobrado,pendiente}}` | `ver.finanzas` (+ alcance) |
| GET | `/v1/facturas/resumen` | Agregados para hubs: por emisor `{n, cobrado, pendiente}`; por cliente; por mes (`agrupar=emisor|cliente|mes`, `tipo`) | `ver.finanzas` |
| GET | `/v1/facturas/{id}` | Factura completa con `lineas`, `emisor_snapshot`, `totales`, `project`, `apunte_id` | `ver.finanzas` |
| POST | `/v1/facturas` | Crear (borrador por defecto). Cuerpo: cliente, emisor, serie?, fechas, período, cond_pago, iva/irpf, efectivo, personal, notas, project_id, `lineas:[{concepto,cantidad,precio}]` | `finanzas.emitir` |
| PATCH | `/v1/facturas/{id}` | Editar (solo borrador, ver riesgos) | `finanzas.emitir` |
| POST | `/v1/facturas/{id}/emitir` | Borrador → enviada: asigna número definitivo y congela snapshot | `finanzas.emitir` |
| POST | `/v1/facturas/{id}/estado` | `{estado, fecha_pago?}`; a/desde `pagada` sincroniza el apunte y notifica | `finanzas.cobrar` (a/desde pagada), `finanzas.emitir` (resto) |
| POST | `/v1/facturas/{id}/duplicar` | Copia como borrador (serie actual, fecha hoy) | `finanzas.emitir` |
| POST | `/v1/facturas/{id}/rectificar` | (nuevo) Factura rectificativa por diferencias o sustitución, serie R | `finanzas.emitir` |
| PATCH | `/v1/facturas/{id}/proyecto` | `{project_id}` (actualiza también el apunte) | `finanzas.emitir` |
| DELETE | `/v1/facturas/{id}` | A la papelera (solo borradores; ver riesgos) → `{papelera_id}` | `finanzas.borrar` |
| GET | `/v1/facturas/{id}/pdf` | PDF generado en servidor (mismo diseño) | `ver.finanzas` |
| GET | `/v1/facturas/siguiente-numero` | `?emisor&serie&fecha` → previsualizar número (sin reservar) | `finanzas.emitir` |
| GET | `/v1/proyectos/buscar` | `q`, `client_id`, `limit` → `{items:[{id,nombre,color,activo,is_client,nmov}]}` (sustituye `?proj_search`) | `ver.finanzas` |
| POST | `/v1/proyectos` | Crear por nombre (`proj_get_or_create`) | `finanzas.emitir` |

**Documentos subidos (gastos/ingresos)**
| GET | `/v1/finanzas/documentos` | `emisor`, `tipo`, `mes`/`desde`/`hasta` | `ver.finanzas` |
| POST | `/v1/finanzas/documentos` | multipart: archivo + `{emisor,tipo,concepto,proveedor,importe,fecha,efectivo,personal,project_id}`; crea apunte | `conta.editar` |
| PATCH | `/v1/finanzas/documentos/{id}` | multipart opcional; actualiza apunte | `conta.editar` |
| DELETE | `/v1/finanzas/documentos/{id}` | A la papelera (archivo incluido) | `conta.editar` |
| GET | `/v1/finanzas/documentos/{id}/archivo` | `?descargar=1` | `ver.finanzas` |

**Programaciones**
| GET | `/v1/programaciones` | Lista con `base_mes`, `total_mes`, `ultima`, `proxima` (calculada) | `ver.finanzas` |
| GET/POST/PATCH/DELETE | `/v1/programaciones[/{id}]` | CRUD | `finanzas.programar` |
| POST | `/v1/programaciones/{id}/pausar` · `/activar` | toggle explícito | `finanzas.programar` |
| POST | `/v1/programaciones/generar` | «Generar ahora» → `{generadas, facturas:[{id,numero}]}` (con bloqueo) | `finanzas.programar` |

**Contabilidad**
| GET | `/v1/contabilidad/movimientos` | `ambito` (`empresa`=Hub), `anio`, `limit/offset` → items + `kpis` | `ver.conta` |
| GET | `/v1/contabilidad/movimientos/export` | `?formato=csv|xlsx&ambito&anio` | `ver.conta` |
| GET | `/v1/contabilidad/analisis` | `ambito`, `anio` → `{kpis, por_mes:[12×{ing,gas}], trimestres, categorias, legal_vs_efectivo, deducible_socios}` | `ver.conta` |
| GET | `/v1/contabilidad/anios` | Años con movimientos | `ver.conta` |
| POST/PATCH/DELETE | `/v1/contabilidad/movimientos[/{id}]` | (opcional, futuro) apuntes manuales; hoy no existe en el legado | `conta.editar` |

**Resumen mensual**
| GET | `/v1/finanzas/resumen-mensual` | `ambito`, `mes` → `{mes:{base,iva,irpf,gastos,n,cobrado,impuestos,neto}, anterior:{…}, delta:{cobrado,neto}, tendencia:[12×{ym,base}], facturas:[…]}` | `ver.finanzas` |

**Horas**
| GET | `/v1/horas` | `admin_id` (solo gestor ≠ yo), `mes` → `{persona, tarifa, entradas, por_tarea, extras, calculo:{horas,base,iva,irpf,total}, pendiente:{n,minutos,importe}, apunte_id}` | `ver.horas` |
| GET | `/v1/horas/personas` | Miembros con `es_autonomo`, tarifa (gestor) | gestor |
| POST | `/v1/horas/extras` | `{admin_id, concepto, fecha, tipo:'horas'|'importe', valor}` | `tareas.horas` (propio) / gestor |
| DELETE | `/v1/horas/{entryId}` | 409 si ya volcada (`acc_id`) | `tareas.horas` (propio) / gestor |
| PATCH | `/v1/equipo/{id}/tarifa` | `{es_autonomo, tarifa_hora, iva_pct, irpf_pct}` | gestor |
| POST | `/v1/horas/volcar` | `{admin_id, mes}` → `{ok, id, importe, horas, msg}` | gestor + `conta.editar` |

**Clientes (datos fiscales)**
| GET | `/v1/clientes/facturacion` | `{items:[{id,name,fact_*:…, completo}], sin_datos}` | `ver.finanzas` |
| PATCH | `/v1/clientes/{id}/facturacion` | `{fact_nombre,fact_nif,fact_dir,fact_email,fact_tel}` | `finanzas.emitir` |

**CRM / portal / cron**
| POST | `/v1/negocios/{id}/factura` | Puente negocio → borrador | `finanzas.emitir` |
| GET | `/v1/portal/facturas` · `/v1/portal/facturas/{id}` · `/{id}/pdf` | Sesión de cliente; nunca borradores | cliente |
| GET | `/v1/cron/estado` | Última ejecución por tarea | `ver.ajustes` |
El cron sigue siendo un script CLI (`backend/bin/cron` o similar) que llama a los mismos servicios.

---

## 16. (b) Pantallas y componentes React

Rutas sugeridas (`front/src/features/finanzas/...`):

| Ruta | Pantalla | Componentes |
|---|---|---|
| `/finanzas/facturas` | Hubs por emisor | `EmisorHubGrid`, `EmisorHubCard` (cobrado/pendiente) |
| `/finanzas/facturas/:emisor` | Ingresos / Gastos | `TipoHubCard` ×2 |
| `/finanzas/facturas/:emisor/:tipo` | Carpetas por mes | `MesFolderGrid`, `MesFolderCard` (total, neto) |
| `/finanzas/facturas/:emisor/:tipo/:mes` | Lista del mes | `DocumentosTabla` (filas `FacturaRow` y `DocumentoRow`), `EstadoBadge`, `RowMenu` (también por clic derecho), `ConfirmDialog` |
| `/finanzas/facturas/clientes[/:id[/:mes]]` | Por cliente | `ClienteHubGrid`, `ClienteFiscalForm` (plegable), `MesFolderGrid`, `FacturasMesTabla` |
| `/finanzas/facturas/nueva`, `/finanzas/facturas/:id/editar` | Editor | `FacturaEditor` (react-hook-form + zod): `SeccionPlegable`, `ClienteSelector` (autocompleta fiscales), `EmisionFields` (emisor, serie, número sugerido vía `/siguiente-numero`), `FechasCobroFields` + `PeriodoSegmented` (Mes vista / Mes vencido / Sin período), `LineasEditor` (`useFieldArray`, total por línea, añadir/quitar, parseo es-ES), `ImpuestosFields` + toggles Efectivo/Personal, `NotasField`, `ProyectoCombobox`; `FacturaPreview` (misma `FacturaHoja` en escala) en columna sticky; `useFacturaTotales` (cálculo en vivo con el mismo redondeo que el servidor) |
| `/finanzas/facturas/:id` | Vista | `FacturaHoja` (componente único compartido por preview, vista admin, portal e impresión; CSS `@media print`), barra con Editar / Descargar PDF / Imprimir / Cambiar estado |
| (panel) | Subir documento | `DocumentoUploadPanel` (dropzone de ventana completa, `PdfPreview` con pdf.js, `ImagePreview`), `ImporteInput` (es-ES) |
| (modal) | Asignar proyecto | `AsignarProyectoDialog` |
| `/finanzas/programaciones` | Programaciones | `ProgramacionesTabla`, `ProgramacionForm` (reutiliza `ClienteSelector`, `LineasEditor`), botón `GenerarAhora` con resultado |
| `/finanzas/contabilidad` (`?ambito&anio`) | Movimientos | `ContaHeader` (`SegmentedTabs` páginas/ámbito/año, `ExportMenu`), `KpiTile` ×4, `FranjaPersonal`, `MovimientosTabla` (responsive a tarjetas) |
| `/finanzas/contabilidad/analisis` | Análisis | `KpiTile`, `BarIngresosGastos` (12 meses), `DonutCategorias` (≥2 categorías, si no texto), `TrimestresTabla`, `CategoriasBarras`, `LegalEfectivoBox`, `DeducibleSociosBox` |
| `/finanzas/resumen` (`?ambito&mes`) | Resumen mensual | `MonthNavigator`, `ResumenCard` ×3 con `DeltaBadge`, `TendenciaLinea`, `FacturasMesLista`, `CascadaFacturado` |
| `/finanzas/horas` (`?u&mes`) | Horas | `PersonaHeader` + `PersonaSelect`, `MonthNavigator`, `HorasCards`, `VolcadoBanner` (confirmación), `HorasPorTareaLista`, `ExtrasLista` + `ExtraForm` (segmentado Horas/Importe), `TarifaForm` |
| `/finanzas/clientes-facturacion` | Datos fiscales de clientes | `ClienteFiscalGrid`, `ClienteFiscalDialog` |
| `/ajustes/facturacion` | Emisores | `EmisoresEditor` (fila por emisor: nombre, prefijo con ejemplo «Nº V-2026-001», datos fiscales, IBAN, defaults, quitar con bloqueo por uso; altas; emisor por defecto) |
| Portal `/facturas`, `/facturas/:id` | Cliente | `PortalFacturasLista`, `FacturaHoja` (variante portal) |

Compartidos: `formatEur` (`Intl.NumberFormat('es-ES',{style:'currency',currency:'EUR'})`), `parseNumeroEs` (port de `num_es`), `mesLabel`,
`DatePickerEs` (dd/mm/aa), `useEmisores`, `useCan(permiso)` para ocultar acciones, `MaskedAmount` (respeta `ver.importes`).
Gráficas: elegir una librería (Recharts/Chart.js) y respetar los colores actuales (verde `#12a150`, rojo `#e05a4f`).

---

## 17. (c) Riesgos y ambigüedades

**Legales / numeración (RD 1619/2012, Reglamento de facturación; y obligaciones VeriFactu/Ley Antifraude)**
1. **El número se asigna al crear el borrador**: borradores borrados o abandonados dejan **huecos** en la serie. Recomendado: el borrador
   no tiene número; se numera al «emitir» (`/emitir`) dentro de una transacción.
2. **Facturas emitidas editables y borrables**: hoy se puede cambiar número, fecha, importes, cliente y emisor de una factura enviada o
   pagada, y borrarla (papelera + purga a 30 días). Legalmente una factura emitida no se modifica: se corrige con **rectificativa**
   (serie propia, referencia a la original, motivo). **No existe soporte de rectificativas** ni de facturas simplificadas.
3. **Correlatividad y fechas**: no se valida que la fecha sea coherente con el orden de numeración; `prog_run` genera facturas atrasadas
   con fechas pasadas y `dup` copia la fecha original. La serie libre (`F`) no reinicia por año y no incluye año; el año sale de la fecha
   enviada.
4. **Bug en `dup`**: la serie se deduce quitando dígitos finales del número original → la copia de una factura de 2025 se numera en la
   serie `V-2025-` aunque se cree en 2026.
5. **Número editable a mano** sin validación de formato ni de unicidad (el índice UNIQUE puede no existir si había duplicados; si existe,
   el error es un 500 sin mensaje). `numero` es VARCHAR(30) y `invoice_counters.serie` VARCHAR(60): una serie larga puede truncarse.
   El respaldo `n = date('His')` produce números absurdos si falla el contador.
6. **Snapshot del emisor**: no se actualiza si en edición se cambia el emisor (la factura imprimiría datos de otro emisor). La vista del
   admin mezcla settings actuales + snapshot; el portal usa solo snapshot → pueden diferir.
7. **«Efectivo (en B)» / «Factura sin IVA»**: fuerza IVA 0 sin causa de exención ni mención legal (art. 6.1.j RD 1619/2012) y marca
   ingresos como «no legales». Funcionalidad con implicaciones fiscales serias: **decidir con negocio/gestoría** antes de portarla tal cual.
8. **Contenido mínimo de la factura**: falta mención de exención/ISP cuando IVA=0, fecha de operación distinta de expedición (solo período),
   y desglose por tipo de IVA si hubiera varios (hoy un único IVA por factura). Ver también VeriFactu (registro y QR) si aplica a los emisores.
9. **Rectificativas, abonos, cobros parciales y múltiples pagos no existen**: «pagada» es binario; `fecha_pago` no se puede elegir (siempre
   hoy al marcar) y se pierde al desmarcar.

**Cálculo / redondeo**
10. Sin redondeo por línea ni por cuota: IVA/IRPF se calculan sobre la base con floats y solo se redondea al mostrar; `accounting.importe`
    redondea MySQL; el portal usa `ROUND(…,2)`. Riesgo de céntimos de diferencia entre pantalla, portal, contabilidad y PDF.
    Definir una regla única (recomendado: `importe_línea = round(q·p, 2)`, `base = Σ`, `cuota = round(base·%/100, 2)`, `total = base+IVA−IRPF`)
    y usar aritmética decimal (céntimos enteros o BCMath) en servidor y cliente. `precio` DECIMAL(12,2) impide precios unitarios de 3–4 decimales.
11. Sin validación de rangos (IVA/IRPF negativos o >100, cantidades/precios negativos aceptados vía API aunque el input HTML tenga `min=0`).

**Contabilidad / coherencia**
12. Contabilidad se recalcula borrando y reinsertando el apunte (cambia el `id` del apunte en cada sincronización).
13. Borrar una subida (`del_doc`) es **físico** (no papelera), al contrario que las facturas.
14. Borrar un extra de horas ya volcado no actualiza el gasto; tampoco se puede «des-volcar». El gasto de horas es la base (sin IVA/IRPF del
    autónomo) y lleva `deducible=1` en ámbito `empresa` (ambiguo).
15. `fin-resumen` incluye **borradores** como facturado y rotula «Ha entrado / Cobrado en cuenta» lo que es devengo. Los hubs por cliente
    también suman borradores. `dashboard.php` cuenta cobrado por `fecha` de factura, no por `fecha_pago`.
16. Márgenes distintos: Movimientos usa beneficio **neto** (sin IVA/IRPF) y Análisis el **bruto**. Unificar o etiquetar.
17. `fin_limpiar_ingresos_no_cobrados()` es una migración de datos ejecutada en un GET: llevarla a una migración del backend.

**Permisos / seguridad**
18. `set_estado` (cobrar) no está mapeado a `finanzas.cobrar`; `save` con estado `pagada` tampoco; `upload_doc`/`del_doc` crean/borran apuntes
    sin `conta.editar`; tarifas de horas las cambia cualquiera con `general.editar`. Los avisos de vencidas van a **todos** los admins.
19. Alcance de clientes no aplicado en `?cli=ID`, hubs por emisor, contabilidad ni resumen.
20. Subida de archivos: solo se valida la extensión (sin tamaño máximo ni MIME real); el nombre aleatorio es de 16 hex.

**Programaciones / cron**
21. `prog_run` sin bloqueo ni transacción: dos ejecuciones simultáneas (cron + «Generar ahora») o un fallo entre «crear factura» y «actualizar
    `last_ym`» pueden **duplicar facturas**. Recomendado: `SELECT … FOR UPDATE` / `GET_LOCK`, transacción por factura, e idempotencia por
    `(schedule_id, ym)` con índice único (requiere añadir `schedule_id` a `invoices`).
22. Las facturas generadas nacen «enviada» (no se revisan), sin `fecha_venc` (nunca pasarán a vencidas), sin período ni proyecto, y no se
    envían por email (no existe envío de facturas en el ERP: «enviada» es un estado manual).
23. Cambiar `start_ym` de una programación ya emitida no reinicia `last_ym` (comportamiento ambiguo). Default de IRPF 7% en programaciones vs
    default del emisor en facturas.

**PDF**
24. No hay PDF en servidor: «Descargar / Imprimir» = `window.print()` (depende del navegador, márgenes y «imprimir fondos»). Para la nueva
    versión: generar el PDF en servidor (p. ej. Dompdf/mPDF o Chromium headless) desde una única plantilla, guardar copia inmutable al emitir
    (hash) y servirla al cliente; mantener la vista HTML como previsualización. Hay **tres** plantillas casi iguales (hoja admin, preview JS y
    portal) con diferencias de textos («Datos autónomo» vs «Emitida por», IRPF siempre vs solo >0, «Vencimiento» vs «Condiciones», período
    solo en admin) → unificar en `FacturaHoja` y decidir los textos definitivos.

**Migración de datos / esquema**
25. Tablas `invoice_uploads`, `projects`, `time_entries` (+ `time_entries.acc_id`, columnas fiscales de `admins`) no las crean las migraciones
    del backend nuevo. Añadirlas antes de exponer endpoints.
26. Datos legados con duplicados de número impedirían el índice UNIQUE: auditar `SELECT numero, COUNT(*) … HAVING COUNT(*)>1` antes de migrar.
27. Claves de emisor por defecto `victor`/`gavi` y textos «Víctor», «Gabi», «Croilab» como respaldos: no deben quedar fijos en el front.
