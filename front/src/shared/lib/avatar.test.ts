import { describe, expect, it } from 'vitest'
import { colorDe, iniciales } from './avatar'

describe('colorDe()', () => {
  // Valores sacados del ERP PHP (avatar_color): deben coincidir byte a byte.
  it.each([
    ['Aeternum', '#c2410c'],
    ['bdelacruz654', '#c2410c'],
    ['Bodegas Ribera', '#dc2626'],
    ['Clínica Dental Sur', '#b45309'],
    ['laura', '#047857'],
  ])('%s → %s (igual que en PHP)', (nombre, color) => {
    expect(colorDe(nombre)).toBe(color)
  })
})

describe('iniciales()', () => {
  it('toma las dos primeras letras en mayúsculas', () => {
    expect(iniciales('Aeternum')).toBe('AE')
    expect(iniciales('  laura ')).toBe('LA')
  })

  it('las iniciales guardadas del cliente mandan', () => {
    expect(iniciales('Aeternum', 'at')).toBe('AT')
    expect(iniciales('Bodegas Ribera', 'BR')).toBe('BR')
  })

  it('unas iniciales guardadas vacías no cuentan', () => {
    expect(iniciales('Aeternum', '  ')).toBe('AE')
    expect(iniciales('Aeternum', null)).toBe('AE')
  })

  it('sin nombre devuelve ?', () => {
    expect(iniciales('')).toBe('?')
  })
})
