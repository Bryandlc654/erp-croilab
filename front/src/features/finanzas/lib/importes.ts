import { eur, numero } from '../../../shared/lib/formato'

/* Dinero en céntimos enteros, con la MISMA regla que el servidor
   (backend/src/Modulos/Finanzas/Dinero.php):
     línea = round(cantidad × precio, 2) · base = Σ líneas
     IVA = round(base × iva%, 2) · IRPF = round(base × irpf%, 2) · total = base + IVA − IRPF
   Redondeo «a la mitad hacia fuera». Así la vista previa del editor da
   exactamente lo mismo que la factura guardada, el PDF y la contabilidad. */

/* Texto decimal («1234.56», «-3.5», «21») → céntimos (o centésimas). */
export function c(v: string | number | null | undefined): number {
  if (v === null || v === undefined || v === '') return 0
  const s = typeof v === 'number' ? v.toFixed(6) : v.trim()
  const m = /^(-?)(\d*)(?:\.(\d*))?$/.exec(s)
  if (!m) return 0
  const dec = (m[3] ?? '').padEnd(3, '0')
  const abs = Number(m[2] || '0') * 100 + Number(dec.slice(0, 2)) + (Number(dec[2]) >= 5 ? 1 : 0)
  return m[1] === '-' ? -abs : abs
}

/* División entera redondeando la mitad hacia fuera. */
function dividir(x: number, d: number) {
  const abs = Math.floor((Math.abs(x) + d / 2) / d)
  return x < 0 ? -abs : abs
}

export function linea(cantidad: string, precio: string) {
  return dividir(c(cantidad) * c(precio), 100)
}

export function cuota(base: number, pct: string | number) {
  return dividir(base * c(pct), 10000)
}

export type Totales = { base: number; iva: number; irpf: number; total: number }

export function totales(lineas: { cantidad: string; precio: string; concepto?: string }[], iva: string | number, irpf: string | number): Totales {
  const base = lineas.reduce((s, l) => s + linea(l.cantidad, l.precio), 0)
  const ci = cuota(base, iva)
  const cr = cuota(base, irpf)
  return { base, iva: ci, irpf: cr, total: base + ci - cr }
}

/* Céntimos → «1234.56» (para mandar a la API). */
export function decimal(cent: number) {
  const s = cent < 0 ? '-' : ''
  const a = Math.abs(cent)
  return `${s}${Math.floor(a / 100)}.${String(a % 100).padStart(2, '0')}`
}

/* Lee lo que escribe una persona («1.234,56», «12,5», «1234.5») y lo deja como
   «1234.56». El último separador es el decimal; «1.234» sin otro separador son
   miles (como num_es del ERP). null si no hay número. */
export function leer(v: string | number | null | undefined): string | null {
  if (typeof v === 'number') return Number.isFinite(v) ? decimal(c(v)) : null
  if (v === null || v === undefined) return null
  let s = v.trim().replace(/[^0-9,.-]/g, '')
  if (s === '' || s === '-') return null
  const neg = s.startsWith('-')
  s = s.replace(/-/g, '')
  const coma = s.lastIndexOf(',')
  const punto = s.lastIndexOf('.')
  const ult = Math.max(coma, punto)
  let ent = s
  let dec = ''
  if (ult >= 0) {
    dec = s.slice(ult + 1)
    ent = s.slice(0, ult)
    const otro = coma >= 0 && punto >= 0
    if (!otro && dec.length === 3 && ent.replace(/^0+/, '') !== '' && /^\d+$/.test(dec)) {
      ent += dec
      dec = ''
    }
  }
  ent = ent.replace(/\D/g, '') || '0'
  dec = dec.replace(/\D/g, '')
  return decimal(c(`${neg ? '-' : ''}${ent}${dec ? '.' + dec : ''}`))
}

/* Céntimos → «1.234,56 €». */
export function eurC(cent: number) {
  return eur(cent / 100)
}

/* Céntimos → «1.235 €» (KPIs). */
export function eurC0(cent: number) {
  return eur(Math.round(cent / 100), 0)
}

/* «1234.50» → «1.234,50» (para rellenar un campo). */
export function aTexto(dec: string | null | undefined, decimales = 2) {
  if (dec === null || dec === undefined || dec === '') return ''
  return numero(c(dec) / 100, decimales)
}

/* Cantidad sin ceros sobrantes: «1.00» → «1», «1.50» → «1,5». */
export function cantidadCorta(dec: string) {
  const v = c(dec)
  if (v % 100 === 0) return String(v / 100)
  return numero(v / 100, 2).replace(/0$/, '')
}

/* Porcentaje para mostrar: «7.50» → «7,5». */
export function pctCorto(v: string | number) {
  const x = c(typeof v === 'number' ? String(v) : v)
  return x % 100 === 0 ? String(x / 100) : numero(x / 100, 2).replace(/0$/, '')
}

/* Variación en % con su dirección (null si no hay con qué comparar). */
export function delta(p: number | null) {
  if (p === null) return undefined
  return { value: `${p > 0 ? '+' : ''}${p}%`, dir: p >= 0 ? ('up' as const) : ('down' as const) }
}
