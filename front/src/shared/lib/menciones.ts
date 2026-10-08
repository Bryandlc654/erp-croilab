import { normalizar } from './emojis'

/* Lógica de las menciones @ (task.php / chat.php del antiguo), sin DOM. */

export type PersonaMencion = { id: number | string; username: string; foto?: string | null }

/* Igual que el antiguo: @ + letras, números, «_», «.» y «-» justo antes del cursor. */
const RE_CONSULTA = /(^|[^\p{L}0-9_.-])@([\p{L}0-9_.-]*)$/u

/* Texto que se está escribiendo tras una @ (o null si no hay mención abierta). */
export function consultaMencion(antesDelCursor: string): string | null {
  const m = RE_CONSULTA.exec(antesDelCursor)
  return m ? m[2] : null
}

/* Primero quien empieza por lo escrito, luego quien lo contiene; máx. `max`. */
export function filtrarPersonas<T extends PersonaMencion>(people: T[], consulta: string, max = 6): T[] {
  const q = normalizar(consulta)
  if (!q) return people.slice(0, max)
  const empiezan: T[] = []
  const contienen: T[] = []
  for (const p of people) {
    const n = normalizar(p.username)
    if (n.startsWith(q)) empiezan.push(p)
    else if (n.includes(q)) contienen.push(p)
  }
  return [...empiezan, ...contienen].slice(0, max)
}

/* Sustituye la «@consulta» que hay antes del cursor por «@usuario » y
   devuelve el texto nuevo y dónde queda el cursor. */
export function insertarMencion(valor: string, cursor: number, username: string): { valor: string; cursor: number } {
  const antes = valor.slice(0, cursor)
  const q = consultaMencion(antes)
  if (q === null) return { valor, cursor }
  const inicio = cursor - q.length - 1
  const texto = `@${username} `
  // Si ya había un espacio detrás, no se duplica.
  const despues = valor.slice(cursor)
  const fin = despues.startsWith(' ') ? texto.slice(0, -1) : texto
  return { valor: valor.slice(0, inicio) + fin + despues, cursor: inicio + texto.length }
}
