import type { Bloque, DocRT, Fuente, Inline, MarcaInline } from '../../lib/richtext'

/* Conversión entre el árbol del formato del ERP (richtext.ts) y el documento
   JSON de TipTap. Es JSON puro (sin importar TipTap) para poder probarla y
   para que no arrastre el editor al bundle principal. */

export type MarcaTT = { type: string; attrs?: Record<string, unknown> }
export type NodoTT = { type: string; attrs?: Record<string, unknown>; content?: NodoTT[]; text?: string; marks?: MarcaTT[] }

const MARCA_TT: Record<MarcaInline, string> = { b: 'bold', i: 'italic', u: 'underline', s: 'strike' }
const TT_MARCA: Record<string, MarcaInline> = { bold: 'b', italic: 'i', underline: 'u', strike: 's' }
// Orden de anidado cuando dos marcas empiezan y acaban a la vez (de fuera a dentro).
const RANGO: MarcaInline[] = ['u', 's', 'b', 'i']

/* ------------------------------------------------------------ Árbol → TipTap */

function inlineATT(nodos: Inline[], marcas: MarcaTT[] = []): NodoTT[] {
  const out: NodoTT[] = []
  const texto = (text: string, extra: MarcaTT[] = []) => {
    if (!text) return
    const ms = [...marcas, ...extra]
    out.push(ms.length ? { type: 'text', text, marks: ms } : { type: 'text', text })
  }
  for (const n of nodos) {
    switch (n.t) {
      case 'text':
        texto(n.v)
        break
      case 'b':
      case 'i':
      case 'u':
      case 's':
        out.push(...inlineATT(n.c, [...marcas, { type: MARCA_TT[n.t] }]))
        break
      case 'code':
        texto(n.v, [{ type: 'code' }])
        break
      case 'link':
        texto(n.label, [{ type: 'link', attrs: { href: n.href } }])
        break
      case 'url':
        texto(n.href, [{ type: 'link', attrs: { href: n.href } }])
        break
      case 'mention':
        out.push({ type: 'mention', attrs: { id: n.name, label: n.name } })
        break
      case 'img':
        out.push({ type: 'erpImagen', attrs: { fn: n.fn } })
        break
      case 'imgSlot':
        out.push({ type: 'erpHuecoImagen' })
        break
      case 'file':
        out.push({ type: 'erpArchivo', attrs: { fn: n.fn, orig: n.orig } })
        break
    }
  }
  return out
}

const parrafo = (c: Inline[], fuente?: Fuente): NodoTT => {
  const content = inlineATT(c)
  const nodo: NodoTT = { type: 'paragraph' }
  if (fuente) nodo.attrs = { fuente }
  if (content.length) nodo.content = content
  return nodo
}

function bloqueATT(b: Bloque): NodoTT {
  const attrs = b.fuente ? { fuente: b.fuente } : {}
  switch (b.t) {
    case 'p':
      return parrafo(b.c, b.fuente)
    case 'h': {
      const content = inlineATT(b.c)
      return { type: 'heading', attrs: { ...attrs, level: b.nivel }, ...(content.length ? { content } : {}) }
    }
    case 'ul':
      return { type: 'bulletList', attrs, content: b.items.map((c) => ({ type: 'listItem', content: [parrafo(c)] })) }
    case 'ol':
      return { type: 'orderedList', attrs: { ...attrs, start: b.inicio }, content: b.items.map((c) => ({ type: 'listItem', content: [parrafo(c)] })) }
    case 'quote':
      return { type: 'blockquote', attrs, content: b.lineas.length ? b.lineas.map((c) => parrafo(c)) : [parrafo([])] }
    case 'tareas':
      return { type: 'taskList', attrs, content: b.items.map((it) => ({ type: 'taskItem', attrs: { checked: it.hecho }, content: [parrafo(it.c)] })) }
    case 'code': {
      const text = b.lineas.join('\n')
      return { type: 'codeBlock', attrs: { ...attrs, language: b.lang || null }, ...(text ? { content: [{ type: 'text', text }] } : {}) }
    }
    case 'hr':
      return { type: 'horizontalRule', attrs }
    case 'table': {
      // La tabla de ProseMirror tiene que ser rectangular: se rellenan las filas cortas.
      const cols = Math.max(1, ...b.filas.map((f) => f.length))
      return {
        type: 'table',
        attrs,
        content: b.filas.map((f, r) => ({
          type: 'tableRow',
          content: Array.from({ length: cols }, (_, k) => ({ type: r === 0 ? 'tableHeader' : 'tableCell', content: [parrafo(f[k] ?? [])] })),
        })),
      }
    }
  }
}

