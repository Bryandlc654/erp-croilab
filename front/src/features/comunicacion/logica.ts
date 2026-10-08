/* Lógica pura de Comunicación (probada en logica.test.ts): fechas cortas del
   chat, vista previa de las salas, agrupación de mensajes, trozos del texto
   (enlaces y menciones), invitados, WhatsApp y fechas relativas. */

import type { AvisoChatT, CorreoSugerido, Mensaje, Sala } from './schemas'

const dos = (n: number) => String(n).padStart(2, '0')

/* 'AAAA-MM-DD HH:MM:SS' (hora local del servidor = Madrid) → Date local. */
export function aFecha(s: string): Date {
  const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/.exec(s)
  if (!m) return new Date(NaN)
  return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] ?? 0), +(m[5] ?? 0), +(m[6] ?? 0))
}

export function isoDia(d: Date) {
  return `${d.getFullYear()}-${dos(d.getMonth() + 1)}-${dos(d.getDate())}`
}

const DIAS_CORTOS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb']

/* Hora de la lista de salas: «HH:MM» hoy, «ayer», «lun…dom» hasta 6 días, si no «dd/mm». */
export function horaCorta(s: string, ahora: Date = new Date()) {
  const d = aFecha(s)
  if (Number.isNaN(d.getTime())) return ''
  const hoy = new Date(ahora.getFullYear(), ahora.getMonth(), ahora.getDate())
  if (d >= hoy) return `${dos(d.getHours())}:${dos(d.getMinutes())}`
  const dias = Math.round((hoy.getTime() - new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()) / 86_400_000)
  if (dias === 1) return 'ayer'
  if (dias <= 6) return DIAS_CORTOS[d.getDay()]
  return `${dos(d.getDate())}/${dos(d.getMonth() + 1)}`
}

export function horaMensaje(s: string) {
  const d = aFecha(s)
  return `${dos(d.getHours())}:${dos(d.getMinutes())}`
}

/* Separador de día del feed: «Hoy», «Ayer» o «dd/mm/aaaa». */
export function etiquetaDia(dia: string, ahora: Date = new Date()) {
  const hoy = isoDia(ahora)
  const ayer = isoDia(new Date(ahora.getFullYear(), ahora.getMonth(), ahora.getDate() - 1))
  if (dia === hoy) return 'Hoy'
  if (dia === ayer) return 'Ayer'
  const [a, m, d] = dia.split('-')
  return `${d}/${m}/${a}`
}

