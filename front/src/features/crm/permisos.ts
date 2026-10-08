import { useAuth } from '../auth/useAuth'

/* Qué puede hacer la persona en el CRM (03-crm.md §2.3). Solo para ocultar
   botones: la API lo vuelve a comprobar. Escribir exige además `general.editar`. */
export function usePermisosCrm() {
  const { can, me } = useAuth()
  const escribe = can('general.editar')
  return {
    yo: me?.id ?? 0,
    crear: escribe && can('crm.crear'),
    editar: escribe && can('crm.editar'),
    borrar: escribe && can('crm.borrar'),
    convertir: escribe && can('crm.convertir') && can('ver.clientes') && can('clientes.crear'),
    dueno: can('admin.total'),
    clientes: can('ver.clientes'),
    facturar: can('finanzas.emitir'),
    finanzas: can('ver.finanzas'),
    agenda: can('ver.agenda'),
    papelera: can('papelera.restaurar'),
  }
}
export type PermisosCrm = ReturnType<typeof usePermisosCrm>
