import { z } from 'zod'

/* Contrato de la API de Finanzas (docs/migracion/api/finanzas.md).
   Todos los importes en CÉNTIMOS enteros; cantidades, precios y % como texto
   decimal («1234.56», «21»). */

const Cent = z.number().int()
const Fecha = z.string().nullable()
const Ok = z.object({ ok: z.literal(true) })

export const ESTADOS = ['borrador', 'enviada', 'pagada', 'vencida', 'anulada'] as const
export type EstadoFactura = (typeof ESTADOS)[number]

export const ProyectoMini = z.object({ id: z.number().int(), nombre: z.string(), color: z.string() })
export type ProyectoMini = z.infer<typeof ProyectoMini>

/* ---------- Emisores ---------- */

export const EmisorLista = z.object({
  clave: z.string(),
  nombre: z.string(),
  prefijo: z.string(),
  baja: z.boolean(),
  defaults: z.object({ iva: z.string(), irpf: z.string(), venc: z.string() }),
  serie_recordada: z.string(),
})
export type EmisorLista = z.infer<typeof EmisorLista>
export const EmisoresRespuesta = Ok.extend({ items: z.array(EmisorLista), por_defecto: z.string() })

export const Fiscal = z.object({ name: z.string(), nif: z.string(), dir: z.string(), email: z.string(), phone: z.string(), banco: z.string(), iban: z.string(), venc: z.string() })
export type Fiscal = z.infer<typeof Fiscal>
export const EmisorAjuste = z.object({
  clave: z.string(),
  nombre: z.string(),
  prefijo: z.string(),
  fiscal: Fiscal,
  iva: z.string(),
  irpf: z.string(),
  uso: z.object({ facturas: z.number(), programaciones: z.number(), apuntes: z.number(), documentos: z.number(), total: z.number() }),
})
export type EmisorAjuste = z.infer<typeof EmisorAjuste>
export const EmisoresAjustesRespuesta = Ok.extend({ items: z.array(EmisorAjuste), por_defecto: z.string(), anio: z.number() })

/* ---------- Facturas ---------- */

export const FacturaFila = z.object({
  id: z.number().int(),
  numero: z.string().nullable(),
  serie: z.string(),
  tipo: z.enum(['normal', 'rectificativa']),
  estado: z.enum(ESTADOS),
  emisor: z.string(),
  client_id: z.number().int().nullable(),
  cliente_nombre: z.string(),
  fecha: Fecha,
  fecha_venc: Fecha,
  fecha_pago: Fecha,
  iva_pct: z.string(),
  irpf_pct: z.string(),
  efectivo: z.boolean(),
  personal: z.boolean(),
  project: ProyectoMini.nullable(),
  rectifica_id: z.number().int().nullable(),
  base: Cent,
  iva: Cent,
  irpf: Cent,
  total: Cent,
})
export type FacturaFila = z.infer<typeof FacturaFila>

export const TotalesSchema = z.object({ base: Cent, iva: Cent, irpf: Cent, total: Cent })

export const FacturasRespuesta = Ok.extend({
  items: z.array(FacturaFila),
  total: z.number().int(),
  totales: z.object({ n: z.number(), base: Cent, total: Cent, cobrado: Cent, pendiente: Cent }),
})

export const LineaGuardada = z.object({ id: z.number().int(), concepto: z.string(), cantidad: z.string(), precio: z.string(), importe: Cent })
export const ClienteFiscal = z.object({ nombre: z.string(), nif: z.string(), dir: z.string(), email: z.string(), tel: z.string() })
export type ClienteFiscal = z.infer<typeof ClienteFiscal>

