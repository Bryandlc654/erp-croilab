import { describe, expect, it } from 'vitest'
import { aplicarEdicion, buscar, claveMes, conAnterior, delta, estimarIngresos, etiquetaMes, euros, global, mesAnterior, servicioContratado, textoDelta, videoDe } from './logica'
import type { ContenidoEditable, DatosPortal, Metrica } from './schemas'

const met = (clave: string, n: Partial<Metrica> = {}): Metrica => ({
  clave,
  etiqueta: etiquetaMes(clave),
  mes: '',
  ll: 0,
  wa: 0,
  fo: 0,
  vi: 0,
  ap: 0,
  ctr: 0,
  total: 0,
  src: {},
  geo: {},
  ...n,
})

describe('meses con año', () => {
  const ref = new Date(2027, 0, 15)
  it('lee etiquetas con y sin año', () => {
    expect(claveMes('2026-06', ref)).toBe('2026-06')
    expect(claveMes('Junio 2025', ref)).toBe('2025-06')
    expect(claveMes('marzo de 2024', ref)).toBe('2024-03')
    expect(claveMes('Enero', ref)).toBe('2027-01')
    expect(claveMes('Diciembre', ref)).toBe('2026-12')
    expect(claveMes('General', ref)).toBeNull()
  })
  it('mes anterior cruza el año', () => {
    expect(mesAnterior('2026-01')).toBe('2025-12')
    expect(etiquetaMes('2026-09')).toBe('Septiembre 2026')
  })
})

describe('variaciones', () => {
  it('delta', () => {
    expect(delta(12, 10)).toEqual({ tipo: 'sube', pct: 20 })
    expect(delta(5, 10)).toEqual({ tipo: 'baja', pct: -50 })
    expect(delta(5, 0)).toEqual({ tipo: 'nuevo', pct: 0 })
    expect(delta(0, 0).tipo).toBe('igual')
    expect(delta(5, null).tipo).toBe('partida')
    expect(textoDelta(delta(12, 10))).toBe('▲ +20%')
  })
  it('compara con el mes de calendario anterior, no con el anterior de la lista', () => {
    const l = [met('2026-06', { total: 5 }), met('2026-08', { total: 9 })]
    expect(conAnterior(l, '2026-08').anterior).toBeNull()
    expect(conAnterior([...l, met('2026-07', { total: 3 })], '2026-08').anterior?.total).toBe(3)
  })
  it('global: suma y CTR de clics entre apariciones', () => {
    const g = global([met('2026-07', { vi: 10, ap: 1000, ll: 1, ctr: 1, src: { Direct: 2 } }), met('2026-08', { vi: 30, ap: 1000, wa: 2, ctr: 3, src: { Direct: 3 } })])
    expect(g.total).toBe(3)
    expect(g.ctr).toBe(2)
    expect(g.src).toEqual({ Direct: 5 })
  })
})

describe('formato y utilidades', () => {
  it('euros desde céntimos', () => {
    expect(euros(123456)).toBe('1.234,56 €')
    expect(euros(5)).toBe('0,05 €')
    expect(euros(-1000)).toBe('-10,00 €')
  })
  it('servicios: null = todos, alias de Meta y tiendas', () => {
    expect(servicioContratado(null, 'SEO')).toBe(true)
    expect(servicioContratado(['Meta Ads'], 'Meta')).toBe(true)
    expect(servicioContratado(['Tienda online'], 'Tiendas online')).toBe(true)
    expect(servicioContratado(['SEO'], 'SEM')).toBe(false)
    expect(videoDe({ 'Meta Ads': 'abc' }, 'Meta', 'gen')).toBe('abc')
    expect(videoDe({}, 'SEO', 'gen')).toBe('gen')
  })
  it('calculadora', () => {
    expect(estimarIngresos(40, 5, 1500)).toEqual({ clientes: 2, ingresos: 3000 })
    expect(estimarIngresos(10, 150, 100).clientes).toBe(10)
  })
})

const base = {
  secciones: { metricas: true, progreso: true, informes: true, como: true, accesos: true, plan: false },
  servicios: ['SEO'],
  informes: [{ clave: '2026-09', mes: 'Septiembre', etiqueta: 'Septiembre 2026', titulo: 'Informe de septiembre', texto: '', url: '' }],
}

describe('buscador', () => {
  const s = ['Diseño web', 'SEO', 'SEM']
  it('servicio contratado → su ficha; no contratado → sección', () => {
    expect(buscar('seo', base, s)).toEqual({ tipo: 'servicio', nombre: 'SEO' })
    expect(buscar('sem', base, s)).toEqual({ tipo: 'vista', vista: 'metodo' })
  })
  it('palabras clave respetando las secciones visibles', () => {
    expect(buscar('llamadas', base, s)).toEqual({ tipo: 'vista', vista: 'metricas' })
    expect(buscar('precio', base, s)).toBeNull()
    expect(buscar('septiembre', base, s)).toEqual({ tipo: 'vista', vista: 'informes' })
    expect(buscar('  ', base, s)).toBeNull()
  })
})

describe('editor en vivo', () => {
  it('pinta lo editado como lo verá el cliente', () => {
    const d = {
      cliente: { id: 1, name: 'A', saludo: 'A', iniciales: 'A', username: 'a', actual: '2026-09', actual_etiqueta: 'Septiembre 2026' },
      progreso: [],
      informes: [],
    } as unknown as DatosPortal
    const c: ContenidoEditable = {
      name: 'Nuevo',
      username: 'n',
      iniciales: '',
      saludo: '',
      actual: 'Octubre 2026',
      tipo_id: null,
      conversiones: true,
      looker: 'https://evil.test',
      servicios: null,
      estado: { nombre: 'E', etiqueta: '', siguiente: '', fases: [{ t: '', s: '', estado: 'now' }, { t: 'F', s: '', estado: 'done' }] },
      plan: { resumen: '', items: [{ n: '', t: '' }], detalle: [] },
      accesos: [{ b: 'X', s: '', u: 'javascript:alert(1)', tipo: 'web' }],
      tareas: [{ mes: 'Agosto', completado: [{ t: 'Hecho', d: '' }], pendiente: [] }, { mes: 'Octubre 2026', completado: [], pendiente: [{ t: 'Ya', d: '' }] }],
      informes: [{ mes: 'Agosto', titulo: 'Ag', texto: 'x', url: '' }, { mes: '', titulo: '', texto: '', url: '' }],
    }
    const r = aplicarEdicion(d, c, new Date(2026, 9, 8))
    expect(r.cliente.saludo).toBe('Nuevo')
    expect(r.cliente.iniciales).toBe('CL')
    expect(r.cliente.actual).toBe('2026-10')
    expect(r.estado.fases.map((f) => f.t)).toEqual(['F'])
    expect(r.plan.items).toEqual([])
    expect(r.accesos[0].u).toBe('')
    expect(r.looker).toBe('')
    expect(r.progreso.map((p) => p.etiqueta)).toEqual(['Octubre 2026', 'Agosto 2026'])
    expect(r.informes.map((i) => i.titulo)).toEqual(['Ag'])
  })
})
