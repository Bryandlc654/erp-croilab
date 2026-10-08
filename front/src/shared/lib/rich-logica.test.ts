import { afterEach, describe, expect, it } from 'vitest'
import datosEs from 'emojibase-data/es/data.json'
import { anadirReciente, buscarEmojis, guardarReciente, leerRecientes, normalizar, prepararEmojis, type Emoji, type EmojiBase } from './emojis'
import { consultaMencion, filtrarPersonas, insertarMencion } from './menciones'
import { marcar, ordenarChecklist, ordenTrasArrastre, progreso } from './checklist'
import { columnaEnX, esMovimiento, moverTarjeta, pasoTeclado, ubicar } from './kanban'
import { intercalarPorFecha, repartirImagenes } from './comentarios'
import { tamanoLegible, tipoAdjunto, validarFicheros } from './adjuntos'
import { configGrafica, conAlfa, hayDatos } from '../ui/rich/charts'

const E = (e: string, n: string, k: string[] = [], g = 0): Emoji => ({ e, n, k, g })

describe('emojis: búsqueda', () => {
  const lista = [E('🙂', 'cara sonriendo levemente', ['sonrisa']), E('😀', 'cara sonriendo', ['feliz', 'sonrisa']), E('🐱', 'gato', ['animal', 'mascota'], 3), E('🐈', 'gato negro', ['felino'], 3), E('🍕', 'pizza', ['comida'], 4), E('🎂', 'pastel de cumpleaños', ['tarta', 'celebración'], 4)]

  it('exacto > empieza por > palabra clave > contiene', () => {
    expect(buscarEmojis(lista, 'gato').map((x) => x.e)).toEqual(['🐱', '🐈'])
    expect(buscarEmojis(lista, 'cara sonriendo').map((x) => x.e)).toEqual(['😀', '🙂'])
    expect(buscarEmojis(lista, 'son').map((x) => x.e)).toEqual(['🙂', '😀'])
    expect(buscarEmojis(lista, 'tar').map((x) => x.e)).toEqual(['🎂'])
    expect(buscarEmojis(lista, 'izz').map((x) => x.e)).toEqual(['🍕'])
  })

  it('ignora mayúsculas y tildes', () => {
    expect(buscarEmojis(lista, 'CELEBRACION').map((x) => x.e)).toEqual(['🎂'])
    expect(buscarEmojis(lista, 'cumpleanos').map((x) => x.e)).toEqual(['🎂'])
    expect(normalizar('  Ñandú ')).toBe('nandu')
  })

  it('vacío no busca y como mucho 250', () => {
    expect(buscarEmojis(lista, '  ')).toEqual([])
    const muchos = Array.from({ length: 400 }, (_, k) => E(String(k), 'cosa ' + k))
    expect(buscarEmojis(muchos, 'cosa')).toHaveLength(250)
  })

  it('datos reales en español: filtra componentes y versiones nuevas', () => {
    const todos = prepararEmojis(datosEs as unknown as EmojiBase[])
    expect(todos.length).toBeGreaterThan(1500)
    expect(todos.some((x) => x.g === 2)).toBe(false)
    expect(buscarEmojis(todos, 'pulgar hacia arriba')[0].e.startsWith('👍')).toBe(true)
    expect(buscarEmojis(todos, 'corazón').length).toBeGreaterThan(5)
    expect(buscarEmojis(todos, 'fuego')[0].e).toBe('🔥')
  })
})

describe('emojis: recientes', () => {
  afterEach(() => localStorage.clear())

  it('el último primero, sin repetir, máx. 32', () => {
    let l: string[] = []
    for (let k = 0; k < 40; k++) l = anadirReciente(l, String(k))
    expect(l).toHaveLength(32)
    expect(l[0]).toBe('39')
    expect(anadirReciente(['a', 'b', 'c'], 'b')).toEqual(['b', 'a', 'c'])
  })

  it('se guardan en erpEmojiRecientes y toleran basura', () => {
    guardarReciente('🔥')
    guardarReciente('👍')
    expect(JSON.parse(localStorage.getItem('erpEmojiRecientes') ?? '')).toEqual(['👍', '🔥'])
    localStorage.setItem('erpEmojiRecientes', '{roto')
    expect(leerRecientes()).toEqual([])
    localStorage.setItem('erpEmojiRecientes', '[1,"😀"]')
    expect(leerRecientes()).toEqual(['😀'])
  })
})