export const Factura = z.object({
  id: z.number().int(),
  numero: z.string().nullable(),
  serie: z.string(),
  tipo: z.enum(['normal', 'rectificativa']),
  estado: z.enum(ESTADOS),
  editable: z.boolean(),
  emisor: z.string(),
  emisor_nombre: z.string(),
  emisor_snapshot: z.record(z.string(), z.unknown()).nullable(),
  client_id: z.number().int().nullable(),
  cliente: ClienteFiscal,
  fecha: z.string(),
  fecha_venc: Fecha,
  fecha_pago: Fecha,
  periodo_ini: Fecha,
  periodo_fin: Fecha,
  cond_pago: z.string(),
  iva_pct: z.string(),
  irpf_pct: z.string(),
  efectivo: z.boolean(),
  personal: z.boolean(),
  notas: z.string(),
  mencion_iva: z.string(),
  project: ProyectoMini.nullable(),
  lineas: z.array(LineaGuardada),
  totales: TotalesSchema,
  rectifica: z.object({ id: z.number().int(), numero: z.string().nullable(), fecha: z.string().nullable() }).nullable(),
  rect_motivo: z.string(),
  rectificativas: z.array(z.object({ id: z.number().int(), numero: z.string().nullable(), estado: z.enum(ESTADOS), fecha: Fecha, total: Cent })),
  emitida_at: z.string().nullable(),
  anulada_at: z.string().nullable(),
  anulada_motivo: z.string(),
  apunte_id: z.number().int().nullable(),
  schedule_id: z.number().int().nullable(),
  deal_id: z.number().int().nullable(),
  hash: z.string().nullable(),
  created_at: z.string().nullable(),
})
export type Factura = z.infer<typeof Factura>
export const FacturaRespuesta = Ok.extend({ factura: Factura })

export const Hoja = z.object({
  id: z.number().int(),
  numero: z.string().nullable(),
  tipo: z.string(),
  estado: z.string(),
  borrador: z.boolean(),
  titulo: z.string(),
  fecha: z.string(),
  fecha_venc: Fecha,
  periodo_ini: Fecha,
  periodo_fin: Fecha,
  cliente: z.object({ nombre: z.string(), nif: z.string(), tel: z.string(), dir: z.string(), email: z.string() }),
  emisor: z.object({ name: z.string(), nif: z.string(), dir: z.string(), email: z.string(), phone: z.string(), iban: z.string(), banco: z.string() }),
  lineas: z.array(z.object({ concepto: z.string(), cantidad: z.string(), precio: z.string(), importe: Cent })),
  iva_pct: z.string(),
  irpf_pct: z.string(),
  totales: TotalesSchema,
  pago: z.object({ banco: z.string(), titular: z.string(), forma: z.string(), condiciones: z.string(), fecha_venc: Fecha, iban: z.string() }),
  notas_legales: z.array(z.string()),
  notas: z.string(),
  rectifica: z.object({ numero: z.string(), fecha: z.string().nullable(), motivo: z.string() }).nullable(),
  hash: z.string().nullable(),
  integra: z.boolean().nullable(),
})
export type Hoja = z.infer<typeof Hoja>
export const HojaRespuesta = Ok.extend({ hoja: Hoja })
export const PdfRespuesta = Ok.extend({ nombre: z.string(), mime: z.string(), base64: z.string() })
export const NumeroRespuesta = Ok.extend({ numero: z.string() })
export const BorradoRespuesta = Ok.extend({ papelera_id: z.number().int() })
export const VacioRespuesta = Ok

export const NegocioRespuesta = Ok.extend({
  ya: z.object({ id: z.number().int(), numero: z.string().nullable(), estado: z.string() }).nullable(),
  negocio: z.object({ id: z.number().int(), nombre: z.string() }),
  borrador: z.object({
    deal_id: z.number().int(),
    client_id: z.number().int().nullable(),
    cliente: ClienteFiscal,
    emisor: z.string(),
    serie: z.string(),
    iva_pct: z.string(),
    irpf_pct: z.string(),
    cond_pago: z.string(),
    lineas: z.array(z.object({ concepto: z.string(), cantidad: z.string(), precio: z.string() })),
  }),
})

/* ---------- Explorador ---------- */