/* Vista previa de una sala: «Tú: …», «Nombre: …» en grupos, «Mensaje eliminado», «📎 Adjunto», «N miembros». */
export function vistaPrevia(s: Sala, yo: number) {
  const u = s.ultimo
  if (!u) return s.tipo === 'grupo' ? `${s.miembros.length} miembros` : ''
  if (u.borrado) return 'Mensaje eliminado'
  const cuerpo = u.texto.replace(/\*\*|__|`/g, '') || (u.adjunto ? '📎 Adjunto' : '')
  if (u.autor_id === yo) return `Tú: ${cuerpo}`
  if (s.tipo === 'grupo') return `${u.autor}: ${cuerpo}`
  return cuerpo
}

/* Mensaje del feed con lo que hace falta para pintarlo: separador de día y si
   continúa al anterior (mismo autor, mismo día, < 5 min: sin avatar ni nombre). */
export type MensajeFeed = { m: Mensaje; dia: string; nuevoDia: boolean; continua: boolean }

export function agruparMensajes(ms: Mensaje[]): MensajeFeed[] {
  const out: MensajeFeed[] = []
  let ant: Mensaje | null = null
  for (const m of ms) {
    const dia = m.creado.slice(0, 10)
    const nuevoDia = !ant || ant.creado.slice(0, 10) !== dia
    const continua = !!ant && !nuevoDia && ant.autor_id === m.autor_id && !ant.borrado && aFecha(m.creado).getTime() - aFecha(ant.creado).getTime() < 5 * 60_000
    out.push({ m, dia, nuevoDia, continua })
    ant = m
  }
  return out
}

/* Junta mensajes (nuevos o cambiados) en la lista: sustituye por id y ordena. */
export function fusionarMensajes(lista: Mensaje[], llegan: Mensaje[]): Mensaje[] {
  if (!llegan.length) return lista
  const porId = new Map(lista.map((m) => [m.id, m]))
  for (const m of llegan) porId.set(m.id, m)
  return [...porId.values()].sort((a, b) => a.id - b.id)
}

/* Trozos del texto de un mensaje: texto, enlaces http(s) y @menciones del equipo. */
export type Trozo = { t: 'texto'; v: string } | { t: 'enlace'; v: string } | { t: 'mencion'; v: string; yo: boolean }

export function trozosTexto(texto: string, nombres: string[], yo: string): Trozo[] {
  const conocidos = new Set(nombres.map((n) => n.toLowerCase()))
  const re = /(https?:\/\/[^\s<]+)|(^|[^\p{L}\d_.-])@([\p{L}\d_.-]{2,40})/gu
  const out: Trozo[] = []
  let ultimo = 0
  const push = (v: string) => {
    if (!v) return
    const a = out[out.length - 1]
    if (a && a.t === 'texto') a.v += v
    else out.push({ t: 'texto', v })
  }
  for (let m = re.exec(texto); m; m = re.exec(texto)) {
    if (m[1]) {
      const url = m[1].replace(/[.,);:!?]+$/, '')
      push(texto.slice(ultimo, m.index))
      out.push({ t: 'enlace', v: url })
      ultimo = m.index + url.length
      re.lastIndex = ultimo
    } else {
      const nombre = m[3].replace(/[.-]+$/, '')
      if (!conocidos.has(nombre.toLowerCase())) continue
      const ini = m.index + m[2].length
      push(texto.slice(ultimo, ini))
      out.push({ t: 'mencion', v: nombre, yo: nombre.toLowerCase() === yo.toLowerCase() })
      ultimo = ini + 1 + nombre.length
      re.lastIndex = ultimo
    }
  }
  push(texto.slice(ultimo))
  return out
}

/* «X está escribiendo…», «X y Y están escribiendo…», «3 escribiendo…». */
export function textoEscribiendo(nombres: string[]) {
  if (!nombres.length) return ''
  if (nombres.length === 1) return `${nombres[0]} está escribiendo…`
  if (nombres.length === 2) return `${nombres[0]} y ${nombres[1]} están escribiendo…`
  return `${nombres.length} escribiendo…`
}

/* ---------- Invitados ---------- */

export function ultimoToken(v: string) {
  const partes = v.split(/[,;]/)
  return (partes[partes.length - 1] ?? '').trim().toLowerCase()
}

export function reemplazarUltimoToken(v: string, email: string) {
  const i = Math.max(v.lastIndexOf(','), v.lastIndexOf(';'))
  const antes = i >= 0 ? v.slice(0, i + 1).trimEnd() + ' ' : ''
  return `${antes}${email}, `
}

export function listaCorreos(v: string) {
  return [...new Set(v.split(/[,;\s]+/).map((e) => e.trim().toLowerCase()).filter((e) => /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(e)))]
}

export function sugerirCorreos(todos: CorreoSugerido[], token: string, valor: string, max = 6) {
  if (!token) return []
  const ya = new Set(listaCorreos(valor))
  return todos.filter((c) => !ya.has(c.email) && (c.email.includes(token) || c.nombre.toLowerCase().includes(token))).slice(0, max)
}

/* ---------- WhatsApp y enlaces de reserva ---------- */

/* wa.me con el prefijo 34 si el número no lo lleva (menos de 11 cifras), como el antiguo. */
export function enlaceWhatsapp(tel: string, texto: string) {
  let n = tel.replace(/\D/g, '')
  if (!n) return ''
  if (n.length < 11) n = '34' + n
  return `https://wa.me/${n}?text=${encodeURIComponent(texto)}`
}

export function textoReserva(nombre: string, enlace: string) {
  return `Hola ${nombre || ''}, te paso mi enlace para agendar nuestra reunión cuando mejor te venga: ${enlace}`.replace('Hola ,', 'Hola,')
}

/* ---------- Soporte ---------- */

/* «ahora», «N min», «N h» o «dd/mm/aaaa» (columna Actualizado del antiguo). */
export function actualizadoRelativo(s: string, ahora: Date = new Date()) {
  const d = aFecha(s)
  const seg = Math.floor((ahora.getTime() - d.getTime()) / 1000)
  if (seg < 60) return 'ahora'
  if (seg < 3600) return `${Math.floor(seg / 60)} min`
  if (seg < 86400) return `${Math.floor(seg / 3600)} h`
  return `${dos(d.getDate())}/${dos(d.getMonth() + 1)}/${d.getFullYear()}`
}

/* Suma minutos a una hora «HH:MM» (sin pasar de 23:59). */
export function sumarMinutos(hora: string, min: number) {
  const [h, m] = hora.split(':').map(Number)
  const t = Math.min(23 * 60 + 59, h * 60 + m + min)
  return `${dos(Math.floor(t / 60))}:${dos(t % 60)}`
}

export const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']
export const MESES_CORTOS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']

/* «8 oct 2026 · 10:00» */
export function fechaReunion(dia: string, hora: string) {
  const d = aFecha(dia)
  return `${d.getDate()} ${MESES_CORTOS[d.getMonth()]} ${d.getFullYear()}${hora ? ` · ${hora}` : ''}`
}

/* Tipos que admite el servidor para el chat (Adjuntos.php): el navegador filtra lo mismo antes de subir. */
export const ACEPTA_CHAT = 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.mp4,.mov,.webm,.mp3,.ogg,.wav,.m4a'

/* Pop-ups del avisador: uno por sala con el último mensaje y «· +N más».
   Título «Grupo · Autor» en los grupos y «Autor» en los directos. */
export function agruparAvisos(ms: AvisoChatT[]) {
  const porSala = new Map<number, AvisoChatT[]>()
  for (const m of ms) porSala.set(m.sala_id, [...(porSala.get(m.sala_id) ?? []), m])
  return [...porSala.entries()].map(([sala_id, lista]) => {
    const u = lista[lista.length - 1]
    const mas = lista.length - 1
    return {
      sala_id,
      autor: u.autor,
      foto: u.foto,
      titulo: u.grupo ? `${u.sala} · ${u.autor}` : u.autor,
      texto: (u.texto || '📎 Adjunto') + (mas > 0 ? ` · +${mas} más` : ''),
    }
  })
}
