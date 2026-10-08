import { describe, expect, it } from 'vitest'
import { coincideAtajo, esCampoEditable } from './useHotkeys'

const tecla = (key: string, mods: Partial<Record<'ctrlKey' | 'metaKey' | 'altKey' | 'shiftKey', boolean>> = {}) => ({
  key,
  ctrlKey: false,
  metaKey: false,
  altKey: false,
  shiftKey: false,
  ...mods,
})

describe('coincideAtajo()', () => {
  it('mod = Ctrl o ⌘', () => {
    expect(coincideAtajo('mod+k', tecla('k', { ctrlKey: true }))).toBe(true)
    expect(coincideAtajo('mod+k', tecla('K', { metaKey: true }))).toBe(true)
    expect(coincideAtajo('mod+k', tecla('k'))).toBe(false)
  })

  it('una tecla suelta no salta con Ctrl pulsado', () => {
    expect(coincideAtajo('n', tecla('n'))).toBe(true)
    expect(coincideAtajo('n', tecla('n', { ctrlKey: true }))).toBe(false)
  })

  it('alias y shift opcional', () => {
    expect(coincideAtajo('esc', tecla('Escape'))).toBe(true)
    expect(coincideAtajo('/', tecla('/', { shiftKey: true }))).toBe(true)
    expect(coincideAtajo('shift+arrowleft', tecla('ArrowLeft'))).toBe(false)
  })
})

describe('esCampoEditable()', () => {
  it('inputs de texto, textarea y contenteditable sí; casillas y botones no', () => {
    const input = document.createElement('input')
    const check = document.createElement('input')
    check.type = 'checkbox'
    const div = document.createElement('div')
    expect(esCampoEditable(input)).toBe(true)
    expect(esCampoEditable(document.createElement('textarea'))).toBe(true)
    expect(esCampoEditable(check)).toBe(false)
    expect(esCampoEditable(document.createElement('button'))).toBe(false)
    expect(esCampoEditable(div)).toBe(false)
    expect(esCampoEditable(null)).toBe(false)
  })
})
