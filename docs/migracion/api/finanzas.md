# API · Finanzas (`/api/v1/finanzas`)

Rutas en `backend/api/rutas/finanzas.php`; código en `backend/src/Modulos/Finanzas/` (montado por `Modulo`).
Convenciones de `backend/API.md`: JSON `{ok:true,…}`, CSRF en POST/PATCH/DELETE, `403 permiso`, `404` fuera de alcance,
`409` conflicto de estado, `422 validacion` con `campo` (p. ej. `lineas.0.precio`, `emisores.1.prefijo`).

**Dinero:** todos los importes de las respuestas son **céntimos enteros** (`total: 139697` = 1.396,97 €). Cantidades,
precios y porcentajes van como texto decimal (`"2.50"`, `"33.33"`, `"21"`). En la entrada se acepta también el formato
español (`"1.234,56"`, `"12,5"`). Regla única (también en el front, `features/finanzas/lib/importes.ts`):
`línea = round(cant × precio, 2)`, `base = Σ`, `IVA = round(base × iva%)`, `IRPF = round(base × irpf%)`, `total = base + IVA − IRPF`.

**Alcance de clientes:** lo que no es de ningún cliente (gastos, facturas sin cliente) lo ve quien tiene el módulo; lo de un
cliente, solo si está en su alcance (`alcance.todos` o clientes de sus tareas/contactos). Aplica a listas, hubs,
resumen y contabilidad.

**Permisos** (escribir exige siempre además `general.editar`):
`ver.finanzas` (facturas, programaciones, resumen, documentos, calculadora) · `finanzas.emitir` (borradores, emitir,
duplicar, rectificar, anular, proyecto, datos fiscales de clientes) · `finanzas.cobrar` (pasar a/desde «pagada») ·
`finanzas.borrar` (borrar borradores) · `finanzas.programar` (programaciones) · `ver.conta` / `conta.editar`
(contabilidad / documentos subidos y apuntes) · `finanzas.emisores` (Ajustes › Facturación) · `ver.horas` /
`tareas.horas` (horas; «gestor» = `general.editar` + `finanzas.emitir`, volcar exige además `conta.editar`) ·
`ver.proyectos` (proyectos).

## Estados y reglas legales

`borrador` → (emitir) → `enviada` ⇄ `vencida` ⇄ `pagada`; `anulada` (final).
- Un **borrador no tiene número** (`numero: null`): se puede editar y borrar (papelera).
- **Emitir** asigna el número en una transacción con la fila del contador bloqueada: correlativo por serie, sin huecos.
  Formato `{PREFIJO}-{AÑO}-{NNN}`; con serie propia se le añade el año si no lo lleva (`F` → `F2026-001`). Las
  rectificativas van en la serie `R…` (`RV-2026-001`). Una serie es de un solo emisor. La fecha no puede ser futura
  ni anterior a la última de su serie. Exige NIF del emisor, nombre del cliente y alguna línea con importe.
  Congela los datos del emisor (`emisor_snapshot`) y guarda una huella SHA-256 (`hash`).
- **Emitida = inmutable:** `PATCH` y `DELETE` → `409 emitida`. Solo cambian estado de cobro, fecha de cobro y proyecto.
  Se corrige con **rectificativa** (borrador en negativo, se ajusta y se emite) o se **anula** (emite una rectificativa
  por el total y deja las dos en `anulada`; no se puede si está cobrada).
- **Caja:** el apunte de ingreso existe solo mientras está `pagada` (fecha de caja = `fecha_pago`); se actualiza el mismo
  apunte. Una rectificativa cobrada (devolución) apunta un ingreso negativo.

## Emisores (Ajustes › Facturación)

| Método | Ruta | Uso | Permiso |
|---|---|---|---|
| GET | `/emisores` | `{items:[{clave,nombre,prefijo,baja,defaults:{iva,irpf,venc},serie_recordada}], por_defecto}` | `ver.finanzas` |
| GET | `/emisores/ajustes` | `{items:[{clave,nombre,prefijo,fiscal:{name,nif,dir,email,phone,banco,iban,venc},iva,irpf,uso:{facturas,programaciones,apuntes,documentos,total}}], por_defecto, anio}` | `finanzas.emisores` |
| POST | `/emisores` | Guarda la lista entera `{emisores:[{clave?,nombre,prefijo?,fiscal,iva,irpf}], por_defecto}`. Clave vacía = alta (se genera). IBAN validado (mod 97). Prefijos únicos. | `finanzas.emisores` |
| DELETE | `/emisores?clave=k` | Baja. `409` si tiene histórico (con `uso`) o es el último. | `finanzas.emisores` |

## Facturas

