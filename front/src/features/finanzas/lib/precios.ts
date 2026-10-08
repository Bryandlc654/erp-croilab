import { c, cuota, linea } from './importes'

/* Calculadora de precios (pricing.php): presupuesto, coste interno y margen.
   Todo en céntimos. Solo cliente, sin persistencia. */

export type LineaPrecio = { concepto: string; cantidad: string; precio: string }

export type EntradaPrecios = {
  lineas: LineaPrecio[]
  descuentoPct: string
  ivaPct: string
  horas: string
  costeHora: string
  fijos: string
  margenObjetivo: string
}

export type Aviso = { tono: 'info' | 'error' | 'warn' | 'ok'; texto: string }

export function calcularPrecios(e: EntradaPrecios) {
  const subtotal = e.lineas.reduce((s, l) => s + linea(l.cantidad || '0', l.precio || '0'), 0)
  const descuento = cuota(subtotal, e.descuentoPct || '0')
  const base = subtotal - descuento
  const iva = cuota(base, e.ivaPct || '0')
  const total = base + iva
  const coste = linea(e.horas || '0', e.costeHora || '0') + c(e.fijos || '0')
  const beneficio = base - coste
  const margenPct = base > 0 ? Math.round((beneficio / base) * 100) : 0
  let aviso: Aviso
  if (coste === 0) aviso = { tono: 'info', texto: 'Añade el coste interno para ver tu margen real.' }
  else if (beneficio < 0) aviso = { tono: 'error', texto: 'Estás perdiendo dinero: el precio no cubre el coste.' }
  else if (margenPct < 35) aviso = { tono: 'warn', texto: 'Margen ajustado. Lo sano en agencia suele ser 40–60%.' }
  else aviso = { tono: 'ok', texto: 'Margen saludable sobre la base imponible.' }
  /* Precio (base) recomendado para el margen objetivo: coste / (1 − m), con m ≤ 95 %. */
  const m = Math.min(95, Math.max(0, c(e.margenObjetivo || '0') / 100))
  const recomendado = coste > 0 ? Math.round(coste / (1 - m / 100)) : 0
  return { subtotal, descuento, base, iva, total, coste, beneficio, margenPct, aviso, recomendado }
}
