/* Formato de texto enriquecido del ERP antiguo (lib/rt_editor.php y task.php):
   texto con marcadores tipo markdown, NUNCA HTML. Es lo que hay guardado en
   descripciones de tareas (`descripcion_rich`), comentarios (`cuerpo`) y actas
   (`contenido`), así que el front lo lee y lo escribe tal cual.

   Bloques (una línea = un bloque, salvo listas, citas, tablas y código):
     # / ## / ###          títulos             - texto · • texto   viñetas
     1. texto · 1) texto   numerada            > texto              cita
     ```                   código en bloque    ---                  divisor
     | a | b |  + | --- |  tabla               [[chk:0]] texto      lista de control
     (línea vacía)         línea en blanco      cualquier otra       párrafo
   En línea:
     **negrita** *cursiva* __subrayado__ ~~tachado~~ `código` [texto](https://…)
     https://… suelto · @usuario · [[img:FICHERO]] · [[file:FICHERO|nombre]]
     [[img]] (hueco de imagen de un comentario: las imágenes adjuntas, en orden)

   `parse` → árbol y `serializar` → texto. La ida y vuelta es EXACTA para
   cualquier texto: el texto en línea se guarda carácter a carácter y cada
   bloque recuerda sus líneas de origen (`fuente`), que se reutilizan mientras
   su contenido no cambie. Lo que se edita sale en la forma canónica que
   escribía el serializador antiguo (`- `, `1. `, `> `, `| a | b |`…).
   Solo los saltos de línea \r\n se normalizan a \n. */

export type Inline =
  | { t: 'text'; v: string }
  | { t: 'b' | 'i' | 'u' | 's'; c: Inline[] }
  | { t: 'code'; v: string }
  | { t: 'link'; href: string; label: string }
  | { t: 'url'; href: string }
  | { t: 'mention'; name: string }
  | { t: 'img'; fn: string }
  | { t: 'imgSlot' }
  | { t: 'file'; fn: string; orig: string | null }

export type MarcaInline = 'b' | 'i' | 'u' | 's'

/* Líneas originales del bloque y su forma canónica al leerlo. */
export type Fuente = { lineas: string[]; canon: string }

export type ItemChk = { hecho: boolean; c: Inline[] }

export type Bloque = (
  | { t: 'p'; c: Inline[] }
  | { t: 'h'; nivel: 1 | 2 | 3; c: Inline[] }
  | { t: 'ul'; items: Inline[][] }
  | { t: 'ol'; inicio: number; items: Inline[][] }
  | { t: 'quote'; lineas: Inline[][] }
  | { t: 'tareas'; items: ItemChk[] }
  | { t: 'code'; lang: string; lineas: string[] }
  | { t: 'hr' }
  | { t: 'table'; filas: Inline[][][] }
) & { fuente?: Fuente }

export type DocRT = Bloque[]

/* ------------------------------------------------------------------ En línea */

type Res = { nodos: Inline[]; fin: number }
type Delim = '**' | '__' | '~~' | '*'

const TIPO: Record<Delim, MarcaInline> = { '**': 'b', __: 'u', '~~': 's', '*': 'i' }
const LETRA_MENCION = /[\p{L}0-9_.-]/u

