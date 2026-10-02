import { describe, expect, it } from 'vitest'
import { reintentar } from './queryClient'
import { ApiError } from '../shared/api/client'

describe('reintentar()', () => {
  it('no reintenta los 4xx ni los contratos rotos', () => {
    expect(reintentar(0, new ApiError(401, 'Sin sesión'))).toBe(false)
    expect(reintentar(0, new ApiError(404, 'No existe'))).toBe(false)
    expect(reintentar(0, new ApiError(200, 'Formato inesperado', 'contrato'))).toBe(false)
  })

  it('reintenta una vez los fallos de red y 5xx', () => {
    expect(reintentar(0, new ApiError(0, 'Sin conexión', 'red'))).toBe(true)
    expect(reintentar(0, new ApiError(503, 'Migraciones pendientes'))).toBe(true)
    expect(reintentar(1, new ApiError(503, 'Migraciones pendientes'))).toBe(false)
  })
})
