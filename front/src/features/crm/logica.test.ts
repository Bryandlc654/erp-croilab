import { describe, expect, it } from 'vitest'
import {
  cambiarMapeo,
  chipsFiltros,
  columnasEmbudo,
  contarAvanzados,
  enlaceWhatsapp,
  filtrosDeParams,
  filtrosDeVista,
  importeCelda,
  leerImporte,
  mesCorto,
  paramsDeFiltros,
  rangoRapido,
  textoCondiciones,
  tonoParado,
  vecino,
  vistaDeFiltros,
} from './logica'
import type { Catalogos, Fase, Negocio } from './schemas'

const fase = (slug: string, nombre: string, tipo: Fase['tipo'], orden: number): Fase => ({ id: orden, slug, nombre, tipo, orden, probabilidad: 0, color: '#123456', estructural: false })
const FASES = [fase('lead_nuevo', 'Lead nuevo', 'abierta', 1), fase('propuesta', 'Propuesta enviada', 'abierta', 2), fase('ganado', 'Cerrado ganado', 'ganada', 3), fase('perdido', 'Cerrado perdido', 'perdida', 4)]
const CAT = { fases: FASES, etiquetas: [{ id: 3, nombre: 'VIP', color: '#000' }] } as unknown as Catalogos
const EQUIPO = [{ id: 2, username: 'laura', foto: null }]

const negocio = (o: Partial<Negocio>): Negocio =>
  ({ id: 1, contact_id: 1, contacto: { nombre: 'A', empresa: '', sector: '' }, nombre: 'N', valor: null, servicio: '', fase: 'lead_nuevo', tipo_fase: 'abierta', probabilidad: 0,
    fecha_cierre_prevista: null, fecha_entrada_fase: null, dias_en_fase: 0, fecha_cierre_real: null, motivo_perdida: null, motivo_perdida_txt: '', fecha_reactivacion: null,
    propietario_id: null, archivado: false, client_id: null, invoice_id: null, etiquetas: [], ...o }) as Negocio

describe('filtros ⇄ URL', () => {
  it('lee y escribe sin vacíos y conserva otras claves', () => {
    const f = filtrosDeParams(new URLSearchParams('sector=Salud&quick=act7&q=%20ana%20&x=1'))
    expect(f.sector).toBe('Salud')
    expect(f.q).toBe('ana')
    expect(f.fase).toBe('')
    const p = paramsDeFiltros({ ...f, sector: '' }, new URLSearchParams('x=1&sector=Salud'))
    expect(p.get('sector')).toBeNull()
    expect(p.get('quick')).toBe('act7')
    expect(p.get('x')).toBe('1')
  })

  it('cuenta solo los filtros avanzados', () => {
    expect(contarAvanzados({ q: 'a', quick: 'act7', sector: 'Salud', vmin: '100' })).toBe(2)
  })

  it('vistas guardadas: de y hacia los filtros', () => {
    expect(filtrosDeVista({ sector: 'Salud', prop: 2, tag: 3 })).toEqual({ sector: 'Salud', prop: '2', tag: '3' })
    expect(vistaDeFiltros(filtrosDeParams(new URLSearchParams('fase=propuesta&sort=valor')))).toEqual({ fase: 'propuesta', sort: 'valor' })
  })

  it('chips de filtros activos con nombres legibles', () => {
    const chips = chipsFiltros(filtrosDeParams(new URLSearchParams('q=bar&fase=propuesta&prop=2&tag=3&vmin=2500&fdesde=2026-03-14')), CAT, EQUIPO)
    expect(chips.map((c) => c.label)).toEqual(['«bar»', 'Embudo: Propuesta enviada', 'Propietario: laura', 'Etiqueta: VIP', '≥ 2.500 €', 'Desde 14/03/26'])
  })
})

