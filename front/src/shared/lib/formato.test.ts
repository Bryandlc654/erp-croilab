import { describe, expect, it } from 'vitest'
import { aFecha, eur, eur0, eurk, fechaCorta, fechaLarga, haceTiempo, horaRelativa, mesLabel, mesNombre, numero } from './formato'

describe('dinero', () => {
  it('eur: miles con punto, decimales con coma y € detrás (también con 4 cifras)', () => {
    expect(eur(1234.56)).toBe('1.234,56 €')
    expect(eur(1234)).toBe('1.234,00 €')
    expect(eur(0)).toBe('0,00 €')
    expect(eur(1234567.891)).toBe('1.234.567,89 €')
    expect(eur(-50.5)).toBe('-50,50 €')
  })

  it('no pinta «-0,00»', () => {
    expect(eur(-0.001)).toBe('0,00 €')
  })

  it('eur0 redondea sin céntimos', () => {
    expect(eur0(1234.56)).toBe('1.235 €')
    expect(eur0(999)).toBe('999 €')
  })

  it('eurk compacta en miles sin «,0»', () => {
    expect(eurk(12400)).toBe('12,4K €')
    expect(eurk(12000)).toBe('12K €')
    expect(eurk(1250000)).toBe('1.250K €')
    expect(eurk(850)).toBe('850 €')
  })

  it('numero con decimales', () => {
    expect(numero(1000.5, 1)).toBe('1.000,5')
    expect(numero(Number.NaN)).toBe('0')
  })
})

describe('meses', () => {
  it('mesNombre y mesLabel en español', () => {
    expect(mesNombre(1)).toBe('Enero')
    expect(mesNombre(12)).toBe('Diciembre')
    expect(mesLabel('2026-03')).toBe('Marzo 2026')
    expect(mesLabel('2026-10-08')).toBe('Octubre 2026')
    expect(mesLabel('no')).toBe('')
  })
})

describe('fechas', () => {
  it('aFecha lee AAAA-MM-DD como hora local (no UTC)', () => {
    const d = aFecha('2026-03-14')!
    expect([d.getFullYear(), d.getMonth(), d.getDate(), d.getHours()]).toEqual([2026, 2, 14, 0])
    const h = aFecha('2026-03-14 09:05:00')!
    expect([h.getHours(), h.getMinutes()]).toEqual([9, 5])
    expect(aFecha('')).toBeNull()
    expect(aFecha('basura')).toBeNull()
  })

  it('fechaCorta dd/mm/aa y vacío configurable', () => {
    expect(fechaCorta('2026-03-04')).toBe('04/03/26')
    expect(fechaCorta(null)).toBe('')
    expect(fechaCorta(null, '—')).toBe('—')
  })

  it('fechaLarga', () => {
    expect(fechaLarga('2026-07-27')).toBe('27 de julio de 2026')
  })
})

describe('horaRelativa', () => {
  const ahora = new Date(2026, 9, 8, 15, 30)
  it.each([
    ['2026-10-08 15:29:40', 'justo ahora'],
    ['2026-10-08 15:29:00', 'hace 1 minuto'],
    ['2026-10-08 15:05:00', 'hace 25 minutos'],
    ['2026-10-08 13:00:00', 'hace 2 horas'],
    ['2026-10-07 09:05:00', 'ayer a las 9:05'],
    ['2026-07-27 14:30:00', '27 de jul. a las 14:30'],
    ['2025-07-27 14:30:00', '27 de jul. de 2025'],
  ])('%s → %s', (iso, texto) => {
    expect(horaRelativa(iso, ahora)).toBe(texto)
  })
})

describe('haceTiempo', () => {
  it.each([
    [10, 'ahora'],
    [300, 'hace 5 min'],
    [7200, 'hace 2 h'],
    [3 * 86400, 'hace 3 d'],
  ])('%i s → %s', (s, texto) => expect(haceTiempo(s)).toBe(texto))
})
