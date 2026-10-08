import { describe, expect, it } from 'vitest'
import { rutaDesdeLegado } from './rutas'

describe('rutaDesdeLegado()', () => {
  it.each([
    ['task.php?id=42', '/tareas/42'],
    ['task.php?id=42#c17', '/tareas/42#c17'],
    ['/admin/task.php?id=7#chk', '/tareas/7#chk'],
    ['https://erp.croilab.com/admin/task.php?id=9', '/tareas/9'],
    ['facturas.php?v=15', '/finanzas/facturas/15'],
    ['facturas.php?edit=16', '/finanzas/facturas/16'],
    ['chat.php?room=4', '/chat/4'],
    ['chat.php?dm=9', '/chat?dm=9'],
    ['calendar.php', '/calendario'],
    ['client.php?id=3', '/clientes/3'],
    ['support.php?t=8', '/soporte/8'],
    ['crm.php?open=21', '/crm/contactos/21'],
    ['perfil.php?id=5', '/perfil/5'],
    ['reuniones.php', '/reuniones'],
    ['reuniones.php?tab=pasadas', '/reuniones'],
  ])('%s → %s', (legado, ruta) => {
    expect(rutaDesdeLegado(legado)).toBe(ruta)
  })

  it('lo que ya es una ruta del front se deja tal cual', () => {
    expect(rutaDesdeLegado('/tareas/42#c3')).toBe('/tareas/42#c3')
    expect(rutaDesdeLegado('/finanzas/facturas/2')).toBe('/finanzas/facturas/2')
  })

  it('lo desconocido o sin id válido va a /inicio', () => {
    expect(rutaDesdeLegado('desconocido.php?id=1')).toBe('/inicio')
    expect(rutaDesdeLegado('task.php?id=abc')).toBe('/inicio')
    expect(rutaDesdeLegado('task.php')).toBe('/inicio')
    expect(rutaDesdeLegado('')).toBe('/inicio')
    expect(rutaDesdeLegado('javascript:alert(1)')).toBe('/inicio')
    expect(rutaDesdeLegado('//evil.example.com/x')).toBe('/inicio')
  })
})