export function docATiptap(doc: DocRT): NodoTT {
  const content = doc.map(bloqueATT)
  return { type: 'doc', content: content.length ? content : [{ type: 'paragraph' }] }
}

/* ------------------------------------------------------------ TipTap → árbol */

type Segmento = { marcas: Set<MarcaInline>; nodo: Inline }

function limpiarHref(href: string) {
  return href.trim().replace(/\s/g, '%20').replace(/\)/g, '%29')
}

/* Un nodo en línea de TipTap → la pieza atómica del formato y sus marcas. */
function segmento(n: NodoTT): Segmento | null {
  const marcas = new Set<MarcaInline>()
  let enlace: string | null = null
  let codigo = false
  for (const m of n.marks ?? []) {
    if (TT_MARCA[m.type]) marcas.add(TT_MARCA[m.type])
    else if (m.type === 'code') codigo = true
    else if (m.type === 'link' && typeof m.attrs?.href === 'string') enlace = m.attrs.href
  }
  if (n.type === 'text') {
    const text = n.text ?? ''
    if (!text) return null
    if (enlace && /^https?:\/\//i.test(enlace)) {
      const href = limpiarHref(enlace)
      // Como el serializador antiguo: si el texto es la propia URL, va suelta.
      if (text === enlace || text === href) return { marcas, nodo: { t: 'url', href } }
      return { marcas, nodo: { t: 'link', href, label: text.replace(/\n/g, ' ').replace(/\]/g, ')') } }
    }
    if (codigo) {
      const v = text.replace(/`/g, '').replace(/\n/g, ' ')
      return v ? { marcas, nodo: { t: 'code', v } } : null
    }
    return { marcas, nodo: { t: 'text', v: text } }
  }
  const a = n.attrs ?? {}
  if (n.type === 'mention') {
    const name = String(a.id ?? a.label ?? '').replace(/\s+/g, '')
    return name ? { marcas: new Set(), nodo: { t: 'mention', name } } : null
  }
  if (n.type === 'erpImagen' && typeof a.fn === 'string') return { marcas: new Set(), nodo: { t: 'img', fn: a.fn } }
  if (n.type === 'erpHuecoImagen') return { marcas: new Set(), nodo: { t: 'imgSlot' } }
  if (n.type === 'erpArchivo' && typeof a.fn === 'string') return { marcas: new Set(), nodo: { t: 'file', fn: a.fn, orig: typeof a.orig === 'string' ? a.orig : null } }
  return null
}

function empujar(lista: Inline[], nodo: Inline) {
  const ult = lista[lista.length - 1]
  if (nodo.t === 'text' && ult?.t === 'text') ult.v += nodo.v
  else lista.push(nodo)
}

/* Marcas planas por tramo → marcas anidadas. Cuando se abren varias a la vez,
   va por fuera la que dura más (así «***x** y*» se relee igual). */
function anidar(segs: Segmento[]): Inline[] {
  const raiz: Inline[] = []
  const pila: { m: MarcaInline; c: Inline[] }[] = []
  const tramo = (desde: number, m: MarcaInline) => {
    let k = desde
    while (k < segs.length && segs[k].marcas.has(m)) k++
    return k - desde
  }
  segs.forEach((seg, k) => {
    const corte = pila.findIndex((e) => !seg.marcas.has(e.m))
    if (corte >= 0) pila.length = corte
    const abiertas = new Set(pila.map((e) => e.m))
    const nuevas = [...seg.marcas].filter((m) => !abiertas.has(m)).sort((a, b) => tramo(k, b) - tramo(k, a) || RANGO.indexOf(a) - RANGO.indexOf(b))
    for (const m of nuevas) {
      const nodo: Inline = { t: m, c: [] }
      ;(pila[pila.length - 1]?.c ?? raiz).push(nodo)
      pila.push({ m, c: nodo.c })
    }
    empujar(pila[pila.length - 1]?.c ?? raiz, seg.nodo)
  })
  return raiz
}

/* Contenido en línea de un bloque de texto, partido por los saltos (Mayús+Intro). */
function lineasDe(n: NodoTT | undefined): Inline[][] {
  const lineas: Segmento[][] = [[]]
  for (const h of n?.content ?? []) {
    if (h.type === 'hardBreak') lineas.push([])
    else {
      const s = segmento(h)
      if (s) lineas[lineas.length - 1].push(s)
    }
  }
  return lineas.map(anidar)
}

/* Todo en una línea (títulos, celdas, elementos de lista): los saltos pasan a espacio. */
function unaLinea(nodos: (NodoTT | undefined)[]): Inline[] {
  const out: Inline[] = []
  nodos.forEach((n) => {
    lineasDe(n).forEach((l) => {
      if (out.length && l.length) empujar(out, { t: 'text', v: ' ' })
      l.forEach((x) => empujar(out, x))
    })
  })
  return out
}

const fuenteDe = (n: NodoTT): Fuente | undefined => {
  const f = n.attrs?.fuente as Fuente | undefined | null
  return f && Array.isArray(f.lineas) && typeof f.canon === 'string' ? f : undefined
}

/* Bloques de texto dentro de un nodo (para aplanar lo que el formato no anida). */
function textblocks(n: NodoTT): NodoTT[] {
  if (n.type === 'paragraph' || n.type === 'heading' || n.type === 'codeBlock') return [n]
  return (n.content ?? []).flatMap(textblocks)
}

function itemsDeLista(n: NodoTT): Inline[][] {
  const items: Inline[][] = []
  for (const li of n.content ?? []) {
    const parrafos = (li.content ?? []).filter((c) => c.type === 'paragraph')
    items.push(unaLinea(parrafos))
    // Las sublistas no existen en el formato: sus elementos siguen en la misma lista.
    for (const sub of (li.content ?? []).filter((c) => c.type !== 'paragraph')) items.push(...textblocks(sub).map((t) => unaLinea([t])))
  }
  return items
}

function itemsDeTareas(n: NodoTT): { hecho: boolean; c: Inline[] }[] {
  const items: { hecho: boolean; c: Inline[] }[] = []
  for (const ti of n.content ?? []) {
    const parrafos = (ti.content ?? []).filter((c) => c.type === 'paragraph')
    items.push({ hecho: !!ti.attrs?.checked, c: unaLinea(parrafos) })
    for (const sub of (ti.content ?? []).filter((c) => c.type !== 'paragraph')) {
      if (sub.type === 'taskList') items.push(...itemsDeTareas(sub))
      else items.push(...textblocks(sub).map((t) => ({ hecho: false, c: unaLinea([t]) })))
    }
  }
  return items
}

function ttABloques(n: NodoTT): Bloque[] {
  const fuente = fuenteDe(n)
  const con = (b: Bloque): Bloque => (fuente ? { ...b, fuente } : b)
  switch (n.type) {
    case 'paragraph': {
      const lineas = lineasDe(n)
      return lineas.length === 1 ? [con({ t: 'p', c: lineas[0] })] : lineas.map((c) => ({ t: 'p', c }) as Bloque)
    }
    case 'heading': {
      const nivel = Math.min(3, Math.max(1, Number(n.attrs?.level) || 1)) as 1 | 2 | 3
      return [con({ t: 'h', nivel, c: unaLinea([n]) })]
    }
    case 'bulletList':
      return [con({ t: 'ul', items: itemsDeLista(n) })]
    case 'orderedList':
      return [con({ t: 'ol', inicio: Math.max(0, Number(n.attrs?.start) || 1), items: itemsDeLista(n) })]
    case 'taskList':
      return [con({ t: 'tareas', items: itemsDeTareas(n) })]
    case 'blockquote':
      return [con({ t: 'quote', lineas: textblocks(n).flatMap((t) => (t.type === 'codeBlock' ? [[{ t: 'text', v: t.content?.[0]?.text ?? '' } as Inline]] : lineasDe(t))) })]
    case 'codeBlock': {
      const text = (n.content ?? []).map((c) => c.text ?? '').join('')
      return [con({ t: 'code', lang: typeof n.attrs?.language === 'string' ? n.attrs.language : '', lineas: text ? text.replace(/```/g, '').split('\n') : [] })]
    }
    case 'horizontalRule':
      return [con({ t: 'hr' })]
    case 'table':
      return [con({ t: 'table', filas: (n.content ?? []).map((tr) => (tr.content ?? []).map((celda) => unaLinea(textblocks(celda)))) })]
    default:
      return textblocks(n).flatMap((t) => (t === n ? [] : ttABloques(t)))
  }
}

export function tiptapADoc(json: NodoTT): DocRT {
  return (json.content ?? []).flatMap(ttABloques)
}
