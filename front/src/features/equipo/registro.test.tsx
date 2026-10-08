import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import RegistroPage from './pages/RegistroPage'

type Llamada = { url: string; method: string; body: unknown; csrf: string | null }

/* fetch de mentira: responde según «MÉTODO ruta» y apunta cada llamada. */
function api(rutas: Record<string, { status?: number; cuerpo: object }>) {
  const llamadas: Llamada[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init: RequestInit = {}) => {
      const method = init.method ?? 'GET'
      const cab = (init.headers ?? {}) as Record<string, string>
      llamadas.push({ url, method, body: init.body ? JSON.parse(String(init.body)) : undefined, csrf: cab['X-CSRF-Token'] ?? null })
      const r = rutas[`${method} ${url.split('?')[0]}`] ?? { status: 404, cuerpo: { ok: false, msg: 'Este enlace ya no vale.', error: 'no_encontrado' } }
      return new Response(JSON.stringify(r.cuerpo), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
    }),
  )
  return llamadas
}

const TOKEN = 'b'.repeat(64)
const INFO = { ok: true, rol_nombre: 'Editor', email: null, marca: { nombre: 'Mi Agencia', inicial: 'M', logo: null, color: '#123456' }, horas: 48 }

function montar(url: string) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[url]}>
        <Routes>
          <Route path="/registro" element={<RegistroPage />} />
          <Route path="/login" element={<p>login</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

afterEach(() => vi.unstubAllGlobals())

describe('Registro por enlace', () => {
  it('enseña el rol y la marca, comprueba las contraseñas y crea la cuenta con CSRF', async () => {
    const llamadas = api({
      'GET /api/v1/registro': { cuerpo: INFO },
      'GET /api/v1/auth/csrf': { cuerpo: { ok: true, csrf: 'tok' } },
      'POST /api/v1/registro': { status: 201, cuerpo: { ok: true, username: 'nueva' } },
    })
    montar(`/registro?t=${TOKEN}`)
    expect(await screen.findByText('Editor')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Nombre de usuario'), 'nueva')
    await userEvent.type(screen.getByLabelText('Contraseña'), 'Secreta-1')
    await userEvent.type(screen.getByLabelText('Repite la contraseña'), 'Secreta-2')
    expect(screen.getByText('Las dos contraseñas no coinciden')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /Crear mi cuenta/ }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Las dos contraseñas no coinciden.')
    expect(llamadas.some((l) => l.method === 'POST')).toBe(false)

    await userEvent.clear(screen.getByLabelText('Repite la contraseña'))
    await userEvent.type(screen.getByLabelText('Repite la contraseña'), 'Secreta-1')
    await userEvent.click(screen.getByRole('button', { name: /Crear mi cuenta/ }))
    expect(await screen.findByText('Cuenta creada')).toBeInTheDocument()
    const post = llamadas.find((l) => l.method === 'POST')
    expect(post?.csrf).toBe('tok')
    expect(post?.body).toEqual({ token: TOKEN, username: 'nueva', email: '', password: 'Secreta-1' })
  })

  it('un enlace caducado o usado lo dice sin enseñar el formulario', async () => {
    api({})
    montar(`/registro?t=${TOKEN}`)
    expect(await screen.findByText('Este enlace ya no vale')).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('button', { name: /Crear mi cuenta/ })).not.toBeInTheDocument())
  })
})
