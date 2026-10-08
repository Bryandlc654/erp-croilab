import { useMemo, type MouseEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { CheckSquare, Copy, Euro, Eye, ListChecks, Pencil, Plus, Ticket, Trash2, Users } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import Card from '../../../shared/ui/Card'
import EmptyState from '../../../shared/ui/EmptyState'
import IconButton from '../../../shared/ui/IconButton'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import Segmented from '../../../shared/ui/Segmented'
import Select from '../../../shared/ui/Select'
import { SearchBox } from '../../../shared/ui/FilterBar'
import { MenuItem, MenuPanel, MenuSeparator } from '../../../shared/ui/Menu'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useContextMenu } from '../../../shared/ui/useContextMenu'
import { mensajeError, useBorrarCliente, useClientesTabla, useDuplicarCliente, useTipos } from '../api'
import { contarSegmentos, filtrarClientes, segmentoDe, textoALaVista, TITULOS_SEGMENTO } from '../lib/filtro'
import { importe } from '../lib/etiquetas'
import type { ClienteFila } from '../schemas'
import Etiqueta from './Etiqueta'
import { usePermisosClientes } from './permisos'

/* Listado de clientes (index.php): segmentado En alta / No activos / Todos,
   buscador y tipo, todo filtrado en el navegador. Clic derecho en una fila
   abre sus acciones. */
