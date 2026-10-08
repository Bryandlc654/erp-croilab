import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Copy, Eye, EyeOff, FolderOpen, Inbox, List, Pencil, Plus, Trash2 } from 'lucide-react'
import Menu, { MenuItem, MenuLabel, MenuPanel, MenuSeparator } from '../../../shared/ui/Menu'
import SortableList from '../../../shared/ui/SortableList'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useAccionesListas } from '../api'
import type { ListaTareas } from '../schemas'

type Permisos = { crear: boolean; editar: boolean; borrar: boolean }

/* Pestañas de las listas de un cliente (.tl-tab): subrayadas, arrastrables y
   con su menú (clic derecho o ⋯): abrir, renombrar, visible en el portal,
   clonar y borrar. «＋ Añadir» crea una lista o la de informes. */
export default function ListasCliente({ cli, listas, actual, fe, permisos }: { cli: number; listas: ListaTareas[]; actual: number | null; fe: string; permisos: Permisos }) {
  const navigate = useNavigate()
  const acciones = useAccionesListas()
  const { confirm, prompt } = useConfirm()
  const [menu, setMenu] = useState<{ l: ListaTareas; x: number; y: number } | null>(null)
  const tieneInforme = listas.some((l) => l.tipo === 'informe')
  const ir = (id: number) => navigate(`/tareas?view=cliente&cli=${cli}&list=${id}${fe ? `&fe=${encodeURIComponent(fe)}` : ''}`)

  async function nueva(tipo: 'tareas' | 'informe') {
    const nombre = await prompt({ title: tipo === 'informe' ? 'Nueva lista de informes' : 'Nueva lista', value: tipo === 'informe' ? 'INFORMES CLIENTE' : '', placeholder: 'Nombre…', okLabel: 'Crear' })
    if (nombre === null) return
    const r = await acciones.crear.mutateAsync({ client_id: cli, nombre, tipo })
    if (r.id) ir(r.id)
  }

  async function renombrar(l: ListaTareas) {
    const nombre = await prompt({ title: 'Renombrar lista', value: l.nombre, okLabel: 'Guardar' })
    if (nombre !== null && nombre.trim() && nombre.trim() !== l.nombre) acciones.cambiar.mutate({ id: l.id, nombre: nombre.trim() })
  }

  async function borrar(l: ListaTareas) {
    const ok = await confirm({
      title: `¿Borrar «${l.nombre}»?`,
      message: l.tipo === 'informe' ? 'La lista de informes y sus entradas van a la papelera; podrás deshacerlo.' : 'La lista y sus tareas van a la papelera; podrás deshacerlo.',
      danger: true,
      okLabel: 'Borrar',
    })
    if (!ok) return
    await acciones.borrar.mutateAsync({ id: l.id, nombre: l.nombre })
    if (l.id === actual) navigate(`/tareas?view=cliente&cli=${cli}`)
  }

  const TAB = 'inline-flex items-center gap-[7px] whitespace-nowrap border-b-2 px-3 py-2 text-[13px] font-semibold transition-colors'

  return (
    <div className="mb-5 flex items-end gap-1 overflow-x-auto border-b border-line [scrollbar-width:none]">
      <SortableList
        as="div"
        axis="x"
        handle={false}
        disabled={!permisos.editar}
        aria-label="Listas del cliente"
        className="gap-1"
        items={listas}
        getId={(l) => l.id}
        onReorder={(ids) => acciones.ordenar.mutate({ client_id: cli, ids: ids.map(Number) })}
        renderItem={(l) => {
          const on = l.id === actual
          const Icono = l.tipo === 'informe' ? Inbox : List
          return (
            <Link
              to={`/tareas?view=cliente&cli=${cli}&list=${l.id}${fe ? `&fe=${encodeURIComponent(fe)}` : ''}`}
              draggable={false}
              onContextMenu={(e) => {
                e.preventDefault()
                setMenu({ l, x: e.clientX, y: e.clientY })
              }}
              className={`${TAB} -mb-px ${on ? 'border-accent text-ink-strong' : 'border-transparent text-muted hover:text-ink'}`}
            >
              <Icono className="size-[15px]" strokeWidth={1.8} /> {l.nombre}
              {l.es_cliente && <Eye className="size-3.5 text-label" aria-label="Visible en el portal" />}
            </Link>
          )
        }}
      />
      {permisos.crear && (
        <Menu
          label="Añadir lista"
          trigger={() => (
            <span className={`${TAB} -mb-px border-transparent text-muted hover:text-ink`}>
              <Plus className="size-[15px]" /> Añadir
            </span>
          )}
          width={278}
        >
          {(cerrar) => (
            <>
              <MenuLabel>Crear en este cliente</MenuLabel>
              <MenuItem
                icon={<List className="text-[#7b68ee]" />}
                onClick={() => {
                  cerrar()
                  void nueva('tareas').catch(() => undefined)
                }}
              >
                <span>
                  <b className="block text-[13.5px] font-semibold text-ink-strong">Lista</b>
                  <small className="block text-[11.5px] text-muted">Seguimiento de tareas y elementos.</small>
                </span>
              </MenuItem>
              {!tieneInforme && (
                <MenuItem
                  icon={<Inbox className="text-[#0ea5e9]" />}
                  onClick={() => {
                    cerrar()
                    void nueva('informe').catch(() => undefined)
                  }}
                >
                  <span>
                    <b className="block text-[13.5px] font-semibold text-ink-strong">Informe de cliente</b>
                    <small className="block text-[11.5px] text-muted">Entregables por meses · va al portal.</small>
                  </span>
                </MenuItem>
              )}
            </>
          )}
        </Menu>
      )}

      <MenuPanel open={!!menu} onClose={() => setMenu(null)} anchor={menu ? { x: menu.x, y: menu.y } : null} label="Acciones de la lista">
        {menu && (
          <>
            <MenuItem icon={<FolderOpen />} onSelect={() => ir(menu.l.id)}>
              Abrir lista
            </MenuItem>
            {permisos.editar && (
              <>
                <MenuItem icon={<Pencil />} onSelect={() => void renombrar(menu.l)}>
                  Renombrar
                </MenuItem>
                {menu.l.tipo !== 'informe' && (
                  <MenuItem icon={menu.l.es_cliente ? <EyeOff /> : <Eye />} onSelect={() => acciones.cambiar.mutate({ id: menu.l.id, es_cliente: !menu.l.es_cliente })}>
                    {menu.l.es_cliente ? 'Quitar del portal del cliente' : 'Que el cliente vea toda la lista'}
                  </MenuItem>
                )}
              </>
            )}
            {permisos.crear && (
              <MenuItem icon={<Copy />} onSelect={() => acciones.clonar.mutate(menu.l.id)}>
                Clonar (con sus tareas)
              </MenuItem>
            )}
            {permisos.borrar && (
              <>
                <MenuSeparator />
                <MenuItem icon={<Trash2 />} danger onSelect={() => void borrar(menu.l)}>
                  Borrar lista
                </MenuItem>
              </>
            )}
          </>
        )}
      </MenuPanel>
    </div>
  )
}