// Expresiones «pegajosas» (y): se prueban en la posición exacta sin recortar el texto.
const RE_CODE = /`([^`\n]+)`/y
const RE_LINK = /\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/y
const RE_URL = /https?:\/\/[^\s<]+/y
const RE_IMG = /\[\[img:([^\]]+)\]\]/y
const RE_FILE = /\[\[file:([^|\]]+)(\|([^\]]*))?\]\]/y
const RE_MENCION = /@([\p{L}0-9_.-]+)/uy

function pegajosa(re: RegExp, s: string, i: number) {
  re.lastIndex = i
  return re.exec(s)
}

/* Piezas que no admiten formato dentro: código, enlaces, URLs, adjuntos, menciones. */
function leerAtomo(s: string, i: number): { nodo: Inline; fin: number } | null {
  const ch = s[i]
  if (ch === '`') {
    const m = pegajosa(RE_CODE, s, i)
    if (m) return { nodo: { t: 'code', v: m[1] }, fin: i + m[0].length }
  } else if (ch === '[') {
    if (s.startsWith('[[img]]', i)) return { nodo: { t: 'imgSlot' }, fin: i + 7 }
    if (s.startsWith('[[img:', i)) {
      const m = pegajosa(RE_IMG, s, i)
      if (m) return { nodo: { t: 'img', fn: m[1] }, fin: i + m[0].length }
    }
    if (s.startsWith('[[file:', i)) {
      const m = pegajosa(RE_FILE, s, i)
      if (m) return { nodo: { t: 'file', fn: m[1], orig: m[2] === undefined ? null : m[3] }, fin: i + m[0].length }
    }
    const m = pegajosa(RE_LINK, s, i)
    if (m) return { nodo: { t: 'link', label: m[1], href: m[2] }, fin: i + m[0].length }
  } else if (ch === 'h') {
    const m = pegajosa(RE_URL, s, i)
    if (m) {
      // La puntuación final («mira https://x.com.») no es parte del enlace.
      let href = m[0]
      while (/[.,;:!?'"]$/.test(href) || (href.endsWith(')') && contar(href, '(') < contar(href, ')'))) href = href.slice(0, -1)
      if (/^https?:\/\/[^/]/.test(href)) return { nodo: { t: 'url', href }, fin: i + href.length }
    }
  } else if (ch === '@') {
    // Solo a principio de palabra: «pepe@empresa.com» no es una mención.
    if (i > 0 && LETRA_MENCION.test(s[i - 1])) return null
    const m = pegajosa(RE_MENCION, s, i)
    if (m) {
      const name = m[1].replace(/[.-]+$/, '')
      if (name) return { nodo: { t: 'mention', name }, fin: i + 1 + name.length }
    }
  }
  return null
}

function contar(s: string, c: string) {
  let n = 0
  for (const x of s) if (x === c) n++
  return n
}

class LectorInline {
  private memo = new Map<string, Res | null>()
  constructor(private s: string) {}

  leer(): Inline[] {
    return (this.span(0, null, 0) as Res).nodos
  }

  /* Intenta abrir `delim` en `i`: devuelve la marca si encuentra su cierre. */
  private marca(i: number, delim: Delim, prof: number): { nodo: Inline; fin: number } | null {
    const r = this.span(i + delim.length, delim, prof + 1)
    if (!r || r.nodos.length === 0) return null
    return { nodo: { t: TIPO[delim], c: r.nodos }, fin: r.fin }
  }

  private aperturas(i: number, cierre: Delim | null, prof: number) {
    const s = this.s
    if (s.startsWith('**', i)) {
      if (cierre !== '**') {
        const r = this.marca(i, '**', prof)
        if (r) return r
      }
      // «***x** y*»: la primera estrella abre una cursiva que contiene la negrita.
      if (cierre !== '*') return this.marca(i, '*', prof)
      return null
    }
    if (s.startsWith('__', i)) return cierre !== '__' ? this.marca(i, '__', prof) : null
    if (s.startsWith('~~', i)) return cierre !== '~~' ? this.marca(i, '~~', prof) : null
    if (s[i] === '*' && cierre !== '*') return this.marca(i, '*', prof)
    return null
  }