| Método | Ruta | Uso |
|---|---|---|
| GET | `/facturas` | Filtros `emisor, client_id, estado (lista con comas), mes, desde, hasta, q, project_id, tipo, personal, limit, offset` → `{items:[FacturaFila], total, totales:{n,base,total,cobrado,pendiente}}` (los totales no cuentan borradores) |
| POST | `/facturas` | Crea borrador. `{emisor?, serie?, client_id?, cliente:{nombre,nif,dir,email,tel}, fecha?, fecha_venc?, periodo_ini?, periodo_fin?, cond_pago?, iva_pct?, irpf_pct?, efectivo?, personal?, notas?, mencion_iva?, project_id? \| project_nombre?, deal_id?, lineas:[{concepto,cantidad,precio}]}` → `201 {factura}`. Copia a `clients.fact_*` los datos no vacíos. `deal_id` repetido → `409` con `factura_id`. |
| GET | `/facturas/{id}` | `{factura}` completa: líneas con `importe`, `totales`, `emisor_snapshot`, `rectifica`, `rectificativas`, `apunte_id`, `hash`, `editable`… |
| PATCH | `/facturas/{id}` | Solo borradores (mismos campos). |
| DELETE | `/facturas/{id}` | Solo borradores sin número → `{papelera_id}`. |
| POST | `/facturas/{id}/emitir` | Numera y emite. |
| POST | `/facturas/{id}/estado` | `{estado: enviada\|vencida\|pagada, fecha_pago?}`. A/desde `pagada` exige `finanzas.cobrar`; sincroniza la caja; al cobrar llama a `notif_invoice_paid()`. |
| POST | `/facturas/{id}/duplicar` | Borrador sin número, fecha de hoy, serie recordada del emisor → `201`. |
| POST | `/facturas/{id}/rectificar` | `{motivo}` → `201` borrador rectificativo con las líneas en negativo. |
| POST | `/facturas/{id}/anular` | `{motivo}` → emite la rectificativa total y deja ambas `anulada`. |
| PATCH | `/facturas/{id}/proyecto` | `{project_id \| project_nombre}` (también en su apunte). |
| GET | `/facturas/{id}/hoja` | Datos de la hoja imprimible (los mismos para vista, PDF y portal): `{hoja:{titulo,numero,borrador,fecha,periodo_*,cliente,emisor,lineas,iva_pct,irpf_pct,totales,pago,notas_legales,notas,rectifica,hash,integra}}`. |
| GET | `/facturas/{id}/pdf` | PDF generado en el servidor: `{nombre, mime:'application/pdf', base64}`. |
| GET | `/facturas/siguiente-numero?emisor&serie&fecha&tipo` | Número previsto (sin reservar). |
| GET | `/facturas/desde-negocio/{dealId}` | Prellenado desde un negocio del CRM: `{ya:{id,numero,estado}\|null, negocio, borrador}` (lectura de `deals`, `contacts`, `billing_data`, `clients`). |
| POST | `/papelera/{id}/restaurar` | «Deshacer» de un borrador o documento borrado. |

**Portal (futuro):** `HojaFactura::paraCliente($clientId, $id)` (nunca borradores ni de otro cliente) +
`PdfFactura::generar($hoja)`; el módulo está en el contenedor como `$c->unico('finanzas', …)`.

## Navegación por carpetas y por cliente

| GET | Uso |
|---|---|
| `/explorador/hubs` | `{items:[{clave,nombre,baja,n,cobrado,pendiente}]}` |
| `/explorador/tipos?emisor=` | `{emisor, ingreso:{n,total}, gasto:{n,total}}` |
| `/explorador/meses?emisor=&tipo=ingreso\|gasto` | `{emisor, items:[{mes,n,total,neto}]}` |
| `/explorador/lista?emisor=&tipo=&mes=` | `{emisor, facturas:[FacturaFila], documentos:[Documento]}` |
| `/por-cliente` | `{items:[{client_id,nombre,n,total,neto,borradores}]}` |
| `/por-cliente/{id}` | `{cliente:{…fact_*,completo}, meses:[…]}` |
| `/clientes-facturacion` | `{items:[{id,name,fact_nombre,fact_nif,fact_dir,fact_email,fact_tel,completo,activo}], sin_datos}` |
| PATCH `/clientes-facturacion/{id}` | Sobrescribe los 5 `fact_*` (`finanzas.emitir`). |

## Documentos subidos (gastos e ingresos externos)

| Método | Ruta | Uso | Permiso |
|---|---|---|---|
| GET | `/documentos?emisor&tipo&mes` | `{items:[{id,emisor,tipo,concepto,proveedor,importe,fecha,archivo,nombre_archivo,mime,tipo_archivo,efectivo,personal,deducible,project,acc_id}]}` | `ver.finanzas` |
| POST | `/documentos` | multipart: `emisor,tipo,concepto,proveedor,importe,fecha,efectivo,personal,project_id\|project_nombre` + `archivo` (PDF/JPG/PNG/WebP por contenido real, ≤15 MB). Crea su apunte. | `conta.editar` |
| POST | `/documentos/{id}` | Edición (multipart o JSON); archivo nuevo opcional (borra el anterior). | `conta.editar` |
| DELETE | `/documentos/{id}` | A la papelera con su apunte → `{papelera_id}`. | `conta.editar` |

