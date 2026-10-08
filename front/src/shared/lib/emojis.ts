/* Datos y búsqueda del selector de emojis (assets/emoji/component.js del ERP
   antiguo). Los emojis son los nativos del sistema; nombres y palabras clave
   en español de `emojibase-data` (locale es), que se carga bajo demanda. */

export type Emoji = {
  /* El carácter (con selectores de variante). */
  e: string
  /* Nombre en español: «pulgar hacia arriba». */
  n: string
  /* Palabras clave. */
  k: string[]
  /* Grupo de emojibase (0 caras, 1 personas…). */
  g: number
}

export type CategoriaEmoji = { id: string; label: string; grupo: number | null }

/* Mismas categorías y orden que el selector antiguo. */
export const CATEGORIAS_EMOJI: CategoriaEmoji[] = [
  { id: 'recientes', label: 'Recientes', grupo: null },
  { id: 'caras', label: 'Caras y emoción', grupo: 0 },
  { id: 'personas', label: 'Personas', grupo: 1 },
  { id: 'animales', label: 'Animales y naturaleza', grupo: 3 },
  { id: 'comida', label: 'Comida y bebida', grupo: 4 },
  { id: 'actividades', label: 'Actividades', grupo: 6 },
  { id: 'viajes', label: 'Viajes y lugares', grupo: 5 },
  { id: 'objetos', label: 'Objetos', grupo: 7 },
  { id: 'simbolos', label: 'Símbolos', grupo: 8 },
  { id: 'banderas', label: 'Banderas', grupo: 9 },
]

/* Reacciones rápidas del chat y de los comentarios. */
export const REACCIONES_RAPIDAS = ['👍', '❤️', '😂', '😮', '😢', '🙏', '🔥', '👏']

export const CLAVE_RECIENTES = 'erpEmojiRecientes'
export const MAX_RECIENTES = 32
const MAX_RESULTADOS = 250
/* Versiones de Unicode más nuevas salen como cuadraditos en Windows 10/11:
   se quedan fuera hasta que las fuentes del sistema las tengan. */
export const VERSION_MAX = 14

/* Lo que trae emojibase-data/es/data.json (solo lo que usamos). */
export type EmojiBase = { emoji: string; label: string; tags?: string[]; group?: number; order?: number; version?: number }

export function normalizar(s: string) {
  return s
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .trim()
}

/* Filtra (sin «componentes» de tono de piel ni letras sueltas, sin versiones
   nuevas) y ordena como el teclado del sistema. */
export function prepararEmojis(datos: EmojiBase[]): Emoji[] {
  return datos
    .filter((d) => d.group !== undefined && d.group !== 2 && (d.version ?? 0) <= VERSION_MAX)
    .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
    .map((d) => ({ e: d.emoji, n: d.label, k: d.tags ?? [], g: d.group as number }))
}

/* Búsqueda con el orden del antiguo: nombre exacto > el nombre empieza por >
   alguna palabra clave (o palabra del nombre) empieza por > lo contiene. */
export function buscarEmojis(lista: Emoji[], consulta: string, max = MAX_RESULTADOS): Emoji[] {
  const q = normalizar(consulta)
  if (!q) return []
  const cubos: Emoji[][] = [[], [], [], []]
  for (const em of lista) {
    const n = normalizar(em.n)
    const ks = em.k.map(normalizar)
    if (n === q) cubos[0].push(em)
    else if (n.startsWith(q)) cubos[1].push(em)
    else if (ks.some((k) => k.startsWith(q)) || n.split(/[\s:,-]+/).some((p) => p.startsWith(q))) cubos[2].push(em)
    else if (n.includes(q) || ks.some((k) => k.includes(q))) cubos[3].push(em)
  }
  return cubos.flat().slice(0, max)
}

let cargando: Promise<Emoji[]> | null = null

/* Carga (una sola vez) los datos en español. Va en un trozo aparte del bundle. */
export function cargarEmojis(): Promise<Emoji[]> {
  if (!cargando) {
    cargando = import('emojibase-data/es/data.json')
      .then((m) => prepararEmojis((m.default ?? m) as unknown as EmojiBase[]))
      .catch((e: unknown) => {
        cargando = null
        throw e
      })
  }
  return cargando
}

/* Recientes en localStorage (como el antiguo): el último elegido primero. */
export function leerRecientes(clave = CLAVE_RECIENTES): string[] {
  try {
    const v: unknown = JSON.parse(localStorage.getItem(clave) ?? '[]')
    return Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string').slice(0, MAX_RECIENTES) : []
  } catch {
    return []
  }
}

export function anadirReciente(lista: string[], emoji: string, max = MAX_RECIENTES): string[] {
  return [emoji, ...lista.filter((x) => x !== emoji)].slice(0, max)
}

export function guardarReciente(emoji: string, clave = CLAVE_RECIENTES): string[] {
  const nueva = anadirReciente(leerRecientes(clave), emoji)
  try {
    localStorage.setItem(clave, JSON.stringify(nueva))
  } catch {
    // Sin almacenamiento (modo privado): los recientes duran lo que la pestaña.
  }
  return nueva
}