  /* Lee desde `inicio` hasta `cierre` (o hasta el final si no hay cierre). */
  private span(inicio: number, cierre: Delim | null, prof: number): Res | null {
    if (prof > 16) return null
    const clave = `${inicio}|${cierre ?? ''}`
    if (this.memo.has(clave)) return this.memo.get(clave) ?? null
    const s = this.s
    const nodos: Inline[] = []
    let texto = ''
    const volcar = () => {
      if (texto) nodos.push({ t: 'text', v: texto })
      texto = ''
    }
    let i = inicio
    let res: Res | null = null
    while (i < s.length) {
      const atomo = leerAtomo(s, i)
      if (atomo) {
        volcar()
        nodos.push(atomo.nodo)
        i = atomo.fin
        continue
      }
      if (cierre) {
        // Dentro de una cursiva, «**» abre antes una negrita anidada si se cierra.
        if (cierre !== '**' && s.startsWith('**', i)) {
          const r = this.marca(i, '**', prof)
          if (r) {
            volcar()
            nodos.push(r.nodo)
            i = r.fin
            continue
          }
        }
        if (i > inicio && s.startsWith(cierre, i)) {
          volcar()
          res = { nodos, fin: i + cierre.length }
          break
        }
      }
      const m = this.aperturas(i, cierre, prof)
      if (m) {
        volcar()
        nodos.push(m.nodo)
        i = m.fin
        continue
      }
      texto += s[i]
      i++
    }
    if (!res && !cierre) {
      volcar()
      res = { nodos, fin: i }
    }
    this.memo.set(clave, res)
    return res
  }
}

export function parseInline(s: string): Inline[] {
  if (!s) return []
  return new LectorInline(s).leer()
}

const DELIM: Record<MarcaInline, string> = { b: '**', i: '*', u: '__', s: '~~' }

export function serializarInline(nodos: Inline[]): string {
  let out = ''
  for (const n of nodos) {
    switch (n.t) {
      case 'text':
        out += n.v
        break
      case 'b':
      case 'i':
      case 'u':
      case 's':
        out += DELIM[n.t] + serializarInline(n.c) + DELIM[n.t]
        break
      case 'code':
        out += '`' + n.v + '`'
        break
      case 'link':
        out += `[${n.label}](${n.href})`
        break
      case 'url':
        out += n.href
        break
      case 'mention':
        out += '@' + n.name
        break
      case 'img':
        out += `[[img:${n.fn}]]`
        break
      case 'imgSlot':
        out += '[[img]]'
        break
      case 'file':
        out += n.orig === null ? `[[file:${n.fn}]]` : `[[file:${n.fn}|${n.orig}]]`
        break
    }
  }
  return out
}

/* --------------------------------------------------------------- Bloques */

const rtrim = (s: string) => s.replace(/\s+$/, '')
const RE_FILA = /^\s*\|.*\|\s*$/
const RE_SEP = /^\s*\|[\s:|-]+\|\s*$/
const RE_CHK = /^\s*\[\[chk:([01])\]\]\s?/
const RE_HR = /^\s*---+\s*$/
const RE_H = /^(#{1,3})\s+/
const RE_QUOTE = /^>\s?/
const RE_UL = /^[-•]\s+/
const RE_OL = /^(\d+)[.)]\s+/

/* Celdas de una fila «| a | b |» (como rt_table_html: sin las barras de los extremos). */
export function celdasDeFila(fila: string): string[] {
  return fila
    .trim()
    .replace(/^\||\|$/g, '')
    .split('|')
    .map((c) => c.trim())
}

/* Detecta con la línea sin espacios finales (como rt_blocks) y toma el
   contenido de la línea original: así no se pierden espacios. */
function resto(raw: string, re: RegExp) {
  const m = re.exec(raw)
  return m ? raw.slice(m[0].length) : raw
}

