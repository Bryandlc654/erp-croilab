import { Extension, Node, mergeAttributes } from '@tiptap/core'
import type { DOMOutputSpec } from '@tiptap/pm/model'
import { colorDe, iniciales } from '../../lib/avatar'
import { url } from '../../api/client'

/* Piezas de TipTap propias del formato del ERP. Solo las carga el editor
   (trozo aparte): nada de esto entra en el bundle principal. */

type Resolver = (fn: string) => string | null | undefined

const SVG = 'http://www.w3.org/2000/svg'
const CLIP: DOMOutputSpec = [
  `${SVG} svg`,
  { width: '13', height: '13', viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true' },
  [`${SVG} path`, { d: 'm21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48' }],
]

/* [[img:FICHERO]]: imagen subida dentro del texto. */
export const ErpImagen = Node.create<{ resolver: Resolver }>({
  name: 'erpImagen',
  group: 'inline',
  inline: true,
  atom: true,
  draggable: true,
  selectable: true,
  addOptions() {
    return { resolver: () => null }
  },
  addAttributes() {
    return { fn: { default: '' } }
  },
  parseHTML() {
    return [{ tag: 'img[data-fn]', getAttrs: (el) => ({ fn: (el as HTMLElement).getAttribute('data-fn') ?? '' }) }]
  },
  renderHTML({ node }) {
    const fn = String(node.attrs.fn)
    const url = this.options.resolver(fn)
    if (!url) return ['span', { class: 'rt-file', 'data-fn': fn }, CLIP, ' ' + fn]
    return ['img', { class: 'rt-img', src: url, alt: '', 'data-fn': fn }]
  },
  renderText({ node }) {
    return `[[img:${node.attrs.fn}]]`
  },
})

/* [[file:FICHERO|nombre]]: fichero subido, como chip con enlace. */
export const ErpArchivo = Node.create<{ resolver: Resolver }>({
  name: 'erpArchivo',
  group: 'inline',
  inline: true,
  atom: true,
  selectable: true,
  addOptions() {
    return { resolver: () => null }
  },
  addAttributes() {
    return { fn: { default: '' }, orig: { default: null } }
  },
  parseHTML() {
    return [{ tag: '[data-erp-file]', getAttrs: (el) => ({ fn: (el as HTMLElement).getAttribute('data-erp-file') ?? '', orig: (el as HTMLElement).getAttribute('data-orig') }) }]
  },
  renderHTML({ node }) {
    const fn = String(node.attrs.fn)
    const nombre = String(node.attrs.orig || fn)
    const url = this.options.resolver(fn)
    const attrs = { class: 'rt-file', 'data-erp-file': fn, 'data-orig': node.attrs.orig ?? '', contenteditable: 'false' }
    if (!url) return ['span', attrs, CLIP, ' ' + nombre]
    return ['a', mergeAttributes(attrs, { href: url, target: '_blank', rel: 'noopener noreferrer' }), CLIP, ' ' + nombre]
  },
  renderText({ node }) {
    return `[[file:${node.attrs.fn}|${node.attrs.orig ?? ''}]]`
  },
})

/* [[img]]: hueco de imagen de un comentario antiguo (las imágenes van aparte). */
export const ErpHuecoImagen = Node.create({
  name: 'erpHuecoImagen',
  group: 'inline',
  inline: true,
  atom: true,
  parseHTML() {
    return [{ tag: 'span[data-erp-hueco]' }]
  },
  renderHTML() {
    return ['span', { class: 'rt-img-slot', 'data-erp-hueco': '' }, '🖼 imagen']
  },
  renderText() {
    return '[[img]]'
  },
})

/* Recuerda las líneas de origen de cada bloque (ver `fuente` en richtext.ts):
   así lo que no se edita se guarda tal como estaba. No se copia al partir un
   bloque ni sale en el HTML. */
export const FuenteErp = Extension.create({
  name: 'fuenteErp',
  addGlobalAttributes() {
    return [
      {
        types: ['paragraph', 'heading', 'bulletList', 'orderedList', 'blockquote', 'codeBlock', 'horizontalRule', 'table', 'taskList'],
        attributes: { fuente: { default: null, rendered: false, keepOnSplit: false } },
      },
    ]
  },
})

/* Atajos propios: Intro envía (compositores), Ctrl/⌘+K enlace, Ctrl/⌘+. emojis. */
export const AtajosErp = Extension.create<{
  enviar: () => boolean
  enlace: () => boolean
  emoji: () => boolean
}>({
  name: 'atajosErp',
  // Por delante del Intro de StarterKit (si no, nunca llegaría aquí).
  priority: 1000,
  addOptions() {
    return { enviar: () => false, enlace: () => false, emoji: () => false }
  },
  addKeyboardShortcuts() {
    return {
      Enter: () => this.options.enviar(),
      'Mod-k': () => this.options.enlace(),
      'Mod-.': () => this.options.emoji(),
    }
  },
})

/* Mención: «@usuario» con avatar (como el chip .mention del antiguo). */
export function htmlMencion(nombre: string, foto?: string | null): DOMOutputSpec {
  const av: Record<string, string> = { class: 'rt-mention-av', style: `background-color:${colorDe(nombre)}` }
  // Misma URL que <Avatar>: la foto se sirve desde la API.
  if (foto) av.style += `;background-image:url("${encodeURI(url('/' + foto))}")`
  return ['span', { class: 'rt-mention', 'data-mention': nombre }, ['span', av, foto ? '' : iniciales(nombre)], nombre]
}