El archivo se sirve por `archivo.php?d=facturas&f=<archivo>[&dl=1]`.

## Programaciones (recurrentes)

| Método | Ruta | Uso |
|---|---|---|
| GET | `/programaciones` | `{items:[{id,emisor,serie,client_id,cliente,cliente_sin_datos,lineas,iva_pct,irpf_pct,cond_pago,dia,activo,start_ym,last_ym,venc_dias,project,base_mes,total_mes,proxima}]}` |
| POST / PATCH / DELETE | `/programaciones[/{id}]` | CRUD (`finanzas.programar`). `dia` 1–28, `start_ym` `AAAA-MM`, `venc_dias` 0–365 o null. |
| POST | `/programaciones/{id}/pausar` · `/activar` | |
| POST | `/programaciones/generar` | «Generar ahora» → `{generadas, facturas:[{id,numero,mes}], errores:[{programacion_id,msg}], ocupado}` |

Generación: un solo generador a la vez (`GET_LOCK`), una transacción por mes, idempotente por `(schedule_id, schedule_ym)`
(índice único). Meses atrasados: fecha de hoy y período del mes que cubren. Siguiente mes = el posterior a `last_ym`, nunca
antes de `start_ym`.

## Contabilidad y resumen

| GET | Uso | Permiso |
|---|---|---|
| `/contabilidad/movimientos?ambito=empresa\|k&anio=` | `{kpis:{ing,ing_legal,ing_efectivo,neto_ing,gas,ded,gas_personal,gas_empresa,neto_benef,bruto,margen}, items:[Movimiento], ambitos, anios}` | `ver.conta` |
| `/contabilidad/analisis?ambito&anio` | `{kpis, por_mes[12], trimestres[4], categorias, legal_vs_efectivo, deducible_socios, deducible_empresa, ambitos, anios}` | `ver.conta` |
| `/resumen?ambito&mes=AAAA-MM` | Devengo sin borradores: `{actual:{base,iva,irpf,n,gastos,facturado,impuestos,neto}, anterior, delta:{facturado,neto}, tendencia[12], facturas, ambitos}` | `ver.finanzas` |

Exportar CSV/Excel se genera en el front con los datos de `movimientos`.

## Horas

| Método | Ruta | Uso |
|---|---|---|
| GET | `/horas?admin_id&mes` | `{persona, gestor, puede_volcar, calculo:{minutos,extra_importe,base,iva,irpf,total,n_tareas,n_extras}, por_tarea, extras, pendiente:{n,minutos,importe,fecha}, hay_volcadas}` (sin ser gestor, solo uno mismo) |
| GET | `/horas/personas` | Gestor: todos; si no, solo uno mismo. |
| POST | `/horas/extras` | `{admin_id?, concepto, fecha, tipo: horas\|importe, valor}` |
| DELETE | `/horas/{id}` | `409` si ya está volcada. |
| PATCH | `/horas/personas/{id}/tarifa` | `{es_autonomo, tarifa_hora, iva_pct, irpf_pct}` (gestor) |
| POST | `/horas/volcar` | `{admin_id, mes}` → `{id, importe, minutos, msg}`; gasto de «Equipo» del último día del mes, una sola vez. |

## Proyectos

| Método | Ruta | Uso |
|---|---|---|
| GET | `/proyectos?anio=AAAA\|all` | `{items:[{id,nombre,color,activo,client_id,cliente,ing,gas,ben,nmov}], kpis, anios, sin_proyecto}` |
| POST / PATCH / DELETE | `/proyectos[/{id}]` | Crear `{nombre,color?,client_id?}`, cambiar `{nombre?,color?,activo?,client_id?}`, borrar (los movimientos quedan sin proyecto). |
| GET | `/proyectos/{id}?anio` | Ficha: `{proyecto, kpis:{ing,gas,ben,pendiente}, movimientos, facturas, anios}` |
| GET | `/proyectos/buscar?q&client_id&limit` | Combobox: `{items:[{id,nombre,color,activo,is_client,nmov}]}` |
| POST | `/proyectos/{id}/movimientos` | Apunte manual `{tipo,concepto,importe,fecha}` (`conta.editar`) |
| DELETE | `/proyectos/{id}/movimientos/{acc}` | Desvincula el apunte (`conta.editar`) |
| GET | `/proyectos/{id}/facturas-vinculables?q` | |
| POST / DELETE | `/proyectos/{id}/facturas/{inv}` | Vincular / desvincular factura (`finanzas.emitir`) |

## Cron

`Croilab\Modulos\Finanzas\Cron::ejecutar(PDO $pdo): array` → filas `{tarea, nombre, ok, detalle, ms}` para
`invoice_recurring` (programaciones) e `invoice_due` (`notif_sync_invoices()`: enviadas con vencimiento pasado → vencida
y aviso). Cada una se salta con `settings.auto_<tarea> = '0'`.