describe('menciones', () => {
  const gente = [
    { id: 1, username: 'laura' },
    { id: 2, username: 'lucas' },
    { id: 3, username: 'paula' },
    { id: 4, username: 'víctor' },
  ]

  it('detecta la @ abierta antes del cursor', () => {
    expect(consultaMencion('hola @la')).toBe('la')
    expect(consultaMencion('@')).toBe('')
    expect(consultaMencion('correo pepe@emp')).toBeNull()
    expect(consultaMencion('hola @la ')).toBeNull()
  })

  it('prefijo primero, luego contiene, sin tildes', () => {
    expect(filtrarPersonas(gente, 'la').map((p) => p.username)).toEqual(['laura', 'paula'])
    expect(filtrarPersonas(gente, 'vic').map((p) => p.username)).toEqual(['víctor'])
    expect(filtrarPersonas(gente, '', 2)).toHaveLength(2)
  })

  it('inserta la mención y coloca el cursor', () => {
    expect(insertarMencion('hola @la', 8, 'laura')).toEqual({ valor: 'hola @laura ', cursor: 12 })
    expect(insertarMencion('hola @la qué tal', 8, 'laura')).toEqual({ valor: 'hola @laura qué tal', cursor: 12 })
    expect(insertarMencion('sin arroba', 4, 'laura')).toEqual({ valor: 'sin arroba', cursor: 4 })
  })
})

describe('checklist', () => {
  const items = [
    { id: 1, texto: 'a', hecho: true },
    { id: 2, texto: 'b', hecho: false },
    { id: 3, texto: 'c', hecho: false },
    { id: 4, texto: 'd', hecho: true },
  ]

  it('pendientes arriba y hechos abajo, cada grupo en su orden', () => {
    expect(ordenarChecklist(items).map((i) => i.id)).toEqual([2, 3, 1, 4])
  })

  it('un recién marcado se queda en su sitio mientras está retenido', () => {
    const tras = marcar(items, 2, true)
    expect(ordenarChecklist(tras, new Map([[2, false]])).map((i) => i.id)).toEqual([2, 3, 1, 4])
    expect(ordenarChecklist(tras).map((i) => i.id)).toEqual([3, 1, 2, 4])
    expect(tras[1].hecho).toBe(true)
    expect(items[1].hecho).toBe(false)
  })

  it('progreso', () => {
    expect(progreso(items)).toEqual({ hechos: 2, total: 4 })
    expect(progreso([])).toEqual({ hechos: 0, total: 0 })
  })

  it('arrastrar no mezcla pendientes con hechos', () => {
    expect(ordenTrasArrastre(items, [3, 2, 1, 4])).toEqual([3, 2, 1, 4])
    expect(ordenTrasArrastre(items, [2, 1, 3, 4])).toEqual([2, 3, 1, 4])
  })
})

describe('kanban', () => {
  type T = { id: string }
  const cols = [
    { id: 'lead', items: [{ id: 'a' }, { id: 'b' }, { id: 'c' }] as T[] },
    { id: 'prop', items: [{ id: 'd' }] as T[] },
    { id: 'gan', items: [] as T[] },
  ]
  const g = (t: T) => t.id
  const ids = (cs: typeof cols) => cs.map((c) => c.items.map(g).join(''))

  it('ubicar', () => {
    expect(ubicar(cols, g, 'd')).toEqual({ col: 1, idx: 0 })
    expect(ubicar(cols, g, 'z')).toBeNull()
  })

  it('mover entre columnas y dentro de una', () => {
    expect(ids(moverTarjeta(cols, g, 'b', 'prop', 1))).toEqual(['ac', 'db', ''])
    expect(ids(moverTarjeta(cols, g, 'b', 'gan', 0))).toEqual(['ac', 'd', 'b'])
    expect(ids(moverTarjeta(cols, g, 'a', 'lead', 2))).toEqual(['bca', 'd', ''])
    expect(ids(moverTarjeta(cols, g, 'c', 'lead', 0))).toEqual(['cab', 'd', ''])
    // Índice fuera de rango: al final.
    expect(ids(moverTarjeta(cols, g, 'a', 'prop', 99))).toEqual(['bc', 'da', ''])
    // No toca el original y conserva las columnas que no cambian.
    const r = moverTarjeta(cols, g, 'a', 'prop', 0)
    expect(r[2]).toBe(cols[2])
    expect(ids(cols)).toEqual(['abc', 'd', ''])
  })

  it('esMovimiento', () => {
    expect(esMovimiento(cols, g, 'b', 'lead', 1)).toBe(false)
    expect(esMovimiento(cols, g, 'b', 'lead', 0)).toBe(true)
    expect(esMovimiento(cols, g, 'b', 'prop', 0)).toBe(true)
  })

  it('teclado: flechas', () => {
    const L = [3, 1, 0]
    expect(pasoTeclado(L, { col: 0, idx: 2 }, 'ArrowRight')).toEqual({ col: 1, idx: 1 })
    expect(pasoTeclado(L, { col: 1, idx: 0 }, 'ArrowRight')).toEqual({ col: 2, idx: 0 })
    expect(pasoTeclado(L, { col: 2, idx: 0 }, 'ArrowRight')).toEqual({ col: 2, idx: 0 })
    expect(pasoTeclado(L, { col: 0, idx: 0 }, 'ArrowLeft')).toEqual({ col: 0, idx: 0 })
    expect(pasoTeclado(L, { col: 0, idx: 0 }, 'ArrowUp')).toEqual({ col: 0, idx: 0 })
    expect(pasoTeclado(L, { col: 0, idx: 1 }, 'ArrowDown')).toEqual({ col: 0, idx: 2 })
    expect(pasoTeclado(L, { col: 0, idx: 2 }, 'ArrowDown')).toEqual({ col: 0, idx: 2 })
  })

  it('columna bajo el puntero', () => {
    const cajas = [
      { left: 0, width: 264 },
      { left: 278, width: 264 },
    ]
    expect(columnaEnX(cajas, 10)).toBe(0)
    expect(columnaEnX(cajas, 300)).toBe(1)
    expect(columnaEnX(cajas, 270)).toBe(0)
    expect(columnaEnX(cajas, 900)).toBe(1)
  })
})

