import { useMemo, type ReactNode } from 'react'
import { Image as ImageIcon, Paperclip } from 'lucide-react'
import Avatar from '../Avatar'
import { alternarCheck, esNombreFicheroValido, esUrlSegura, parse, type Bloque, type Inline } from '../../lib/richtext'
import type { PersonaMencion } from '../../lib/menciones'
import './rich.css'

export type RichTextViewProps = {
  /* Texto en el formato del ERP (descripción, comentario, acta). */
  value: string | null | undefined
  /* Personas conocidas: sus menciones salen con avatar (las demás, en plano). */
  people?: PersonaMencion[]
  /* URL de un fichero de [[img:FN]] / [[file:FN|nombre]]; sin ella salen como chip sin enlace. */
  resolveFileUrl?: (fn: string) => string | null | undefined
  /* URLs para los huecos [[img]] de un comentario, en orden. */
  imageSlots?: string[]
  /* Clic en una imagen (abrir el Lightbox). */
  onImageClick?: (url: string) => void
  /* Si se pasa, las casillas [[chk]] se pueden marcar y devuelve el texto nuevo. */
  onChange?: (valor: string) => void
  /* Qué enseñar si está vacío (por defecto nada). */
  empty?: ReactNode
  className?: string
}

type Ctx = {
  people: Map<string, PersonaMencion>
  resolveFileUrl?: RichTextViewProps['resolveFileUrl']
  imageSlots: string[]
  onImageClick?: (url: string) => void
  // Contadores del recorrido: huecos de imagen y casillas, en orden.
  hueco: number
  chk: number
  alternar?: (indice: number, hecho: boolean) => void
}

function inline(nodos: Inline[], ctx: Ctx, clave = ''): ReactNode[] {
  return nodos.map((n, k) => {
    const key = `${clave}${k}`
    switch (n.t) {
      case 'text':
        return n.v
      case 'b':
        return <b key={key}>{inline(n.c, ctx, key + '.')}</b>
      case 'i':
        return <i key={key}>{inline(n.c, ctx, key + '.')}</i>
      case 'u':
        return <u key={key}>{inline(n.c, ctx, key + '.')}</u>
      case 's':
        return <s key={key}>{inline(n.c, ctx, key + '.')}</s>
      case 'code':
        return <code key={key}>{n.v}</code>
      case 'link':
      case 'url': {
        const href = n.href
        const texto = n.t === 'link' ? n.label : n.href
        if (!esUrlSegura(href)) return texto
        return (
          <a key={key} href={href} target="_blank" rel="noopener noreferrer">
            {texto}
          </a>
        )
      }
      case 'mention': {
        const p = ctx.people.get(n.name.toLowerCase())
        return (
          <span key={key} className="rt-mention" data-mention={n.name}>
            {p && <Avatar nombre={p.username} foto={p.foto} size={17} className="rt-mention-av" />}
            {p ? p.username : '@' + n.name}
          </span>
        )
      }
      case 'img': {
        if (!esNombreFicheroValido(n.fn)) return null
        const url = ctx.resolveFileUrl?.(n.fn)
        if (!url) return <Fichero key={key} nombre={n.fn} />
        return <Imagen key={key} url={url} onClick={ctx.onImageClick} />
      }
      case 'imgSlot': {
        const url = ctx.imageSlots[ctx.hueco++]
        return url ? <Imagen key={key} url={url} onClick={ctx.onImageClick} /> : null
      }
      case 'file': {
        if (!esNombreFicheroValido(n.fn)) return null
        return <Fichero key={key} nombre={n.orig || n.fn} url={ctx.resolveFileUrl?.(n.fn) ?? undefined} />
      }
    }
    return null
  })
}

function Imagen({ url, onClick }: { url: string; onClick?: (url: string) => void }) {
  return (
    <img
      className="rt-img"
      src={url}
      alt=""
      loading="lazy"
      onClick={onClick ? () => onClick(url) : undefined}
      onKeyDown={onClick ? (e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), onClick(url)) : undefined}
      tabIndex={onClick ? 0 : undefined}
      role={onClick ? 'button' : undefined}
      aria-label={onClick ? 'Ver imagen' : undefined}
    />
  )
}

