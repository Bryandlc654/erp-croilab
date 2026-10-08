import { lazy, Suspense } from 'react'

/* Las listas del CRM en la barra lateral, cargadas solo al entrar en el CRM:
   así su código (y el de su API) no pesa en el JS principal. */
const SidebarListasCrm = lazy(() => import('../../features/crm/components/SidebarListas'))

export default function ListasCrmDiferidas() {
  return (
    <Suspense fallback={null}>
      <SidebarListasCrm />
    </Suspense>
  )
}
