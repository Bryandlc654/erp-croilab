import { describe, expect, it } from 'vitest'
import { diasCalendario, parsearFecha, sumarDias, tonoVencimiento } from './fechas'

const HOY = new Date(2026, 9, 8)

describe('parsearFecha()', () => {
  it.each([
    ['8/10/26', '2026-10-08'],
    ['08/10/2026', '2026-10-08'],
    ['8-10-26', '2026-10-08'],
    ['8.10.26', '2026-10-08'],
    [' 1 / 2 / 27 ', '2027-02-01'],
    ['2026-10-08', '2026-10-08'],
    ['29/02/28', '2028-02-29'],
  ])('«%s» → %s', (texto, iso) => {
    expect(parsearFecha(texto, HOY)).toBe(iso)
  })

  it('sin año usa el año en curso', () => {
    expect(parsearFecha('3/4', HOY)).toBe('2026-04-03')
  })

  it.each(['', '31/02/26', '29/02/27', '0/1/26', '12/13/26', 'mañana', '1/1/1', '1//26'])('«%s» no es una fecha', (texto) => {
    expect(parsearFecha(texto, HOY)).toBeNull()
  })
})

describe('diasCalendario()', () => {
  it('la semana empieza en lunes y completa semanas enteras', () => {
    // Octubre 2026 empieza en jueves: delante van lunes 28, martes 29 y miércoles 30 de septiembre.
    const dias = diasCalendario(2026, 9)
    expect(dias.length % 7).toBe(0)
    expect(dias.slice(0, 4).map((d) => d.iso)).toEqual(['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01'])
    expect(dias[3]).toEqual({ iso: '2026-10-01', dia: 1, delMes: true })
    expect(dias.filter((d) => d.delMes)).toHaveLength(31)
    expect(dias.at(-1)!.iso).toBe('2026-11-01')
  })

  it('un mes que empieza en lunes no lleva relleno delante', () => {
    expect(diasCalendario(2026, 5)[0].iso).toBe('2026-06-01')
  })
})

describe('utilidades', () => {
  it('sumarDias cruza meses y años', () => {
    expect(sumarDias('2026-12-30', 3)).toBe('2027-01-02')
    expect(sumarDias('2026-03-01', -1)).toBe('2026-02-28')
  })

  it('tonoVencimiento: vencida, vence en ≤2 días o nada', () => {
    expect(tonoVencimiento('2026-10-07', HOY)).toBe('late')
    expect(tonoVencimiento('2026-10-08', HOY)).toBe('soon')
    expect(tonoVencimiento('2026-10-10', HOY)).toBe('soon')
    expect(tonoVencimiento('2026-10-11', HOY)).toBeNull()
    expect(tonoVencimiento(null, HOY)).toBeNull()
  })
})