export const HubsRespuesta = Ok.extend({
  items: z.array(z.object({ clave: z.string(), nombre: z.string(), baja: z.boolean(), n: z.number(), cobrado: Cent, pendiente: Cent })),
})
const EmisorCab = z.object({ clave: z.string(), nombre: z.string(), baja: z.boolean() })
export const TiposRespuesta = Ok.extend({ emisor: EmisorCab, ingreso: z.object({ n: z.number(), total: Cent }), gasto: z.object({ n: z.number(), total: Cent }) })
export const Carpeta = z.object({ mes: z.string(), n: z.number(), total: Cent, neto: Cent })
export type Carpeta = z.infer<typeof Carpeta>
export const MesesRespuesta = Ok.extend({ emisor: EmisorCab, items: z.array(Carpeta) })

export const Documento = z.object({
  id: z.number().int(),
  emisor: z.string(),
  tipo: z.enum(['gasto', 'ingreso']),
  concepto: z.string(),
  proveedor: z.string(),
  importe: Cent,
  fecha: Fecha,
  archivo: z.string().nullable(),
  nombre_archivo: z.string(),
  mime: z.string(),
  tipo_archivo: z.enum(['pdf', 'imagen']).nullable(),
  efectivo: z.boolean(),
  personal: z.boolean(),
  deducible: z.boolean(),
  project: ProyectoMini.nullable(),
  acc_id: z.number().int().nullable(),
})
export type Documento = z.infer<typeof Documento>
export const ListaRespuesta = Ok.extend({ emisor: EmisorCab, facturas: z.array(FacturaFila), documentos: z.array(Documento) })
export const DocumentoRespuesta = Ok.extend({ documento: Documento })

export const PorClienteRespuesta = Ok.extend({
  items: z.array(z.object({ client_id: z.number().int(), nombre: z.string(), n: z.number(), total: Cent, neto: Cent, borradores: z.number() })),
})
export const ClienteFacturacion = z.object({
  id: z.number().int(),
  name: z.string(),
  fact_nombre: z.string(),
  fact_nif: z.string(),
  fact_dir: z.string(),
  fact_email: z.string(),
  fact_tel: z.string(),
  completo: z.boolean(),
  activo: z.boolean().optional(),
})
export type ClienteFacturacion = z.infer<typeof ClienteFacturacion>
export const ClienteMesesRespuesta = Ok.extend({ cliente: ClienteFacturacion, meses: z.array(Carpeta) })
export const ClientesFacturacionRespuesta = Ok.extend({ items: z.array(ClienteFacturacion), sin_datos: z.number() })
export const ClienteFacturacionRespuesta = Ok.extend({ cliente: ClienteFacturacion })

/* ---------- Programaciones ---------- */

export const Programacion = z.object({
  id: z.number().int(),
  emisor: z.string(),
  emisor_nombre: z.string(),
  serie: z.string(),
  client_id: z.number().int().nullable(),
  cliente: ClienteFiscal,
  cliente_sin_datos: z.boolean(),
  lineas: z.array(z.object({ concepto: z.string(), cantidad: z.string(), precio: z.string(), importe: Cent })),
  iva_pct: z.string(),
  irpf_pct: z.string(),
  cond_pago: z.string(),
  dia: z.number().int(),
  activo: z.boolean(),
  start_ym: z.string(),
  last_ym: z.string().nullable(),
  venc_dias: z.number().int().nullable(),
  project_id: z.number().int().nullable(),
  project: ProyectoMini.nullable(),
  base_mes: Cent,
  total_mes: Cent,
  proxima: z.string().nullable(),
})
export type Programacion = z.infer<typeof Programacion>
export const ProgramacionesRespuesta = Ok.extend({ items: z.array(Programacion) })
export const ProgramacionRespuesta = Ok.extend({ programacion: Programacion })
export const GenerarRespuesta = Ok.extend({
  generadas: z.number(),
  facturas: z.array(z.object({ id: z.number(), numero: z.string().nullable(), mes: z.string() })),
  errores: z.array(z.object({ programacion_id: z.number(), msg: z.string() })),
  ocupado: z.boolean(),
})

