/* Adjuntos: tipo por extensión/MIME, tamaños legibles y validación de subidas. */

export type TipoAdjunto = 'imagen' | 'pdf' | 'video' | 'audio' | 'archivo'

export type Adjunto = {
  id: string | number
  nombre: string
  /* URL para abrir/descargar (ya con el prefijo de la API). */
  url: string
  /* MIME si se conoce; si no, se deduce de la extensión. */
  mime?: string | null
  /* Miniatura propia (si no, las imágenes usan `url`). */
  miniatura?: string | null
  tamano?: number | null
}

const IMG = /\.(png|jpe?g|gif|webp|avif|bmp|svg)$/i
const VIDEO = /\.(mp4|webm|mov|m4v|ogv)$/i
const AUDIO = /\.(mp3|wav|ogg|m4a|aac|webm)$/i

export function tipoAdjunto(nombre: string, mime?: string | null): TipoAdjunto {
  const m = (mime ?? '').toLowerCase()
  if (m.startsWith('image/') || (!m && IMG.test(nombre))) return 'imagen'
  if (m === 'application/pdf' || (!m && /\.pdf$/i.test(nombre))) return 'pdf'
  if (m.startsWith('video/') || (!m && VIDEO.test(nombre))) return 'video'
  if (m.startsWith('audio/') || (!m && AUDIO.test(nombre))) return 'audio'
  return 'archivo'
}

export const esImagen = (a: Pick<Adjunto, 'nombre' | 'mime'>) => tipoAdjunto(a.nombre, a.mime) === 'imagen'

/* 1536 → «1,5 KB», 2_500_000 → «2,4 MB». */
export function tamanoLegible(bytes: number | null | undefined) {
  if (bytes === null || bytes === undefined || !Number.isFinite(bytes)) return ''
  if (bytes < 1024) return `${bytes} B`
  const kb = bytes / 1024
  if (kb < 1024) return `${kb.toFixed(kb < 10 ? 1 : 0).replace('.', ',')} KB`
  const mb = kb / 1024
  return `${mb.toFixed(mb < 10 ? 1 : 0).replace('.', ',')} MB`
}

/* ¿Encaja el fichero en un `accept` de <input type=file> («image/*,.pdf»)? */
export function aceptaFichero(f: { name: string; type: string }, accept?: string) {
  if (!accept) return true
  const nombre = f.name.toLowerCase()
  const tipo = (f.type || '').toLowerCase()
  return accept
    .split(',')
    .map((s) => s.trim().toLowerCase())
    .filter(Boolean)
    .some((a) => (a.startsWith('.') ? nombre.endsWith(a) : a.endsWith('/*') ? tipo.startsWith(a.slice(0, -1)) : tipo === a))
}

export type Rechazo<F> = { file: F; motivo: string }

/* Separa los ficheros válidos de los que no (tipo o tamaño), con el motivo. */
export function validarFicheros<F extends { name: string; type: string; size: number }>(files: readonly F[], { accept, maxSizeMB = 25 }: { accept?: string; maxSizeMB?: number } = {}) {
  const validos: F[] = []
  const rechazados: Rechazo<F>[] = []
  for (const f of files) {
    if (!aceptaFichero(f, accept)) rechazados.push({ file: f, motivo: `«${f.name}» no es de un tipo permitido.` })
    else if (f.size > maxSizeMB * 1024 * 1024) rechazados.push({ file: f, motivo: `«${f.name}» pesa más de ${maxSizeMB} MB.` })
    else validos.push(f)
  }
  return { validos, rechazados }
}
