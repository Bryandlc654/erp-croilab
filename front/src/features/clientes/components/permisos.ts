import { useAuth } from '../../auth/useAuth'

/* Qué puede hacer la persona con los clientes. Solo para ocultar botones: la
   API lo vuelve a comprobar. Escribir siempre exige además `general.editar`
   («sin esto solo puede mirar, aunque tenga los demás»). */
export function usePermisosClientes() {
  const { can } = useAuth()
  const escribe = can('general.editar')
  return {
    crear: escribe && can('clientes.crear'),
    editar: escribe && can('clientes.editar'),
    borrar: escribe && can('clientes.borrar'),
    portal: escribe && can('clientes.portal'),
    importes: can('ver.importes'),
    credenciales: can('ver.credenciales'),
    tipos: escribe && can('tipos.editar'),
    servicios: escribe && can('servicios.editar'),
    marca: escribe && can('marca.editar'),
    ajustes: can('ver.ajustes'),
    avanzado: can('datos.avanzado'),
    tareas: escribe && can('tareas.crear'),
    finanzas: can('ver.finanzas'),
  }
}
export type PermisosClientes = ReturnType<typeof usePermisosClientes>
