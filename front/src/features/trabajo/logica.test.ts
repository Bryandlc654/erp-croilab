import { describe, expect, it } from 'vitest'
import { agruparPorFecha, fechaExacta, grupoFecha, haceActa, horaAviso, porDia, pospuestoHasta, saludo, subtituloPopup, tarjetasPulso, tituloPopup } from './logica'
import { aGruposPaleta } from './paleta'

const AHORA = new Date(2026, 9, 8, 17, 30) // jueves 8 de octubre de 2026

describe('lógica de Trabajo', () => {
  it('saluda según la hora', () => {
    expect(saludo(9)).toBe('Buenos días')
    expect(saludo(12)).toBe('Buenas tardes')
    expect(saludo(20)).toBe('Buenas noches')
  })

  it('agrupa los avisos por día y por mes', () => {
    expect(grupoFecha('2026-10-08 09:00:00', AHORA)).toBe('Hoy')
    expect(grupoFecha('2026-10-07 23:59:00', AHORA)).toBe('Ayer')
    expect(grupoFecha('2026-10-03 10:00:00', AHORA)).toBe('Últimos 7 días')
    expect(grupoFecha('2026-07-20 10:00:00', AHORA)).toBe('Julio')
    expect(grupoFecha('2025-06-20 10:00:00', AHORA)).toBe('Junio 2025')
    const g = agruparPorFecha([{ created_at: '2026-10-08 10:00:00' }, { created_at: '2026-10-08 09:00:00' }, { created_at: '2026-08-01 09:00:00' }], AHORA)
    expect(g.map((x) => [x.titulo, x.items.length])).toEqual([
      ['Hoy', 2],
      ['Agosto', 1],
    ])
  })

  it('hora de la fila y de lo pospuesto', () => {
    expect(horaAviso('2026-10-08 09:05:00', AHORA)).toBe('09:05')
    expect(horaAviso('2026-10-07 09:05:00', AHORA)).toBe('Ayer')
    expect(horaAviso('2026-09-29 09:05:00', AHORA)).toBe('29/09/26')
    expect(pospuestoHasta('2026-10-09 09:00:00')).toBe('09/10 09:00')
    expect(pospuestoHasta(null)).toBe('')
  })

  it('fechas relativas de las actas', () => {
    expect(haceActa('2026-10-08 17:29:30', AHORA)).toBe('hace un momento')
    expect(haceActa('2026-10-08 17:00:00', AHORA)).toBe('hace 30 minutos')
    expect(haceActa('2026-10-08 15:00:00', AHORA)).toBe('hace 2 horas')
    expect(haceActa('2026-10-08 08:00:00', AHORA)).toBe('hoy')
    expect(haceActa('2026-10-07 08:00:00', AHORA)).toBe('ayer')
    expect(haceActa('2026-10-04 08:00:00', AHORA)).toBe('hace 4 días')
    expect(haceActa('2026-09-24 08:00:00', AHORA)).toBe('hace 2 semanas')
    expect(haceActa('2026-08-01 08:00:00', AHORA)).toBe('hace 2 meses')
    expect(haceActa('2024-08-01 08:00:00', AHORA)).toBe('hace 2 años')
    expect(fechaExacta('2026-09-23 18:40:00')).toBe('23 sep 2026, 18:40')
  })

  it('reparte el calendario por días', () => {
    const m = porDia([{ dia: '2026-10-09', t: 1 }, { dia: '2026-10-09', t: 2 }, { dia: '2026-10-10', t: 3 }])
    expect(m.get('2026-10-09')?.length).toBe(2)
    expect(m.get('2026-10-11')).toBeUndefined()
  })

  it('textos de los pop-ups de avisos', () => {
    expect(tituloPopup({ actor: 'laura', titulo: 'te ha asignado esta tarea' })).toBe('laura · te ha asignado esta tarea')
    expect(tituloPopup({ actor: '', titulo: 'Factura vencida F-1' })).toBe('Factura vencida F-1')
    expect(subtituloPopup({ tarea: 'Web', cuerpo: 'Alfa' }, 2)).toBe('Web · +2 más')
    expect(subtituloPopup({ tarea: '', cuerpo: 'Alfa' }, 0)).toBe('Alfa')
  })

  it('la búsqueda se convierte en grupos de la paleta con rutas del front', () => {
    const g = aGruposPaleta({ q: 'we', n: 2, grupos: [{ g: 'Tareas', r: [{ t: 'Web', s: 'Alfa', u: '/tareas/3', i: 'check' }, { t: 'Vieja', s: '', u: 'task.php?id=4', i: 'check' }] }] })
    expect(g[0].titulo).toBe('Tareas')
    expect(g[0].resultados.map((r) => r.href)).toEqual(['/tareas/3', '/tareas/4'])
    expect(g[0].resultados[1].subtitulo).toBeUndefined()
  })
})

describe('tarjetasPulso', () => {
  const nada = { tickets: null, crm_hoy: null, cobros: null, chat_no_leidos: null }

  it('sin permiso de ningún módulo no hay tarjetas', () => {
    expect(tarjetasPulso(nada)).toEqual([])
  })

  it('cada módulo con su cifra, su enlace y su alerta', () => {
    const t = tarjetasPulso({
      tickets: { abiertos: 3, sin_asignar: 2, mios: 1 },
      crm_hoy: { total: 4, atrasados: 1 },
      cobros: { pendiente: { n: 2, total: 24200 }, vencidas: { n: 1, total: 12100 } },
      chat_no_leidos: 5,
    })
    expect(t.map((x) => [x.id, x.valor, x.to, x.alerta])).toEqual([
      ['tickets', '3', '/soporte', true],
      ['crm', '4', '/crm/reporting', true],
      ['cobro', '242,00 €', '/finanzas/clientes', false],
      ['vencidas', '1', '/finanzas/clientes', true],
      ['chat', '5', '/chat', false],
    ])
    expect(t[0].sub).toBe('2 sin asignar · 1 tuyo')
    expect(t[1].sub).toBe('1 atrasado')
    expect(t[2].sub).toBe('2 facturas')
    expect(t[3].sub).toBe('121,00 €')
  })

  it('sin ver.importes cuenta facturas pero no enseña euros', () => {
    const t = tarjetasPulso({ ...nada, cobros: { pendiente: { n: 3, total: null }, vencidas: { n: 0, total: null } } })
    expect(t.map((x) => [x.valor, x.sub, x.alerta])).toEqual([
      ['3', '3 facturas', false],
      ['0', 'Ninguna vencida', false],
    ])
  })

  it('todo al día no alerta', () => {
    const t = tarjetasPulso({ tickets: { abiertos: 0, sin_asignar: 0, mios: 0 }, crm_hoy: { total: 0, atrasados: 0 }, cobros: null, chat_no_leidos: 0 })
    expect(t.map((x) => x.sub)).toEqual(['Soporte al día', 'Sin seguimientos para hoy', 'Chat al día'])
    expect(t.some((x) => x.alerta)).toBe(false)
  })
})

