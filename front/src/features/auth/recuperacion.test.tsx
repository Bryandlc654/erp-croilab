import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import Recuperar from './Recuperar'
import Restablecer from './Restablecer'

type Llamada = { url: string; method: string; body: unknown; csrf: string | null }

/* fetch de mentira: responde según la ruta y apunta cada llamada. */
function api(rutas: Record<string, { status?: number; cuerpo: object }>) {
  const llamadas: Llamada[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init: RequestInit = {}) => {
      const method = init.method ?? 'GET'
      const cab = (init.headers ?? {}) as Record<string, string>
      llamadas.push({ url, method, body: init.body ? JSON.parse(String(init.body)) : undefined, csrf: cab['X-CSRF-Token'] ?? null })
      const clave = `${method} ${url.split('?')[0]}`
      const r = rutas[clave] ?? { status: 404, cuerpo: { ok: false, msg: 'Esa ruta no existe.' } }
      return new Response(JSON.stringify(r.cuerpo), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
    }),
  )
  return llamadas
}

const CSRF = { 'GET /api/v1/auth/csrf': { cuerpo: { ok: true, csrf: 'tok-csrf' } } }
const TOKEN = 'a'.repeat(64)

/* Muestra la ruta y el aviso con el que se llega al login. */
function LoginFalso() {
  const loc = useLocation()
  return <p>login: {(loc.state as { aviso?: string } | null)?.aviso}</p>
}

function montar(inicial: string) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[inicial]}>
        <Routes>
          <Route path="/recuperar" element={<Recuperar />} />
          <Route path="/restablecer" element={<Restablecer />} />
          <Route path="/login" element={<LoginFalso />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

afterEach(() => vi.unstubAllGlobals())

describe('Recuperar', () => {
  it('pide el enlace con CSRF y enseña el mensaje del servidor', async () => {
    const llamadas = api({ ...CSRF, 'POST /api/v1/auth/recuperar': { cuerpo: { ok: true, msg: 'Si hay una cuenta, te hemos enviado un enlace.' } } })
    montar('/recuperar')
    await userEvent.type(screen.getByLabelText('Usuario o correo'), ' ana@ejemplo.com ')
    await userEvent.click(screen.getByRole('button', { name: /Enviar enlace/ }))

    expect(await screen.findByText('Si hay una cuenta, te hemos enviado un enlace.')).toBeInTheDocument()
    const post = llamadas.find((l) => l.method === 'POST')!
    expect(post.body).toEqual({ identificador: 'ana@ejemplo.com' })
    expect(post.csrf).toBe('tok-csrf')
  })

  it('enseña el freno de intentos', async () => {
    api({ ...CSRF, 'POST /api/v1/auth/recuperar': { status: 429, cuerpo: { ok: false, msg: 'Demasiados intentos. Espera un minuto.' } } })
    montar('/recuperar')
    await userEvent.type(screen.getByLabelText('Usuario o correo'), 'ana')
    await userEvent.click(screen.getByRole('button', { name: /Enviar enlace/ }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Demasiados intentos')
  })
})

describe('Restablecer', () => {
  const VALIDO = { 'GET /api/v1/auth/restablecer': { cuerpo: { ok: true, username: 'ana' } } }

  it('sin token o con un enlace caducado ofrece pedir otro', async () => {
    api({ 'GET /api/v1/auth/restablecer': { status: 404, cuerpo: { ok: false, msg: 'El enlace no es válido.' } } })
    montar(`/restablecer?token=${TOKEN}`)
    expect(await screen.findByRole('heading', { name: 'Enlace no válido' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Pedir un enlace nuevo' })).toHaveAttribute('href', '/recuperar')
  })

  it('comprueba que las dos contraseñas coinciden antes de enviar', async () => {
    const llamadas = api({ ...VALIDO })
    montar(`/restablecer?token=${TOKEN}`)
    expect(await screen.findByText('ana')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Contraseña nueva'), 'Nueva-clave-1')
    await userEvent.type(screen.getByLabelText('Repite la contraseña'), 'Otra-clave-2')
    await userEvent.click(screen.getByRole('button', { name: /Guardar contraseña/ }))
    expect(screen.getByRole('alert')).toHaveTextContent('no coinciden')
    expect(llamadas.some((l) => l.method === 'POST')).toBe(false)
  })

  it('guarda la contraseña y vuelve al login con un aviso', async () => {
    const llamadas = api({ ...CSRF, ...VALIDO, 'POST /api/v1/auth/restablecer': { cuerpo: { ok: true } } })
    montar(`/restablecer?token=${TOKEN}`)
    await screen.findByText('ana')
    await userEvent.type(screen.getByLabelText('Contraseña nueva'), 'Nueva-clave-1')
    await userEvent.type(screen.getByLabelText('Repite la contraseña'), 'Nueva-clave-1')
    await userEvent.click(screen.getByRole('button', { name: /Guardar contraseña/ }))

    expect(await screen.findByText(/login: Contraseña cambiada/)).toBeInTheDocument()
    const post = llamadas.find((l) => l.method === 'POST' && l.url.includes('restablecer'))!
    expect(post.body).toEqual({ token: TOKEN, password: 'Nueva-clave-1' })
    expect(post.csrf).toBe('tok-csrf')
  })

  it('si el servidor rechaza la contraseña, el error se ve y se puede reintentar', async () => {
    api({ ...CSRF, ...VALIDO, 'POST /api/v1/auth/restablecer': { status: 422, cuerpo: { ok: false, msg: 'Esa contraseña ya la usaste hace poco.', error: 'validacion' } } })
    montar(`/restablecer?token=${TOKEN}`)
    await screen.findByText('ana')
    await userEvent.type(screen.getByLabelText('Contraseña nueva'), 'Repetida-1')
    await userEvent.type(screen.getByLabelText('Repite la contraseña'), 'Repetida-1')
    await userEvent.click(screen.getByRole('button', { name: /Guardar contraseña/ }))
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('ya la usaste'))
    expect(screen.getByLabelText('Contraseña nueva')).toBeEnabled()
  })
})
