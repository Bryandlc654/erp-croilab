import { describe, expect, it } from 'vitest'
import { buscarAjustes, caducaEn, colorValido, dependientesQueCaen, fechaCorta, fechaCumple, hayCambios, requisitosQueFaltan, soloCifras } from './logica'

const REQ = {
  'crm.crear': ['ver.crm', 'general.editar'],
  'crm.convertir': ['ver.crm', 'ver.clientes', 'general.editar'],
  'equipo.gestionar': ['ver.ajustes'],
  'ajustes.editar': ['ver.ajustes', 'general.editar'],
}

describe('requisitos de la matriz de permisos', () => {
  it('pide solo lo que falta, también en cadena', () => {
    expect(requisitosQueFaltan('crm.crear', ['general.editar'], REQ)).toEqual(['ver.crm'])
    expect(requisitosQueFaltan('crm.convertir', [], REQ).sort()).toEqual(['general.editar', 'ver.clientes', 'ver.crm'])
    expect(requisitosQueFaltan('ver.crm', [], REQ)).toEqual([])
  })

  it('al apagar se caen los que dependen de él y estaban activos', () => {
    const tiene = ['ver.crm', 'crm.crear', 'general.editar', 'ver.ajustes', 'ajustes.editar']
    expect(dependientesQueCaen('ver.crm', tiene, REQ)).toEqual(['crm.crear'])
    expect(dependientesQueCaen('general.editar', tiene, REQ).sort()).toEqual(['ajustes.editar', 'crm.crear'])
    expect(dependientesQueCaen('ver.ajustes', tiene, REQ)).toEqual(['ajustes.editar'])
  })
})

describe('fechas', () => {
  it('caducidad en horas o minutos', () => {
    const ahora = new Date('2026-10-08T10:00:00Z')
    expect(caducaEn('2026-10-10T10:00:00Z', ahora)).toBe('caduca en ~48 h')
    expect(caducaEn('2026-10-08T10:20:00Z', ahora)).toBe('caduca en 20 min')
    expect(caducaEn('2026-10-08T09:00:00Z', ahora)).toBe('caducado')
  })

  it('cumpleaños y fecha corta', () => {
    expect(fechaCumple('1990-05-04')).toBe('4 de mayo')
    expect(fechaCumple('1990-12-25', true)).toBe('25 dic')
    expect(fechaCumple(null)).toBe('')
    expect(fechaCorta('2026-01-09')).toBe('09/01/2026')
  })
})

describe('buscador de ajustes', () => {
  it('sin tildes, desde dos letras y por varias palabras', () => {
    expect(buscarAjustes('w')).toEqual([])
    expect(buscarAjustes('whatsapp')[0]?.to).toBe('/ajustes/portal/contacto')
    expect(buscarAjustes('CONTRASEÑA').length).toBeGreaterThan(0)
    expect(buscarAjustes('google calendar')[0]?.titulo).toBe('Google Calendar')
    expect(buscarAjustes('zzzz')).toEqual([])
  })
})

describe('formularios', () => {
  it('normaliza color y teléfono', () => {
    expect(colorValido('1F2')).toBe('#11ff22')
    expect(colorValido('#1f232a')).toBe('#1f232a')
    expect(colorValido('rojo')).toBeNull()
    expect(soloCifras('+34 600-00 00 00')).toBe('34600000000')
  })

  it('detecta cambios sin guardar', () => {
    expect(hayCambios({ a: 'x', b: ['1'] }, { a: 'x', b: ['1'] })).toBe(false)
    expect(hayCambios({ a: 'x', b: ['1', '2'] }, { a: 'x', b: ['1'] })).toBe(true)
  })
})