describe('importes', () => {
  it('lee importes a la española como el antiguo num_es', () => {
    expect(leerImporte('1.234,56')).toBe(1234.56)
    expect(leerImporte('12,5')).toBe(12.5)
    expect(leerImporte('12.5')).toBe(12.5)
    expect(leerImporte('1.234')).toBe(1234)
    expect(leerImporte('1.200 €')).toBe(1200)
    expect(leerImporte('abc')).toBeNull()
  })

  it('formatea la celda sin céntimos si no los tiene', () => {
    expect(importeCelda(12500)).toBe('12.500')
    expect(importeCelda(3200.5)).toBe('3.200,50')
    expect(importeCelda(null)).toBe('')
  })
})

describe('embudo', () => {
  it('una columna por fase con su total exacto en euros', () => {
    const cols = columnasEmbudo(FASES, [negocio({ id: 1, valor: 0.1 }), negocio({ id: 2, valor: 0.2 }), negocio({ id: 3, fase: 'ganado', valor: 1000 })])
    expect(cols.map((c) => c.id)).toEqual(['lead_nuevo', 'propuesta', 'ganado', 'perdido'])
    expect(cols[0].items).toHaveLength(2)
    expect(cols[0].total).toBe('0 €')
    expect(cols[2].total).toBe('1.000 €')
  })

  it('aviso de negocio parado: 14 días naranja, 30 rojo y solo en fases abiertas', () => {
    expect(tonoParado(negocio({ dias_en_fase: 13 }))).toBeNull()
    expect(tonoParado(negocio({ dias_en_fase: 14 }))).toBe('aviso')
    expect(tonoParado(negocio({ dias_en_fase: 30 }))).toBe('peligro')
    expect(tonoParado(negocio({ dias_en_fase: 90, tipo_fase: 'ganada' }))).toBeNull()
  })
})

describe('textos y enlaces', () => {
  it('condiciones legibles de una lista activa', () => {
    expect(textoCondiciones({ sector: 'Salud', fase: 'propuesta', vmin: '2000', quick: 'act30', prop: 'sin' }, CAT, EQUIPO)).toBe(
      'Sector: Salud · Embudo: Propuesta enviada · Propietario: Sin propietario · Sin actividad +30 días · ≥ 2.000 €',
    )
  })

  it('WhatsApp con prefijo 34 para móviles españoles', () => {
    expect(enlaceWhatsapp('600 111 222')).toBe('https://wa.me/34600111222')
    expect(enlaceWhatsapp('+44 7700 900123')).toBe('https://wa.me/447700900123')
    expect(enlaceWhatsapp('')).toBeNull()
  })

  it('meses cortos y rangos rápidos', () => {
    const hoy = new Date(2026, 9, 8)
    expect(mesCorto('2026-05', hoy)).toBe('may')
    expect(mesCorto('2025-12', hoy)).toBe('dic 25')
    expect(rangoRapido('mes', hoy)).toEqual({ desde: '2026-10-01', hasta: '2026-10-08' })
    expect(rangoRapido('tres', hoy)).toEqual({ desde: '2026-08-01', hasta: '2026-10-08' })
    expect(rangoRapido('anio', hoy)).toEqual({ desde: '2026-01-01', hasta: '2026-10-08' })
  })
})

describe('importación y navegación', () => {
  it('un campo solo puede estar en una columna', () => {
    expect(cambiarMapeo(['nombre', 'email', ''], 2, 'nombre')).toEqual(['', 'email', 'nombre'])
    expect(cambiarMapeo(['nombre', 'email'], 1, '')).toEqual(['nombre', ''])
  })

  it('contacto anterior y siguiente de la lista filtrada', () => {
    expect(vecino([4, 7, 9], 7, 1)).toBe(9)
    expect(vecino([4, 7, 9], 7, -1)).toBe(4)
    expect(vecino([4, 7, 9], 9, 1)).toBeNull()
    expect(vecino([4, 7, 9], 5, 1)).toBeNull()
  })
})
