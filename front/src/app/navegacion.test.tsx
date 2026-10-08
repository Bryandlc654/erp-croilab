import { describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { ConfirmProvider } from '../shared/ui/ConfirmDialog'
import { ToastProvider } from '../shared/ui/Toast'
import { AuthContext, type AuthCtx } from '../features/auth/useAuth'
import { clavesNav } from '../features/nav/api'
import type { Nav } from '../features/nav/schemas'
import Sidebar from './layout/Sidebar'
import { itemActivo, moduloDe, modulosNav } from './navegacion'

describe('moduloDe()', () => {
  it.each([
    ['/tareas', 'trabajo'],
    ['/inicio', 'trabajo'],
    ['/notificaciones', 'trabajo'],
    ['/clientes', 'clientes'],
    ['/clientes/tipos', 'clientes'],
    ['/clientes/42', 'clientes'],
    ['/crm', 'crm'],
    ['/crm/contactos/7', 'crm'],
    ['/finanzas', 'finanzas'],
    ['/finanzas/facturas/12', 'finanzas'],
    ['/ajustes', 'ajustes'],
    ['/ajustes/portal/videos', 'ajustes'],
    ['/reuniones', 'reuniones'],
    ['/calendario', 'calendario'],
    ['/chat/general', 'chat'],
    ['/soporte/3', 'soporte'],
    ['/actas', 'actas'],
    ['/asistente', 'asistente'],
    // Lo que nadie reclama cae en Trabajo.
    ['/algo-nuevo', 'trabajo'],
  ])('%s → %s', (path, id) => {
    expect(moduloDe(path).id).toBe(id)
  })

  it('no confunde prefijos: /crmx no es el CRM', () => {
    expect(moduloDe('/crmx').id).toBe('trabajo')
  })

  it('las pantallas «solo» van sin barra lateral', () => {
    expect(moduloDe('/chat').solo).toBe(true)
    expect(moduloDe('/clientes').solo).toBeFalsy()
  })

  it('cada id es único', () => {
    const ids = modulosNav.map((m) => m.id)
    expect(new Set(ids).size).toBe(ids.length)
  })
})

describe('itemActivo()', () => {
  const u = (path: string) => {
    const [pathname, q = ''] = path.split('?')
    return { pathname, params: new URLSearchParams(q) }
  }
  const finanzas = moduloDe('/finanzas')
  const resumen = finanzas.sections[0].items[0]

  it('por defecto: mismo path y mismos parámetros', () => {
    expect(itemActivo(resumen, u('/finanzas'))).toBe(true)
    expect(itemActivo(resumen, u('/finanzas/horas'))).toBe(false)
  })

  it('filtros de clientes por ?f= (en alta por defecto)', () => {
    const [alta, baja] = moduloDe('/clientes').sections[0].items
    expect(itemActivo(alta, u('/clientes'))).toBe(true)
    expect(itemActivo(alta, u('/clientes?f=baja'))).toBe(false)
    expect(itemActivo(baja, u('/clientes?f=baja'))).toBe(true)
  })
})

const NAV: Nav = { marca: 'Croilab', pendientes: 12, no_leidas: 3, clientes: { total: 40, activos: 30, inactivos: 10 } }

function pintar(ruta: string, permisos: string[] = ['admin.total']) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } })
  qc.setQueryData(clavesNav.nav, NAV)
  vi.stubGlobal('fetch', vi.fn(async () => new Response('{}', { status: 500 })))
  const auth: AuthCtx = {
    me: null,
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
    refresh: vi.fn(),
    can: (p) => permisos.includes('admin.total') || permisos.includes(p),
  }
  return render(
    <QueryClientProvider client={qc}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter initialEntries={[ruta]}>
          <Sidebar />
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
}

describe('<Sidebar> según la ruta', () => {
  it('en Tareas: la barra de siempre, con contadores y las secciones de clientes', () => {
    pintar('/tareas?view=mine')
    const aside = screen.getByRole('complementary', { name: 'Croilab ERP' })
    expect(within(aside).getByText('Croilab ERP')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Mis tareas/ })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByRole('link', { name: /Todas las tareas/ })).toHaveTextContent('12')
    expect(screen.getByRole('link', { name: /Notificaciones/ })).toHaveTextContent('3')
    expect(screen.getByRole('button', { name: /Clientes activos/ })).toBeInTheDocument()
  })

  it('en Finanzas: sus secciones y el elemento activo', () => {
    pintar('/finanzas/horas')
    expect(screen.getByText('Finanzas', { selector: 'div' })).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Facturas' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Horas equipo' })).toHaveAttribute('aria-current', 'page')
    expect(screen.queryByRole('button', { name: /Clientes activos/ })).not.toBeInTheDocument()
  })

  it('en Ajustes: las cuatro zonas con las rutas de RUTAS.md', () => {
    pintar('/ajustes/boveda')
    for (const zona of ['Organización', 'Clientes', 'Portal de clientes', 'Sistema']) expect(screen.getByRole('navigation', { name: zona })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Bóveda de credenciales' })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByRole('link', { name: 'Roles y permisos' })).toHaveAttribute('href', '/ajustes/roles')
  })

  it('oculta lo que no se puede ver por permisos', () => {
    pintar('/finanzas', ['ver.finanzas'])
    expect(screen.queryByRole('link', { name: 'Proyectos' })).not.toBeInTheDocument()
    expect(screen.queryByRole('navigation', { name: 'Contabilidad' })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Resumen mensual' })).toBeInTheDocument()
  })

  /* El CRM pinta sus listas de contactos (/v1/crm/listas) con su propio
     componente, que usa confirmaciones y avisos: necesita sus proveedores. */
  function pintarCrm(ruta: string, listas: unknown[]) {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } })
    qc.setQueryData(clavesNav.nav, NAV)
    qc.setQueryData(['crm', 'listas'], { listas })
    vi.stubGlobal('fetch', vi.fn(async () => new Response('{}', { status: 500 })))
    const auth: AuthCtx = { me: null, loading: false, login: vi.fn(), logout: vi.fn(), refresh: vi.fn(), can: () => true }
    return render(
      <QueryClientProvider client={qc}>
        <AuthContext.Provider value={auth}>
          <ToastProvider>
            <ConfirmProvider>
              <MemoryRouter initialEntries={[ruta]}>
                <Sidebar />
              </MemoryRouter>
            </ConfirmProvider>
          </ToastProvider>
        </AuthContext.Provider>
      </QueryClientProvider>,
    )
  }

  // Las listas se cargan aparte (lazy): hay que esperar a que aparezcan.
  it('en el CRM: listas vacías con su aviso', async () => {
    pintarCrm('/crm', [])
    expect(screen.getByText('CRM · Ventas')).toBeInTheDocument()
    expect(await screen.findByText('Sin listas todavía.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Contactos' })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByRole('link', { name: 'Nueva lista' })).toHaveAttribute('href', '/crm/listas?nueva=1')
  })

  it('en el CRM: cada lista con su contador y marcada la que se ve', async () => {
    const lista = (id: number, nombre: string, tipo: string, n: number) => ({ id, nombre, descripcion: '', tipo, condiciones: {}, fecha_creacion: '2026-10-01 10:00:00', fecha_congelado: null, n })
    pintarCrm('/crm/listas/2', [lista(1, 'Restaurantes', 'activa', 3), lista(2, 'Selección', 'estatica', 2)])
    expect(await screen.findByRole('link', { name: /Restaurantes/ })).toHaveTextContent('3')
    expect(screen.queryByText('Sin listas todavía.')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Selección/ })).toHaveAttribute('aria-current', 'page')
  })
})
