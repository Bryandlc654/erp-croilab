import { describe, expect, it } from 'vitest'
import { c, cantidadCorta, decimal, eurC, leer, linea, pctCorto, totales } from './importes'
import { periodoDe, periodoRapido, sumarMeses, ultimoDia } from './periodos'
import { csv, xls, type FilaExport } from './exportar'
import { calcularPrecios } from './precios'

describe('importes (misma regla que el servidor)', () => {
  it('lee lo que escribe una persona', () => {
    const casos: [string, string | null][] = [
      ['1.234,56', '1234.56'],
      ['1,234.56', '1234.56'],
      ['12,5', '12.50'],
      ['1.234', '1234.00'],
      ['0,005', '0.01'],
      ['1.200 €', '1200.00'],
      ['-3,5', '-3.50'],
      ['abc', null],
    ]
    for (const [a, b] of casos) expect(leer(a)).toBe(b)
  })

  it('redondea por línea y las cuotas sobre la base, sin floats', () => {
    expect(linea('2.50', '33.33')).toBe(8333)
    expect(linea('-2.50', '33.33')).toBe(-8333)
    expect(totales([{ cantidad: '2.50', precio: '33.33' }, { cantidad: '1', precio: '1234.56' }], '21', '15')).toEqual({ base: 131789, iva: 27676, irpf: 19768, total: 139697 })
    expect(totales([{ cantidad: '1', precio: '0.1' }, { cantidad: '1', precio: '0.2' }], 0, 0).total).toBe(30)
  })

  it('formatea', () => {
    expect(c('21.00')).toBe(2100)
    expect(decimal(-5)).toBe('-0.05')
    expect(eurC(123456)).toBe('1.234,56 €')
    expect(cantidadCorta('1.50')).toBe('1,5')
    expect(cantidadCorta('2.00')).toBe('2')
    expect(pctCorto('7.50')).toBe('7,5')
  })
})

describe('períodos', () => {
  const hoy = new Date(2026, 2, 14)
  it('mes vista y mes vencido terminan el último día', () => {
    expect(periodoRapido('vista', hoy)).toEqual({ ini: '2026-03-01', fin: '2026-03-31' })
    expect(periodoRapido('vencido', hoy)).toEqual({ ini: '2026-02-01', fin: '2026-02-28' })
    expect(periodoDe('2026-02-01', '2026-02-28', hoy)).toBe('vencido')
    expect(periodoDe(null, null, hoy)).toBe('ninguno')
    expect(periodoDe('2026-01-05', '2026-01-09', hoy)).toBeNull()
  })
  it('suma meses', () => {
    expect(sumarMeses('2026-01', -1)).toBe('2025-12')
    expect(ultimoDia('2024-02')).toBe('2024-02-29')
  })
})

describe('exportar contabilidad', () => {
  const filas: FilaExport[] = [
    { fecha: '2026-03-14', concepto: 'Factura V-2026-001 · Alfa; SL', tipo: 'ingreso', categoria: 'Cliente', ambito_nombre: 'Víctor', project: null, metodo: 'transferencia', legal: true, deducible: false, personal: false, importe: 123456 },
    { fecha: '2026-03-15', concepto: 'Hosting <web>', tipo: 'gasto', categoria: 'Gasto', ambito_nombre: 'Empresa', project: { nombre: 'Web' }, metodo: 'efectivo', legal: false, deducible: true, personal: true, importe: 5000 },
  ]
  it('CSV con BOM, «;» y totales', () => {
    const t = csv(filas)
    expect(t.startsWith('﻿Fecha;Concepto')).toBe(true)
    expect(t).toContain('"Factura V-2026-001 · Alfa; SL"')
    expect(t).toContain('-50,00')
    expect(t).toContain('Beneficio;1.184,56')
  })
  it('XLS escapado', () => {
    const t = xls(filas, 'Contabilidad · Hub · 2026')
    expect(t).toContain('Hosting &lt;web&gt;')
    expect(t).toContain('<Data ss:Type="Number">-50.00</Data>')
  })
})

describe('calculadora de precios', () => {
  it('margen y recomendado', () => {
    const r = calcularPrecios({ lineas: [{ concepto: 'SEO', cantidad: '1', precio: '1000' }], descuentoPct: '10', ivaPct: '21', horas: '20', costeHora: '15', fijos: '0', margenObjetivo: '60' })
    expect(r.base).toBe(90000)
    expect(r.total).toBe(108900)
    expect(r.coste).toBe(30000)
    expect(r.margenPct).toBe(67)
    expect(r.aviso.tono).toBe('ok')
    expect(r.recomendado).toBe(75000)
  })
  it('avisa si se pierde dinero', () => {
    const r = calcularPrecios({ lineas: [{ concepto: 'X', cantidad: '1', precio: '100' }], descuentoPct: '0', ivaPct: '21', horas: '10', costeHora: '15', fijos: '0', margenObjetivo: '60' })
    expect(r.aviso.tono).toBe('error')
  })
})