export function parse(texto: string): DocRT {
  if (!texto) return []
  const L = texto.replace(/\r\n?/g, '\n').split('\n')
  const n = L.length
  const doc: DocRT = []
  let i = 0
  const con = (b: Bloque, desde: number) => {
    b.fuente = { lineas: L.slice(desde, i), canon: '' }
    b.fuente.canon = serializarBloque({ ...b, fuente: undefined })
    doc.push(b)
  }
  while (i < n) {
    const raw = L[i]
    const t = rtrim(raw)
    const desde = i
    if (/^```/.test(raw)) {
      i++
      const lineas: string[] = []
      while (i < n && !/^```/.test(L[i])) lineas.push(L[i++])
      if (i < n) i++
      con({ t: 'code', lang: raw.slice(3).trim(), lineas }, desde)
      continue
    }
    if (RE_FILA.test(t) && i + 1 < n && RE_SEP.test(L[i + 1])) {
      const filas = [raw]
      i += 2
      while (i < n && RE_FILA.test(rtrim(L[i]))) filas.push(L[i++])
      con({ t: 'table', filas: filas.map((f) => celdasDeFila(f).map(parseInline)) }, desde)
      continue
    }
    if (RE_CHK.test(t)) {
      const items: ItemChk[] = []
      while (i < n && RE_CHK.test(rtrim(L[i]))) {
        const m = RE_CHK.exec(L[i]) as RegExpExecArray
        items.push({ hecho: m[1] === '1', c: parseInline(L[i].slice(m[0].length)) })
        i++
      }
      con({ t: 'tareas', items }, desde)
      continue
    }
    if (RE_HR.test(t)) {
      i++
      con({ t: 'hr' }, desde)
      continue
    }
    const h = RE_H.exec(t)
    if (h) {
      i++
      con({ t: 'h', nivel: h[1].length as 1 | 2 | 3, c: parseInline(resto(raw, RE_H)) }, desde)
      continue
    }
    if (RE_QUOTE.test(t)) {
      const lineas: Inline[][] = []
      while (i < n && RE_QUOTE.test(rtrim(L[i]))) lineas.push(parseInline(resto(L[i++], RE_QUOTE)))
      con({ t: 'quote', lineas }, desde)
      continue
    }
    if (RE_UL.test(t)) {
      const items: Inline[][] = []
      while (i < n && RE_UL.test(rtrim(L[i]))) items.push(parseInline(resto(L[i++], RE_UL)))
      con({ t: 'ul', items }, desde)
      continue
    }
    const ol = RE_OL.exec(t)
    if (ol) {
      const items: Inline[][] = []
      while (i < n && RE_OL.test(rtrim(L[i]))) items.push(parseInline(resto(L[i++], RE_OL)))
      con({ t: 'ol', inicio: Number(ol[1]), items }, desde)
      continue
    }
    i++
    con({ t: 'p', c: t === '' ? (raw ? [{ t: 'text', v: raw }] : []) : parseInline(raw) }, desde)
  }
  return doc
}

/* Forma canónica de un bloque (la del serializador del ERP antiguo). */
function canon(b: Bloque): string {
  const linea = (prefijo: string, c: Inline[]) => {
    const s = serializarInline(c)
    return s ? prefijo + s : prefijo.trimEnd()
  }
  switch (b.t) {
    case 'p':
      return serializarInline(b.c)
    case 'h': {
      const s = serializarInline(b.c)
      // Un título vacío no existe en el formato («#» solo sería un párrafo).
      return s.trim() ? '#'.repeat(b.nivel) + ' ' + s : ''
    }
    case 'ul':
      return b.items.map((c) => '- ' + serializarInline(c)).join('\n')
    case 'ol':
      return b.items.map((c, k) => `${b.inicio + k}. ` + serializarInline(c)).join('\n')
    case 'quote':
      return b.lineas.map((c) => linea('> ', c)).join('\n')
    case 'tareas':
      return b.items.map((it) => linea(`[[chk:${it.hecho ? 1 : 0}]] `, it.c)).join('\n')
    case 'code':
      return ['```' + b.lang, ...b.lineas, '```'].join('\n')
    case 'hr':
      return '---'
    case 'table': {
      const filas = b.filas.map((f) => '| ' + f.map((c) => serializarInline(c).replace(/\|/g, '/').replace(/\n/g, ' ')).join(' | ') + ' |')
      if (filas.length === 0) return ''
      const sep = '|' + b.filas[0].map(() => ' --- ').join('|') + '|'
      return [filas[0], sep, ...filas.slice(1)].join('\n')
    }
  }
}

export function serializarBloque(b: Bloque): string {
  const c = canon(b)
  return b.fuente && b.fuente.canon === c ? b.fuente.lineas.join('\n') : c
}

export function serializar(doc: DocRT): string {
  return doc.map(serializarBloque).join('\n')
}

