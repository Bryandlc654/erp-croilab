import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import { api, ApiError, AUTH_EXPIRED, conQuery, CSRF_EXPIRED, guardarCsrf } from './client'

const Ok = z.object({ valor: z.number() })

function responder(cuerpo: string, status = 200, tipo = 'application/json') {
  // Una Response nueva por llamada: el cuerpo solo se puede leer una vez.
  const fetchMock = vi.fn().mockImplementation(async () => new Response(cuerpo, { status, headers: { 'Content-Type': tipo } }))
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

async function fallo(p: Promise<unknown>): Promise<ApiError> {
  try {
    await p
  } catch (e) {
    expect(e).toBeInstanceOf(ApiError)
    return e as ApiError
  }
  throw new Error('Se esperaba un ApiError')
}

describe('api()', () => {
  beforeEach(() => guardarCsrf(null))
  afterEach(() => vi.unstubAllGlobals())

  it('devuelve los datos validados por el schema', async () => {
    responder(JSON.stringify({ ok: true, valor: 3 }))
    await expect(api('/api/v1/x', { schema: Ok })).resolves.toEqual({ valor: 3 })
  })

  it('una respuesta que no es JSON se convierte en ApiError con mensaje genérico', async () => {
    responder('<html><body>Fatal error</body></html>', 500, 'text/html')
    const e = await fallo(api('/api/v1/x', { schema: Ok }))
    expect(e.status).toBe(500)
    expect(e.message).toBe('No se ha podido contactar con el servidor (500).')
  })

  it('un 200 que no es JSON también es un fallo', async () => {
    responder('Página de mantenimiento', 200, 'text/plain')
    const e = await fallo(api('/api/v1/x', { schema: Ok }))
    expect(e.status).toBe(200)
  })

  it('sin conexión lanza ApiError con status 0', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
    const e = await fallo(api('/api/v1/x', { schema: Ok }))
    expect(e.status).toBe(0)
    expect(e.message).toBe('No se puede conectar con el servidor')
  })

  it('un 401 avisa de que la sesión ha caducado', async () => {
    const auth = vi.fn()
    const csrf = vi.fn()
    window.addEventListener(AUTH_EXPIRED, auth)
    window.addEventListener(CSRF_EXPIRED, csrf)
    responder(JSON.stringify({ ok: false, msg: 'Tu sesión ha caducado.', error: 'sesion' }), 401)
    const e = await fallo(api('/api/v1/me', { schema: Ok }))
    window.removeEventListener(AUTH_EXPIRED, auth)
    window.removeEventListener(CSRF_EXPIRED, csrf)
    expect(auth).toHaveBeenCalledTimes(1)
    expect(csrf).not.toHaveBeenCalled()
    expect(e.status).toBe(401)
    expect(e.codigo).toBe('sesion')
  })

  it('un 419 pide un token CSRF nuevo', async () => {
    const auth = vi.fn()
    const csrf = vi.fn()
    window.addEventListener(AUTH_EXPIRED, auth)
    window.addEventListener(CSRF_EXPIRED, csrf)
    responder(JSON.stringify({ ok: false, msg: 'Token caducado.', error: 'csrf' }), 419)
    const e = await fallo(api('/api/v1/tareas/1', { method: 'PATCH', body: {}, schema: Ok }))
    window.removeEventListener(AUTH_EXPIRED, auth)
    window.removeEventListener(CSRF_EXPIRED, csrf)
    expect(csrf).toHaveBeenCalledTimes(1)
    expect(auth).not.toHaveBeenCalled()
    expect(e.status).toBe(419)
  })

  it('ok:false lanza ApiError con el mensaje del servidor', async () => {
    responder(JSON.stringify({ ok: false, msg: 'El título no puede estar vacío.', error: 'validacion', campo: 'titulo' }), 422)
    const e = await fallo(api('/api/v1/tareas/1', { method: 'PATCH', body: { titulo: '' }, schema: Ok }))
    expect(e.status).toBe(422)
    expect(e.message).toBe('El título no puede estar vacío.')
    expect(e.codigo).toBe('validacion')
  })

  it('ok:false con status 200 también es un error', async () => {
    responder(JSON.stringify({ ok: false, msg: 'No se ha podido guardar.' }))
    const e = await fallo(api('/api/v1/x', { schema: Ok }))
    expect(e.message).toBe('No se ha podido guardar.')
  })

  it('si la respuesta no cumple el contrato lanza ApiError claro en vez de devolver datos raros', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const Lista = z.object({ items: z.array(z.object({ estado: z.enum(['pendiente', 'completada']) })) })
    responder(JSON.stringify({ ok: true, items: [{ estado: 'borrador' }] }))
    const e = await fallo(api('/api/v1/tareas', { schema: Lista }))
    expect(e.codigo).toBe('contrato')
    expect(e.message).toContain('GET /api/v1/tareas')
    expect(e.message).toContain('items.0.estado')
  })

  it('manda el token CSRF solo en los métodos que cambian datos', async () => {
    guardarCsrf('tok-123')
    const f = responder(JSON.stringify({ ok: true, valor: 1 }))
    await api('/api/v1/x', { schema: Ok })
    await api('/api/v1/tareas/5', { method: 'PATCH', body: { prioridad: 4 }, schema: Ok })
    const [, get] = f.mock.calls[0] as [string, RequestInit]
    const [, patch] = f.mock.calls[1] as [string, RequestInit]
    expect((get.headers as Record<string, string>)['X-CSRF-Token']).toBeUndefined()
    expect((patch.headers as Record<string, string>)['X-CSRF-Token']).toBe('tok-123')
    expect(patch.method).toBe('PATCH')
    expect(patch.credentials).toBe('include')
    expect(patch.body).toBe(JSON.stringify({ prioridad: 4 }))
  })
})

describe('conQuery()', () => {
  it('omite los parámetros vacíos y pasa los booleanos a 1/0', () => {
    expect(conQuery('/api/v1/clientes', { q: '', activo: false, con_listas: 1, limit: 50, offset: undefined })).toBe('/api/v1/clientes?activo=0&con_listas=1&limit=50')
    expect(conQuery('/api/v1/clientes', {})).toBe('/api/v1/clientes')
  })
})