function Fichero({ nombre, url }: { nombre: string; url?: string }) {
  const contenido = (
    <>
      {/\.(png|jpe?g|gif|webp)$/i.test(nombre) ? <ImageIcon size={13} className="text-label" aria-hidden="true" /> : <Paperclip size={13} className="text-label" aria-hidden="true" />}
      {nombre}
    </>
  )
  if (url && (esUrlSegura(url) || url.startsWith('/')))
    return (
      <a className="rt-file" href={url} target="_blank" rel="noopener noreferrer">
        {contenido}
      </a>
    )
  return <span className="rt-file">{contenido}</span>
}

function bloque(b: Bloque, k: number, ctx: Ctx): ReactNode {
  const key = `b${k}`
  switch (b.t) {
    case 'p':
      return (
        <div key={key} className="rt-p">
          {b.c.length && b.c.some((n) => n.t !== 'text' || n.v.trim()) ? inline(b.c, ctx, key) : <br />}
        </div>
      )
    case 'h': {
      const H = `h${b.nivel}` as const
      return <H key={key}>{inline(b.c, ctx, key)}</H>
    }
    case 'ul':
    case 'ol': {
      const L = b.t
      return (
        <L key={key} start={b.t === 'ol' && b.inicio !== 1 ? b.inicio : undefined}>
          {b.items.map((c, j) => (
            <li key={j}>{inline(c, ctx, `${key}.${j}.`)}</li>
          ))}
        </L>
      )
    }
    case 'quote':
      return (
        <blockquote key={key}>
          {b.lineas.map((c, j) => (
            <div key={j}>{c.length ? inline(c, ctx, `${key}.${j}.`) : <br />}</div>
          ))}
        </blockquote>
      )
    case 'tareas':
      return (
        <div key={key}>
          {b.items.map((it, j) => {
            const indice = ctx.chk++
            const alternar = ctx.alternar
            return (
              <div key={j} className={`rt-chk ${it.hecho ? 'done' : ''}`}>
                {alternar ? (
                  <button type="button" role="checkbox" aria-checked={it.hecho} aria-label={it.hecho ? 'Desmarcar' : 'Marcar como hecho'} className="rt-cbox" onClick={() => alternar(indice, !it.hecho)}>
                    {it.hecho ? '✓' : ''}
                  </button>
                ) : (
                  <span className="rt-cbox" aria-hidden="true">
                    {it.hecho ? '✓' : ''}
                  </span>
                )}
                <span className="min-w-0 flex-1">
                  {!alternar && <span className="sr-only">{it.hecho ? 'Hecho: ' : 'Pendiente: '}</span>}
                  {inline(it.c, ctx, `${key}.${j}.`)}
                </span>
              </div>
            )
          })}
        </div>
      )
    case 'code':
      return (
        <pre key={key}>
          <code>{b.lineas.join('\n')}</code>
        </pre>
      )
    case 'hr':
      return <hr key={key} />
    case 'table': {
      const [cab, ...filas] = b.filas
      return (
        <div key={key} className="rt-tablewrap">
          <table>
            {cab && (
              <thead>
                <tr>
                  {cab.map((c, j) => (
                    <th key={j}>{inline(c, ctx, `${key}.h${j}.`)}</th>
                  ))}
                </tr>
              </thead>
            )}
            <tbody>
              {filas.map((f, r) => (
                <tr key={r}>
                  {f.map((c, j) => (
                    <td key={j}>{inline(c, ctx, `${key}.${r}.${j}.`)}</td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )
    }
  }
}

/* Pinta el formato del ERP con elementos de React: nunca HTML del usuario. */
export default function RichTextView({ value, people, resolveFileUrl, imageSlots, onImageClick, onChange, empty = null, className = '' }: RichTextViewProps) {
  const texto = value ?? ''
  const doc = useMemo(() => parse(texto), [texto])
  const mapa = useMemo(() => new Map((people ?? []).map((p) => [p.username.toLowerCase(), p])), [people])
  if (!texto.trim()) return <>{empty}</>
  const ctx: Ctx = {
    people: mapa,
    resolveFileUrl,
    imageSlots: imageSlots ?? [],
    onImageClick,
    hueco: 0,
    chk: 0,
    alternar: onChange ? (i, hecho) => onChange(alternarCheck(texto, i, hecho)) : undefined,
  }
  return <div className={`rt-content ${className}`}>{doc.map((b, k) => bloque(b, k, ctx))}</div>
}