/* --------------------------------------------------------------- Utilidades */

/* Texto plano de unos nodos en línea (para extractos y lectores de pantalla). */
export function textoInline(nodos: Inline[], opciones: { adjuntos?: boolean } = {}): string {
  let out = ''
  for (const n of nodos) {
    if (n.t === 'text' || n.t === 'code') out += n.v
    else if (n.t === 'link') out += n.label
    else if (n.t === 'url') out += n.href
    else if (n.t === 'mention') out += '@' + n.name
    else if (n.t === 'img' || n.t === 'imgSlot') out += opciones.adjuntos ? '📷 ' : ''
    else if (n.t === 'file') out += opciones.adjuntos ? `📎 ${n.orig || n.fn} ` : ''
    else out += textoInline(n.c, opciones)
  }
  return out
}

/* Extracto en una línea sin marcadores (rt_excerpt / cm_excerpt): para
   listados, citas de respuesta y avisos. Con `adjuntos`, 📷/📎 en su sitio. */
export function extracto(texto: string, n = 160, opciones: { adjuntos?: boolean } = {}): string {
  const partes: string[] = []
  for (const b of parse(texto)) {
    if (b.t === 'p' || b.t === 'h') partes.push(textoInline(b.c, opciones))
    else if (b.t === 'ul' || b.t === 'ol') b.items.forEach((c) => partes.push(textoInline(c, opciones)))
    else if (b.t === 'quote') b.lineas.forEach((c) => partes.push(textoInline(c, opciones)))
    else if (b.t === 'tareas') b.items.forEach((it) => partes.push(textoInline(it.c, opciones)))
    else if (b.t === 'code') partes.push(b.lineas.join(' '))
    else if (b.t === 'table') b.filas.forEach((f) => partes.push(f.map((c) => textoInline(c, opciones)).join(' ')))
  }
  const plano = partes.join(' ').replace(/\s+/g, ' ').trim()
  return plano.length > n ? plano.slice(0, n).trimEnd() + '…' : plano
}

/* Nombres mencionados (@usuario), sin repetir, en orden de aparición. */
export function menciones(texto: string): string[] {
  const vistos = new Set<string>()
  const visitar = (nodos: Inline[]) => {
    for (const n of nodos) {
      if (n.t === 'mention') vistos.add(n.name)
      else if (n.t === 'b' || n.t === 'i' || n.t === 'u' || n.t === 's') visitar(n.c)
    }
  }
  for (const b of parse(texto)) {
    if (b.t === 'p' || b.t === 'h') visitar(b.c)
    else if (b.t === 'ul' || b.t === 'ol') b.items.forEach(visitar)
    else if (b.t === 'quote') b.lineas.forEach(visitar)
    else if (b.t === 'tareas') b.items.forEach((it) => visitar(it.c))
    else if (b.t === 'table') b.filas.forEach((f) => f.forEach(visitar))
  }
  return [...vistos]
}

/* ¿Está vacío (solo espacios y líneas en blanco)? */
export function estaVacio(texto: string | null | undefined) {
  return !texto || texto.trim() === ''
}

/* Marca o desmarca el elemento `indice` (contando todos los [[chk]] del
   texto, en orden) y devuelve el texto nuevo; el resto queda intacto. */
export function alternarCheck(texto: string, indice: number, hecho: boolean): string {
  const doc = parse(texto)
  let k = 0
  for (const b of doc) {
    if (b.t !== 'tareas') continue
    for (const it of b.items) {
      if (k++ === indice) {
        it.hecho = hecho
        return serializar(doc)
      }
    }
  }
  return texto
}

/* Solo http(s) y mailto: ni javascript:, ni data:, ni rutas relativas. */
export function esUrlSegura(href: string) {
  return /^(https?:\/\/|mailto:)/i.test(href.trim())
}

/* Nombre de fichero de [[img:…]] / [[file:…]] válido (desc_fn_ok del antiguo). */
export function esNombreFicheroValido(fn: string) {
  return /^[A-Za-z0-9_.-]+$/.test(fn)
}