export default function ClientesPage() {
  const [params, setParams] = useSearchParams()
  const segmento = segmentoDe(params.get('f'))
  const q = params.get('q') ?? ''
  const tipo = params.get('tipo') ?? ''
  const { data: clientes, isLoading, error } = useClientesTabla()
  const { data: tipos } = useTipos()
  const p = usePermisosClientes()
  const navigate = useNavigate()
  const { confirm } = useConfirm()
  const duplicar = useDuplicarCliente()
  const borrar = useBorrarCliente()
  const cm = useContextMenu<ClienteFila>()

  const visibles = useMemo(() => filtrarClientes(clientes ?? [], { segmento, q, tipo }), [clientes, segmento, q, tipo])
  const cuenta = useMemo(() => contarSegmentos(clientes ?? []), [clientes])

  function cambiar(clave: string, valor: string) {
    const n = new URLSearchParams(params)
    if (valor) n.set(clave, valor)
    else n.delete(clave)
    setParams(n, { replace: true })
  }

  function enlaceSegmento(f: string) {
    const n = new URLSearchParams(params)
    n.set('f', f)
    return `/clientes?${n.toString()}`
  }

  async function pedirDuplicar(c: ClienteFila) {
    const ok = await confirm({ title: 'Duplicar cliente', message: `¿Crear una copia de ${c.name}? Podrás editarla después.`, okLabel: 'Duplicar' })
    if (!ok) return
    const r = await duplicar.mutateAsync(c.id).catch(() => null)
    if (r) navigate(`/clientes/${r.id}?dup=1`, { state: { password: r.password } })
  }

  async function pedirBorrar(c: ClienteFila) {
    const ok = await confirm({
      title: `¿Borrar «${c.name}»?`,
      message: 'Se borran también sus tareas, listas, tickets y accesos. Queda 30 días en la papelera por si acaso.',
      okLabel: 'Borrar',
      danger: true,
    })
    if (ok) borrar.mutate({ id: c.id, name: c.name })
  }

  function menuFila(e: MouseEvent, c: ClienteFila) {
    // Sobre un enlace o un botón, el menú del navegador (abrir en pestaña nueva…).
    if ((e.target as HTMLElement).closest('a,button')) return
    cm.onContextMenu(e, c)
  }

  const opcionesTipo = [
    { value: '', label: 'Todos los tipos' },
    ...(tipos ?? []).map((t) => ({ value: String(t.id), label: t.nombre })),
    { value: 'none', label: '— Sin tipo —' },
  ]

  const vacio = !isLoading && !error && (clientes?.length ?? 0) === 0

  return (
    <div>
      <PageHeader
        title={TITULOS_SEGMENTO[segmento]}
        lead={clientes ? textoALaVista(visibles.length) : ' '}
        actions={
          p.crear && (
            <Button to="/clientes/nuevo" icon={<Plus />}>
              Nuevo cliente
            </Button>
          )
        }
      />

      {error && <Notice tone="error">{mensajeError(error, 'No se han podido cargar los clientes.')}</Notice>}

      {vacio ? (
        <EmptyState
          icon={<Users />}
          title="Aún no hay clientes"
          text={p.crear ? 'Crea el primero para montar su portal, sus tareas y su facturación.' : 'Cuando el equipo dé de alta un cliente, aparecerá aquí.'}
          actions={
            p.crear && (
              <Button to="/clientes/nuevo" icon={<Plus />}>
                Nuevo cliente
              </Button>
            )
          }
        />
      ) : (
        <>
          <div className="mb-[18px] flex flex-wrap items-center gap-2.5 max-sm:flex-col max-sm:items-stretch">
            <Segmented
              aria-label="Qué clientes"
              value={segmento}
              className="max-sm:w-full max-sm:[&>*]:flex-1 max-sm:[&>*]:justify-center"
              items={[
                { value: 'alta', label: 'En alta', count: cuenta.alta, href: enlaceSegmento('alta') },
                { value: 'baja', label: 'No activos', count: cuenta.baja, href: enlaceSegmento('baja') },
                { value: 'todos', label: 'Todos', count: cuenta.todos, href: enlaceSegmento('todos') },
              ]}
            />
            <SearchBox value={q} onChange={(v) => cambiar('q', v)} placeholder="Buscar por nombre o usuario…" delay={0} className="max-w-[340px]" />
            <Select
              variant="mini"
              aria-label="Tipo de cliente"
              value={tipo}
              onChange={(v) => cambiar('tipo', v)}
              options={opcionesTipo}
              className="!max-w-[240px] min-w-[186px] max-sm:!max-w-none"
            />
          </div>

          <Card padding="none" className="overflow-hidden">
            {isLoading ? (
              <p className="px-6 py-[60px] text-center text-[13.5px] text-muted">Cargando…</p>
            ) : (
              <table className="w-full border-collapse text-[13.5px]">
                <thead className="max-sm:hidden">
                  <tr>
                    {['Cliente', 'Tipo', 'Actividad', ''].map((h, i) => (
                      <th
                        key={i}
                        scope="col"
                        className={`border-b border-line px-3 py-[11px] text-left text-[11.5px] font-semibold tracking-[.5px] text-muted uppercase first:pl-6 last:pr-6 ${i === 1 ? 'max-[900px]:hidden' : ''}`}
                      >
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {visibles.map((c) => (
                    <FilaCliente
                      key={c.id}
                      c={c}
                      p={p}
                      onContextMenu={(e) => menuFila(e, c)}
                      onDuplicar={() => void pedirDuplicar(c)}
                    />
                  ))}
                  {visibles.length === 0 && (
                    <tr>
                      <td colSpan={4} className="px-6 py-10 text-center text-[13.5px] text-muted">
                        No hay clientes que coincidan con la búsqueda.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            )}
          </Card>
        </>
      )}

      <MenuPanel {...cm.panel} label="Acciones del cliente">
        {cm.dato && (
          <>
            <MenuItem icon={<Eye />} onClick={() => navigate(`/clientes/${cm.dato!.id}`)}>
              Abrir ficha
            </MenuItem>
            <MenuItem icon={<ListChecks />} onClick={() => navigate(`/tareas?view=cliente&cli=${cm.dato!.id}`)}>
              Tareas del cliente
            </MenuItem>
            <MenuItem icon={<Euro />} onClick={() => navigate(`/finanzas/facturas?cli=${cm.dato!.id}`)}>
              Facturas
            </MenuItem>
            {(p.editar || p.crear) && <MenuSeparator />}
            {p.editar && (
              <MenuItem icon={<Pencil />} onClick={() => navigate(`/clientes/${cm.dato!.id}/editar`)}>
                Editar datos
              </MenuItem>
            )}
            {p.crear && (
              <MenuItem icon={<Copy />} onClick={() => void pedirDuplicar(cm.dato!)}>
                Duplicar
              </MenuItem>
            )}
            {p.borrar && (
              <>
                <MenuSeparator />
                <MenuItem icon={<Trash2 />} danger onClick={() => void pedirBorrar(cm.dato!)}>
                  Borrar cliente
                </MenuItem>
              </>
            )}
          </>
        )}
      </MenuPanel>
    </div>
  )
}

function FilaCliente({
  c,
  p,
  onContextMenu,
  onDuplicar,
}: {
  c: ClienteFila
  p: ReturnType<typeof usePermisosClientes>
  onContextMenu: (e: MouseEvent) => void
  onDuplicar: () => void
}) {
  const navigate = useNavigate()
  const actividad = (
    <span className="flex flex-wrap items-center gap-1.5">
      {c.tareas_abiertas > 0 && (
        <Etiqueta>
          <CheckSquare className="size-3" /> {c.tareas_abiertas} tarea{c.tareas_abiertas === 1 ? '' : 's'}
        </Etiqueta>
      )}
      {c.tickets_abiertos > 0 && (
        <Etiqueta>
          <Ticket className="size-3" /> {c.tickets_abiertos} ticket{c.tickets_abiertos === 1 ? '' : 's'}
        </Etiqueta>
      )}
      {c.pendiente_cobro !== null && c.pendiente_cobro > 0 && (
        <Etiqueta>
          <Euro className="size-3" /> {importe(c.pendiente_cobro)}
        </Etiqueta>
      )}
      {c.tareas_abiertas === 0 && c.tickets_abiertos === 0 && !(c.pendiente_cobro && c.pendiente_cobro > 0) && (
        <span className="text-[12px] text-muted">Sin actividad</span>
      )}
    </span>
  )
  return (
    <tr
      onContextMenu={onContextMenu}
      className={`group border-b border-line last:border-b-0 transition-colors ${c.activo ? 'hover:bg-hover-row' : 'bg-line2'}`}
    >
      <td className="py-[18px] pr-3 pl-6 max-sm:py-3.5 max-sm:pr-4 max-sm:pl-4">
        <div className="flex items-center gap-3">
          <Avatar
            nombre={c.name}
            inicialesGuardadas={c.iniciales}
            forma="cuadrado"
            size={34}
            className={`transition-transform duration-150 group-hover:scale-[1.06] ${c.activo ? '' : 'opacity-55'}`}
          />
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-1.5">
              <Link to={`/clientes/${c.id}`} className="truncate text-[14.5px] font-semibold text-accent hover:underline">
                {c.name}
              </Link>
              {!c.activo && <Etiqueta tono="rojo">No activo</Etiqueta>}
              {c.conversiones && <Etiqueta>Conversiones</Etiqueta>}
            </div>
            <div className="mt-0.5 text-[12px] text-muted">
              {c.username}
              {/* En el móvil la fila es plana: el tipo y la actividad van debajo del nombre. */}
              <span className="hidden max-sm:inline">{c.tipo_nombre ? ` · ${c.tipo_nombre}` : ''}</span>
            </div>
            <div className="mt-1.5 hidden max-sm:block">{actividad}</div>
          </div>
        </div>
      </td>
      <td className="px-3 py-[18px] max-[900px]:hidden">{c.tipo_nombre ? <Etiqueta tono="on">{c.tipo_nombre}</Etiqueta> : <Etiqueta>Sin tipo</Etiqueta>}</td>
      <td className="px-3 py-[18px] max-sm:hidden">{actividad}</td>
      <td className="py-[18px] pr-6 pl-3 text-right max-sm:hidden">
        <span className="inline-flex items-center gap-0.5 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100 max-[760px]:opacity-100">
          <IconButton label="Abrir su ficha" icon={<Eye />} onClick={() => navigate(`/clientes/${c.id}`)} />
          <IconButton label="Sus tareas" icon={<ListChecks />} onClick={() => navigate(`/tareas?view=cliente&cli=${c.id}`)} />
          <IconButton label="Sus facturas" icon={<Euro />} onClick={() => navigate(`/finanzas/facturas?cli=${c.id}`)} />
          {p.editar && <IconButton label="Editar sus datos" icon={<Pencil />} onClick={() => navigate(`/clientes/${c.id}/editar`)} />}
          {p.crear && <IconButton label="Duplicar" icon={<Copy />} onClick={onDuplicar} />}
        </span>
      </td>
    </tr>
  )
}
