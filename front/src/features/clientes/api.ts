import { keepPreviousData, useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { api, conQuery } from '../../shared/api/client'
import { siguienteOffset } from '../../shared/schemas'
import { ClienteRespuesta, ClientesPagina } from './schemas'

export const clavesClientes = {
  todo: ['clientes'] as const,
  busqueda: (q: string) => ['clientes', 'busqueda', q] as const,
  seccion: (activo: boolean) => ['clientes', 'seccion', activo] as const,
  detalle: (id: number) => ['clientes', 'detalle', id] as const,
}

const LIMITE_BUSQUEDA = 20
const LIMITE_SECCION = 50

/* Buscador «Ir a un cliente»: el servidor filtra por prefijo del nombre.
   Mientras llega la respuesta nueva se siguen viendo los resultados anteriores. */
export function useBuscarClientes(q: string) {
  const texto = q.trim()
  return useQuery({
    queryKey: clavesClientes.busqueda(texto),
    queryFn: ({ signal }) => api(conQuery('/api/v1/clientes', { q: texto, limit: LIMITE_BUSQUEDA }), { schema: ClientesPagina, signal }),
    placeholderData: keepPreviousData,
  })
}

/* Una sección de la barra lateral (activos / no activos), con sus listas.
   Solo se pide cuando la sección está desplegada, de 50 en 50. */
export function useClientesSeccion(activo: boolean, enabled: boolean) {
  return useInfiniteQuery({
    queryKey: clavesClientes.seccion(activo),
    queryFn: ({ pageParam, signal }) =>
      api(conQuery('/api/v1/clientes', { activo, con_listas: 1, limit: LIMITE_SECCION, offset: pageParam }), { schema: ClientesPagina, signal }),
    initialPageParam: 0,
    getNextPageParam: siguienteOffset,
    enabled,
  })
}

/* Un cliente con sus listas. id 0 = ninguno (no se pide nada). */
export function useCliente(id: number) {
  return useQuery({
    queryKey: clavesClientes.detalle(id),
    queryFn: ({ signal }) => api(`/api/v1/clientes/${id}`, { schema: ClienteRespuesta, signal }),
    enabled: id > 0,
  })
}
