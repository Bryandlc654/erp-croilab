import { describe, expect, it } from 'vitest'
import { huecoInsercion, indiceDestino, moverElemento, reordenar } from './ordenar'

describe('moverElemento()', () => {
  it('mueve hacia delante y hacia atrás sin tocar el original', () => {
    const l = ['a', 'b', 'c', 'd']
    expect(moverElemento(l, 0, 2)).toEqual(['b', 'c', 'a', 'd'])
    expect(moverElemento(l, 3, 0)).toEqual(['d', 'a', 'b', 'c'])
    expect(l).toEqual(['a', 'b', 'c', 'd'])
  })

  it('índices fuera de rango no rompen nada', () => {
    expect(moverElemento(['a', 'b'], 5, 0)).toEqual(['a', 'b'])
    expect(moverElemento(['a', 'b'], 0, 9)).toEqual(['b', 'a'])
  })
})

describe('huecoInsercion()', () => {
  // Tres filas de 40px una debajo de otra.
  const cajas = [0, 40, 80].map((top) => ({ top, left: 0, width: 200, height: 40 }))
  it('antes de la fila cuya mitad aún no se ha pasado', () => {
    expect(huecoInsercion(cajas, { x: 10, y: 5 }, 'y')).toBe(0)
    expect(huecoInsercion(cajas, { x: 10, y: 25 }, 'y')).toBe(1)
    expect(huecoInsercion(cajas, { x: 10, y: 61 }, 'y')).toBe(2)
    expect(huecoInsercion(cajas, { x: 10, y: 200 }, 'y')).toBe(3)
  })

  it('en horizontal mira la x', () => {
    const tabs = [0, 100, 200].map((left) => ({ top: 0, left, width: 100, height: 30 }))
    expect(huecoInsercion(tabs, { x: 160, y: 999 }, 'x')).toBe(2)
  })
})

describe('reordenar()', () => {
  const l = ['a', 'b', 'c', 'd']
  it('soltar en el hueco de después de otro elemento', () => {
    // «a» soltado al final (hueco 4) → última.
    expect(reordenar(l, 0, 4)).toEqual(['b', 'c', 'd', 'a'])
    // «d» soltado antes de «b» (hueco 1).
    expect(reordenar(l, 3, 1)).toEqual(['a', 'd', 'b', 'c'])
  })

  it('soltar justo antes o después de sí mismo no cambia nada', () => {
    expect(indiceDestino(1, 1)).toBe(1)
    expect(indiceDestino(1, 2)).toBe(1)
    expect(reordenar(l, 1, 2)).toEqual(l)
  })
})
