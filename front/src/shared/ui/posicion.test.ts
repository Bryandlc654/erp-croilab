import { describe, expect, it } from 'vitest'
import { calcularPosicion, fueraDePantalla, rectDePunto, type Rect } from './posicion'

const ancla = (top: number, left: number, width = 100, height = 30): Rect => ({ top, left, width, height, bottom: top + height, right: left + width })
const VP = { vw: 1000, vh: 800 }

describe('calcularPosicion()', () => {
  it('abre debajo del ancla, alineado a su izquierda', () => {
    const p = calcularPosicion({ ancla: ancla(100, 200), ancho: 180, alto: 200, ...VP })
    expect(p).toMatchObject({ lado: 'bottom', top: 134, left: 200 })
    expect(p.bottom).toBeUndefined()
  })

  it('se voltea hacia arriba si abajo no cabe y arriba hay más sitio', () => {
    const p = calcularPosicion({ ancla: ancla(700, 200), ancho: 180, alto: 288, ...VP })
    expect(p.lado).toBe('top')
    // Anclado por abajo: crece hacia arriba si cambia de alto.
    expect(p.bottom).toBe(800 - 700 + 4)
    expect(p.top).toBeUndefined()
  })

  it('no se voltea si arriba hay aún menos sitio', () => {
    const p = calcularPosicion({ ancla: ancla(150, 200, 100, 30), ancho: 180, alto: 700, ...VP })
    expect(p.lado).toBe('bottom')
    // Se limita el alto al espacio disponible.
    expect(p.maxHeight).toBe(800 - 180 - 4 - 8)
  })

  it('con flip=false respeta el lado pedido', () => {
    const p = calcularPosicion({ ancla: ancla(700, 200), ancho: 180, alto: 288, flip: false, ...VP })
    expect(p.lado).toBe('bottom')
  })

  it('top-* prefiere arriba y baja si arriba no cabe', () => {
    expect(calcularPosicion({ ancla: ancla(500, 200), ancho: 180, alto: 100, placement: 'top-start', ...VP }).lado).toBe('top')
    expect(calcularPosicion({ ancla: ancla(40, 200), ancho: 180, alto: 100, placement: 'top-start', ...VP }).lado).toBe('bottom')
  })

  it('acota a 8px de los bordes', () => {
    expect(calcularPosicion({ ancla: ancla(100, 950), ancho: 200, alto: 100, ...VP }).left).toBe(1000 - 200 - 8)
    expect(calcularPosicion({ ancla: ancla(100, 2), ancho: 200, alto: 100, ...VP }).left).toBe(8)
  })

  it('bottom-end alinea el borde derecho con el del ancla', () => {
    expect(calcularPosicion({ ancla: ancla(100, 500, 100), ancho: 180, alto: 100, placement: 'bottom-end', ...VP }).left).toBe(600 - 180)
  })

  it('un punto (clic derecho) cerca de la esquina queda dentro de la pantalla', () => {
    const p = calcularPosicion({ ancla: rectDePunto(990, 790), ancho: 184, alto: 160, offset: 0, ...VP })
    expect(p.left).toBe(1000 - 184 - 8)
    expect(p.lado).toBe('top')
    expect(p.bottom).toBe(10)
  })
})

describe('fueraDePantalla()', () => {
  it('detecta un ancla que ha salido por arriba o por abajo', () => {
    expect(fueraDePantalla(ancla(-50, 10, 100, 30), 800, 1000)).toBe(true)
    expect(fueraDePantalla(ancla(900, 10), 800, 1000)).toBe(true)
    expect(fueraDePantalla(ancla(100, 10), 800, 1000)).toBe(false)
  })
})
