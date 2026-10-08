import { Link, useLocation, useNavigate } from 'react-router-dom'
import { Layers, List, Pencil, Plus, Snowflake, Trash2 } from 'lucide-react'
import { MenuItem, MenuLabel, MenuPanel, MenuSeparator } from '../../../shared/ui/Menu'
import SortableList, { RowGrip } from '../../../shared/ui/SortableList'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useContextMenu } from '../../../shared/ui/useContextMenu'
import { useToast } from '../../../shared/ui/useToast'
import { useAuth } from '../../auth/useAuth'
import { mensajeError, useAccionListas, useListas } from '../api'
import type { Lista } from '../schemas'

/* Sección «Listas» de la barra lateral del CRM: las listas en su orden (se
   reordenan arrastrando por el asa), con su contador, «+» para crear una y
   menú con clic derecho (renombrar, congelar, eliminar). */
export default function SidebarListas() {
  const { can } = useAuth()
  const ve = can('ver.crm')
  const { data: listas = [] } = useListas(ve)
  const acc = useAccionListas()
  const { pathname } = useLocation()
  const navigate = useNavigate()
  const { confirm, prompt } = useConfirm()
  const { aviso } = useToast()
  const cm = useContextMenu<Lista>()
  const edita = can('general.editar') && can('crm.editar')
  const crea = can('general.editar') && can('crm.crear')
  const borra = can('general.editar') && can('crm.borrar')
  const fallo = (e: unknown) => aviso(mensajeError(e), { tipo: 'error' })
  if (!ve) return null
  const l = cm.dato

  return (
    <div>
      <div className="flex items-center gap-2 px-5 pt-[22px] pb-[9px]">
        <span className="min-w-0 flex-1 truncate text-[10.5px] font-bold tracking-[.7px] text-label uppercase">Listas</span>
        {crea && (
          <Link to="/crm/listas?nueva=1" className="flex size-5 items-center justify-center rounded-md text-label hover:bg-soft hover:text-ink" aria-label="Nueva lista" title="Nueva lista">
            <Plus className="size-3.5" strokeWidth={2.2} />
          </Link>
        )}
      </div>
      <nav className="px-2.5 py-1" aria-label="Listas">
        {listas.length === 0 && <p className="px-3 py-1 text-[12.5px] text-muted">Sin listas todavía.</p>}
        <SortableList
          items={listas}
          getId={(x) => x.id}
          disabled={!edita}
          onReorder={(ids) => acc.orden.mutate(ids.map(Number), { onError: fallo })}
          className="flex flex-col gap-0.5"
          renderItem={(x, { handleProps }) => {
            const on = pathname === `/crm/listas/${x.id}`
            const Icono = x.tipo === 'estatica' ? Layers : List
            return (
              <div className="flex items-center" onContextMenu={(e) => cm.onContextMenu(e, x)}>
                {edita && <RowGrip {...handleProps} className="-ml-1" aria-label={`Mover ${x.nombre}`} />}
                <Link
                  to={`/crm/listas/${x.id}`}
                  aria-current={on ? 'page' : undefined}
                  className={`flex min-w-0 flex-1 items-center gap-2.5 rounded-[11px] px-[11px] py-2 text-[13.5px] transition-[background-color,transform] hover:translate-x-0.5 hover:bg-soft ${
                    on ? 'bg-soft font-semibold text-ink' : 'font-medium text-[#5a5f68] dark:text-zinc-400'
                  }`}
                >
                  <Icono className="size-4 shrink-0 text-label" strokeWidth={1.8} />
                  <span className="min-w-0 flex-1 truncate">{x.nombre}</span>
                  <span className="rounded-full bg-[#f0f1f4] px-[7px] text-[10px] font-bold text-[#5c626c] dark:bg-soft dark:text-muted">{x.n}</span>
                </Link>
              </div>
            )
          }}
        />
      </nav>
      <MenuPanel {...cm.panel} label="Acciones de la lista">
        {l && (
          <>
            <MenuLabel>{l.nombre}</MenuLabel>
            <MenuItem onSelect={() => navigate(`/crm/listas/${l.id}`)}>Abrir lista</MenuItem>
            {edita && (
              <MenuItem
                icon={<Pencil />}
                onSelect={async () => {
                  const nombre = await prompt({ title: 'Renombrar lista', value: l.nombre })
                  if (nombre && nombre !== l.nombre) acc.renombrar.mutate({ id: l.id, nombre }, { onError: fallo })
                }}
              >
                Renombrar
              </MenuItem>
            )}
            {edita && l.tipo === 'activa' && (
              <MenuItem
                icon={<Snowflake />}
                onSelect={async () => {
                  if (await confirm({ title: '¿Congelar esta lista?', message: 'Se guardarán los contactos actuales y dejará de actualizarse.', okLabel: 'Congelar' }))
                    acc.congelar.mutate(l.id, { onSuccess: () => aviso('Lista congelada'), onError: fallo })
                }}
              >
                Congelar
              </MenuItem>
            )}
            {borra && (
              <>
                <MenuSeparator />
                <MenuItem
                  icon={<Trash2 />}
                  danger
                  onSelect={async () => {
                    if (!(await confirm({ title: '¿Eliminar la lista?', message: 'Los contactos no se borran: solo la lista.', danger: true }))) return
                    acc.borrar.mutate(l.id, {
                      onSuccess: () => {
                        aviso('Lista eliminada')
                        if (pathname === `/crm/listas/${l.id}`) navigate('/crm/listas', { replace: true })
                      },
                      onError: fallo,
                    })
                  }}
                >
                  Eliminar
                </MenuItem>
              </>
            )}
          </>
        )}
      </MenuPanel>
    </div>
  )
}