/* ---------- Contabilidad y resumen ---------- */

const Ambito = z.object({ clave: z.string(), nombre: z.string() })
export const Movimiento = z.object({
  id: z.number().int(),
  fecha: Fecha,
  tipo: z.enum(['ingreso', 'gasto']),
  concepto: z.string(),
  categoria: z.string(),
  ambito: z.string(),
  ambito_nombre: z.string(),
  importe: Cent,
  metodo: z.string(),
  legal: z.boolean(),
  deducible: z.boolean(),
  personal: z.boolean(),
  project: ProyectoMini.nullable(),
  cliente_nombre: z.string().nullable(),
  proveedor: z.string().nullable(),
  persona: z.string().nullable(),
  origen: z.enum(['factura', 'documento', 'horas', 'manual']),
  factura: z.object({ id: z.number().int(), numero: z.string().nullable() }).nullable(),
  documento: z.object({ id: z.number().int(), emisor: z.string(), tipo: z.string(), mes: z.string(), archivo: z.string().nullable() }).nullable(),
})
export type Movimiento = z.infer<typeof Movimiento>
export const MovimientosRespuesta = Ok.extend({
  ambito: z.string(),
  anio: z.number(),
  kpis: z.object({
    ing: Cent, ing_legal: Cent, ing_efectivo: Cent, neto_ing: Cent, gas: Cent, ded: Cent, gas_personal: Cent, gas_empresa: Cent, neto_benef: Cent, bruto: Cent, margen: z.number(),
  }),
  items: z.array(Movimiento),
  ambitos: z.array(Ambito),
  anios: z.array(z.number()),
})
export type Movimientos = z.infer<typeof MovimientosRespuesta>

export const AnalisisRespuesta = Ok.extend({
  ambito: z.string(),
  anio: z.number(),
  kpis: z.object({ ing: Cent, ing_legal: Cent, ing_efectivo: Cent, gas: Cent, ded: Cent, benef: Cent, margen: z.number() }),
  por_mes: z.array(z.object({ ing: Cent, gas: Cent })),
  trimestres: z.array(z.object({ ing: Cent, gas: Cent, ben: Cent, margen: z.number() })),
  categorias: z.array(z.object({ categoria: z.string(), total: Cent })),
  legal_vs_efectivo: z.object({ legal: Cent, efectivo: Cent, ratio: z.number() }),
  deducible_socios: z.array(z.object({ ambito: z.string(), nombre: z.string(), total: Cent })),
  deducible_empresa: Cent,
  ambitos: z.array(Ambito),
  anios: z.array(z.number()),
})
export type Analisis = z.infer<typeof AnalisisRespuesta>

const CalculoMes = z.object({ base: Cent, iva: Cent, irpf: Cent, n: z.number(), gastos: Cent, facturado: Cent, impuestos: Cent, neto: Cent })
export const ResumenRespuesta = Ok.extend({
  ambito: z.string(),
  mes: z.string(),
  actual: CalculoMes,
  anterior: CalculoMes,
  delta: z.object({ facturado: z.number().nullable(), neto: z.number().nullable() }),
  tendencia: z.array(z.object({ mes: z.string(), base: Cent })),
  facturas: z.array(FacturaFila),
  ambitos: z.array(Ambito),
})
export type Resumen = z.infer<typeof ResumenRespuesta>

/* ---------- Horas ---------- */