describe('adjuntos y comentarios', () => {
  const img = (id: number) => ({ id, nombre: `f${id}.png`, url: `/a/${id}` })
  it('las imágenes rellenan los [[img]] en orden y el resto va debajo', () => {
    const pdf = { id: 9, nombre: 'c.pdf', url: '/a/9' }
    const r = repartirImagenes('a\n[[img]]\nb', [img(1), pdf, img(2)])
    expect(r.huecos).toEqual(['/a/1'])
    expect(r.debajo.map((a) => a.id)).toEqual([9, 2])
    expect(repartirImagenes('sin huecos', [img(1)]).debajo).toHaveLength(1)
  })

  it('intercala comentarios y eventos por fecha', () => {
    const r = intercalarPorFecha([{ creado: '2026-10-08 10:00:00', id: 'c' }], [{ at: '2026-10-08 09:00:00', id: 'e1' }, { at: '2026-10-08 11:00:00', id: 'e2' }])
    expect(r.map((x) => (x.tipo === 'evento' ? x.e.id : x.c.id))).toEqual(['e1', 'c', 'e2'])
  })

  it('tipo, tamaño y validación de ficheros', () => {
    expect(tipoAdjunto('foto.JPG')).toBe('imagen')
    expect(tipoAdjunto('x', 'application/pdf')).toBe('pdf')
    expect(tipoAdjunto('x.zip')).toBe('archivo')
    expect(tamanoLegible(1536)).toBe('1,5 KB')
    expect(tamanoLegible(2_500_000)).toBe('2,4 MB')
    const f = (name: string, type: string, size: number) => ({ name, type, size })
    const { validos, rechazados } = validarFicheros([f('a.png', 'image/png', 10), f('b.exe', '', 10), f('c.jpg', 'image/jpeg', 30 * 1024 * 1024)], { accept: 'image/*,.pdf', maxSizeMB: 25 })
    expect(validos.map((x) => x.name)).toEqual(['a.png'])
    expect(rechazados.map((x) => x.file.name)).toEqual(['b.exe', 'c.jpg'])
  })
})

describe('gráficas: configuración', () => {
  const datos = { labels: ['a', 'b'], series: [{ label: 'Valor', data: [1, 2] }] }
  it('barras, horizontales, donut y línea de finanzas', () => {
    expect(configGrafica('bar', datos, { oscuro: false }).type).toBe('bar')
    expect(configGrafica('barH', datos, { oscuro: false }).options?.indexAxis).toBe('y')
    const d = configGrafica('doughnut', datos, { oscuro: true })
    expect(d.type).toBe('doughnut')
    expect(d.data.datasets[0].borderColor).toBe('#161616')
    const l = configGrafica('lineaFinanzas', datos, { oscuro: false })
    expect(l.data.datasets[0].borderColor).toBe('#111318')
    expect(configGrafica('lineaFinanzas', datos, { oscuro: true }).data.datasets[0].borderColor).toBe('#e5e5e5')
  })
  it('vacío y alfa', () => {
    expect(hayDatos({ labels: [], series: [] })).toBe(false)
    expect(hayDatos(datos)).toBe(true)
    expect(conAlfa('#5b8def', 0.1)).toBe('#5b8def1a')
  })
})
