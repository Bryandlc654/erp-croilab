import { esImagen, type Adjunto } from './adjuntos'
import { aFecha } from './formato'

/* Como render_comment_body del antiguo: las imágenes adjuntas rellenan, en
   orden, los huecos [[img]] del cuerpo; las que sobran y los demás ficheros
   van debajo del texto. */
export function repartirImagenes(cuerpo: string, adjuntos: readonly Adjunto[] = []): { huecos: string[]; debajo: Adjunto[] } {
  const n = (cuerpo.match(/\[\[img\]\]/g) ?? []).length
  const imagenes = adjuntos.filter(esImagen)
  const usadas = imagenes.slice(0, n)
  return { huecos: usadas.map((a) => a.url), debajo: adjuntos.filter((a) => !usadas.includes(a)) }
}

/* Comentarios y eventos de sistema intercalados por fecha (los de la misma
   fecha, en el orden en que llegan: primero los eventos). */
export function intercalarPorFecha<C extends { creado: string }, E extends { at: string }>(comentarios: readonly C[], eventos: readonly E[] = []): ({ tipo: 'comentario'; c: C } | { tipo: 'evento'; e: E })[] {
  const t = (s: string) => aFecha(s)?.getTime() ?? 0
  const todos = [...eventos.map((e, i) => ({ tipo: 'evento' as const, e, at: t(e.at), i })), ...comentarios.map((c, i) => ({ tipo: 'comentario' as const, c, at: t(c.creado), i: eventos.length + i }))]
  todos.sort((a, b) => a.at - b.at || a.i - b.i)
  return todos.map((x) => (x.tipo === 'evento' ? { tipo: 'evento', e: x.e } : { tipo: 'comentario', c: x.c }))
}
