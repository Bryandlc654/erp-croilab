/* Formato de cifras y fechas como el ERP antiguo (eur, mes_label, fechas
   dd/mm/aa…). Todo a mano y no con Intl: es-ES no agrupa los miles de las
   cifras de 4 dígitos («1234 €») y el ERP siempre lo hace («1.234 €»). */

const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']
const MESES_CORTOS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic']

/* 1234.5 → «1.234,50» (miles con punto, decimales con coma). */
export function numero(n: number, decimales = 0) {
  if (!Number.isFinite(n)) return '0'
  const negativo = n < 0
  const [ent, dec] = Math.abs(n).toFixed(decimales).split('.')
  const miles = ent.replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  // «-0,00» no aporta nada: si redondea a cero va sin signo.
  const cero = Number(Math.abs(n).toFixed(decimales)) === 0
  return (negativo && !cero ? '-' : '') + miles + (dec ? ',' + dec : '')
}

/* Dinero con céntimos: «1.234,56 €». */
export function eur(n: number, decimales = 2) {
  return `${numero(n, decimales)} €`
}

/* Dinero sin céntimos (KPIs): «1.235 €». */
export function eur0(n: number) {
  return eur(n, 0)
}

/* Dinero compacto para gráficas estrechas: «12,4K €» (sin «,0»). */
export function eurk(n: number) {
  if (Math.abs(n) < 1000) return eur0(n)
  return `${numero(n / 1000, 1).replace(/,0$/, '')}K €`
}

/* 1 → «Enero». */
export function mesNombre(mes: number) {
  return MESES[(((mes - 1) % 12) + 12) % 12]
}

/* '2026-03' (o '2026-03-14') → «Marzo 2026». */
export function mesLabel(ym: string) {
  const m = /^(\d{4})-(\d{1,2})/.exec(ym)
  if (!m) return ''
  return `${mesNombre(Number(m[2]))} ${m[1]}`
}

/* Lee 'AAAA-MM-DD' o 'AAAA-MM-DD HH:MM[:SS]' (formato de MySQL) como hora
   LOCAL: new Date('2026-03-14') lo tomaría en UTC y en España saldría el día
   anterior por la noche. */
export function aFecha(valor: string | Date | null | undefined): Date | null {
  if (!valor) return null
  if (valor instanceof Date) return Number.isNaN(valor.getTime()) ? null : valor
  const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/.exec(valor)
  if (m && !/[zZ]|[+-]\d{2}:?\d{2}$/.test(valor.slice(10))) {
    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4] ?? 0), Number(m[5] ?? 0), Number(m[6] ?? 0))
    return Number.isNaN(d.getTime()) ? null : d
  }
  const d = new Date(valor)
  return Number.isNaN(d.getTime()) ? null : d
}

const dos = (n: number) => String(n).padStart(2, '0')

/* Fecha a ISO 'AAAA-MM-DD' en hora local. */
export function isoDia(d: Date) {
  return `${d.getFullYear()}-${dos(d.getMonth() + 1)}-${dos(d.getDate())}`
}

/* «14/03/26». `vacio` es lo que se enseña si no hay fecha. */
export function fechaCorta(valor: string | Date | null | undefined, vacio = '') {
  const d = aFecha(valor)
  if (!d) return vacio
  return `${dos(d.getDate())}/${dos(d.getMonth() + 1)}/${String(d.getFullYear()).slice(2)}`
}

/* «14 de marzo de 2026». */
export function fechaLarga(valor: string | Date | null | undefined, vacio = '') {
  const d = aFecha(valor)
  if (!d) return vacio
  return `${d.getDate()} de ${MESES[d.getMonth()].toLowerCase()} de ${d.getFullYear()}`
}

function hora(d: Date) {
  return `${d.getHours()}:${dos(d.getMinutes())}`
}

function mismoDia(a: Date, b: Date) {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
}

/* Hora relativa de comentarios y avisos: «justo ahora», «hace 5 minutos»,
   «hace 2 horas», «ayer a las 9:05», «27 de jul. a las 14:30» y, de otro año,
   «27 de jul. de 2025». */
export function horaRelativa(valor: string | Date | null | undefined, ahora: Date = new Date()) {
  const d = aFecha(valor)
  if (!d) return ''
  const seg = Math.round((ahora.getTime() - d.getTime()) / 1000)
  if (seg < 60) return 'justo ahora'
  if (seg < 3600) {
    const m = Math.floor(seg / 60)
    return `hace ${m} ${m === 1 ? 'minuto' : 'minutos'}`
  }
  if (mismoDia(d, ahora)) {
    const h = Math.floor(seg / 3600)
    return `hace ${h} ${h === 1 ? 'hora' : 'horas'}`
  }
  const ayer = new Date(ahora.getFullYear(), ahora.getMonth(), ahora.getDate() - 1)
  if (mismoDia(d, ayer)) return `ayer a las ${hora(d)}`
  const dia = `${d.getDate()} de ${MESES_CORTOS[d.getMonth()]}.`
  if (d.getFullYear() !== ahora.getFullYear()) return `${dia} de ${d.getFullYear()}`
  return `${dia} a las ${hora(d)}`
}

/* Tiempo transcurrido corto (presencia): «ahora», «hace 5 min», «hace 3 h», «hace 2 d». */
export function haceTiempo(segundos: number) {
  const s = Math.max(0, Math.floor(segundos))
  if (s < 60) return 'ahora'
  if (s < 3600) return `hace ${Math.floor(s / 60)} min`
  if (s < 86400) return `hace ${Math.floor(s / 3600)} h`
  return `hace ${Math.floor(s / 86400)} d`
}