export const PersonaHoras = z.object({ id: z.number().int(), username: z.string(), es_autonomo: z.boolean(), tarifa_hora: Cent, iva_pct: z.string(), irpf_pct: z.string() })
export type PersonaHoras = z.infer<typeof PersonaHoras>
export const PersonasHorasRespuesta = Ok.extend({ items: z.array(PersonaHoras) })
export const HorasRespuesta = Ok.extend({
  persona: PersonaHoras,
  mes: z.string(),
  gestor: z.boolean(),
  puede_volcar: z.boolean(),
  calculo: z.object({ minutos: z.number(), extra_importe: Cent, base: Cent, iva: Cent, irpf: Cent, total: Cent, n_tareas: z.number(), n_extras: z.number() }),
  por_tarea: z.array(z.object({ task_id: z.number(), titulo: z.string(), cliente: z.string().nullable(), n: z.number(), minutos: z.number(), importe: Cent })),
  extras: z.array(z.object({ id: z.number(), concepto: z.string(), fecha: z.string(), minutos: z.number(), importe: Cent.nullable(), valor: Cent, volcada: z.boolean() })),
  pendiente: z.object({ n: z.number(), minutos: z.number(), importe: Cent, fecha: z.string() }),
  hay_volcadas: z.boolean(),
})
export type Horas = z.infer<typeof HorasRespuesta>
export const VolcadoRespuesta = Ok.extend({ id: z.number(), importe: Cent, minutos: z.number(), msg: z.string() })
export const IdRespuesta = Ok.extend({ id: z.number().int() })
export const PersonaRespuesta = Ok.extend({ persona: PersonaHoras })

/* ---------- Proyectos ---------- */

export const ProyectoFila = z.object({
  id: z.number().int(),
  nombre: z.string(),
  color: z.string(),
  activo: z.boolean(),
  client_id: z.number().int().nullable(),
  cliente: z.string().nullable(),
  ing: Cent,
  gas: Cent,
  ben: Cent,
  nmov: z.number(),
})
export type ProyectoFila = z.infer<typeof ProyectoFila>
export const ProyectosRespuesta = Ok.extend({
  items: z.array(ProyectoFila),
  kpis: z.object({ ing: Cent, gas: Cent, ben: Cent }),
  anios: z.array(z.number()),
  sin_proyecto: z.object({ ing: Cent, gas: Cent, nmov: z.number() }),
})
const ProyectoDatos = z.object({ id: z.number().int(), nombre: z.string(), color: z.string(), activo: z.boolean(), client_id: z.number().int().nullable(), cliente: z.string().nullable() })
export type ProyectoDatos = z.infer<typeof ProyectoDatos>
export const ProyectoRespuesta = Ok.extend({ proyecto: ProyectoDatos })
export const FichaProyectoRespuesta = Ok.extend({
  proyecto: ProyectoDatos,
  anios: z.array(z.number()),
  kpis: z.object({ ing: Cent, gas: Cent, ben: Cent, pendiente: Cent }),
  movimientos: z.array(
    z.object({
      id: z.number().int(),
      fecha: Fecha,
      tipo: z.enum(['ingreso', 'gasto']),
      concepto: z.string(),
      importe: Cent,
      ambito: z.string(),
      factura: z.object({ id: z.number().int(), numero: z.string().nullable(), estado: z.string() }).nullable(),
      documento: z.object({ id: z.number().int(), filename: z.string().nullable(), nombre: z.string() }).nullable(),
    }),
  ),
  facturas: z.array(z.object({ id: z.number().int(), numero: z.string().nullable(), cliente_nombre: z.string(), fecha: Fecha, estado: z.enum(ESTADOS), total: Cent })),
})
export type FichaProyecto = z.infer<typeof FichaProyectoRespuesta>
export const BuscarProyectosRespuesta = Ok.extend({
  items: z.array(z.object({ id: z.number().int(), nombre: z.string(), color: z.string(), activo: z.boolean(), is_client: z.boolean(), nmov: z.number() })),
})
export const VinculablesRespuesta = Ok.extend({
  items: z.array(z.object({ id: z.number().int(), numero: z.string().nullable(), cliente_nombre: z.string(), estado: z.enum(ESTADOS), total: Cent, vinculada: z.boolean() })),
})
