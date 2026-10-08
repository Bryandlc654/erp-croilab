import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { CheckCircle2, ChevronDown, ChevronRight, ClipboardList, ExternalLink, Inbox, ListPlus, Plus, Send, Trash2, UserCheck, UserRound } from 'lucide-react'
import { useAuth } from '../../auth/useAuth'
import { useEquipo } from '../../nav/api'
import { useBuscarClientes, useCliente } from '../../clientes/api'
import { api } from '../../../shared/api/client'
import { z } from 'zod'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import EmptyState from '../../../shared/ui/EmptyState'
import InlineAddRow from '../../../shared/ui/InlineAddRow'
import Menu, { MenuItem, MenuPanel, MenuSeparator } from '../../../shared/ui/Menu'
import SortableList from '../../../shared/ui/SortableList'
import { useDebounced } from '../../../shared/lib/useDebounced'
import { usePlegados } from '../../../shared/lib/usePlegados'
import GroupCard from '../../../shared/ui/GroupCard'
import { useConfirm } from '../../../shared/ui/useConfirm'
import EstadoCirculo from './EstadoCirculo'
import TareaFila, { COLUMNAS } from './TareaFila'
import TareaDetalle from './TareaDetalle'
import NuevaTareaModal, { type DatosNuevaTarea } from './NuevaTareaModal'
import ListasCliente from './ListasCliente'
import InformeLista from './InformeLista'
import { ESTADOS, ORDEN_ESTADOS } from '../constantes'
import { agruparTareas } from '../agrupar'
import { completar, ordenTrasMover } from '../informe'
import { useAccionesListas, useActualizarTarea, useBorrarTarea, useCrearTarea, useListasCliente, useReordenarTareas, useTareas, type Vista } from '../api'
import type { CambiosTarea, CampoEnLinea, Estado, Tarea } from '../schemas'

const PLEGADOS = 'croilab:ws_collapsed'

const VISTAS: Vista[] = ['all', 'mine', 'emp', 'cliente']

const SELECT =
  'h-[37px] appearance-none rounded-[9px] border border-line bg-page pr-8 pl-[11px] text-[13px] text-ink focus:border-[#c9ccd1] focus:outline-none'

function Desplegable({ value, onChange, children, label, className = '' }: { value: string; onChange: (v: string) => void; children: ReactNode; label: string; className?: string }) {
  return (
    <div className={`relative ${className}`}>
      <select aria-label={label} value={value} onChange={(e) => onChange(e.target.value)} className={`${SELECT} w-full`}>
        {children}
      </select>
      <ChevronDown className="pointer-events-none absolute top-1/2 right-2.5 size-4 -translate-y-1/2 text-label" />
    </div>
  )
}

/* Cabecera de columnas de cada grupo (en móvil no cabe y se oculta). */
function CabeceraColumnas() {
  return (
    <div role="row" className={`${COLUMNAS} border-b border-line bg-head px-4 py-3 max-md:hidden text-[10.5px] font-[650] tracking-[.5px] text-muted uppercase`}>
      <span role="columnheader">Nombre</span>
      <span role="columnheader">Persona asignada</span>
      <span role="columnheader">Fecha límite</span>
      <span role="columnheader">Prioridad</span>
      <span role="columnheader" aria-label="Acciones" />
    </div>
  )
}

const InformeListaRespuesta = z.object({ list_id: z.number().int() })

export default function TareasPage() {
  const [params, setParams] = useSearchParams()
  const navigate = useNavigate()
  const { me, can } = useAuth()

  const view = (VISTAS as string[]).includes(params.get('view') || '') ? (params.get('view') as Vista) : params.get('cli') ? 'cliente' : 'all'
  const emp = Number(params.get('emp') || 0)
  const cli = Number(params.get('cli') || 0)
  const list = Number(params.get('list') || 0)
  const fe = params.get('fe') || ''
  const fr = params.get('fr') || ''
  const mes = params.get('mes') || ''
  const pideInforme = params.get('informe') === '1'

  const { data: equipoData } = useEquipo()
  const equipo = useMemo(() => equipoData ?? [], [equipoData])
  const { data: clienteData } = useCliente(view === 'cliente' ? cli : 0)
  const cliente = clienteData?.cliente
  const { data: listasData } = useListasCliente(view === 'cliente' ? cli : 0)
  const listas = useMemo(() => listasData?.listas ?? [], [listasData])

  const consulta = useTareas({ view, emp, cli, list, fe, fr }, !pideInforme)
  const actualizar = useActualizarTarea()
  const borrar = useBorrarTarea()
  const crear = useCrearTarea()
  const reordenar = useReordenarTareas()
  const accionesListas = useAccionesListas()

  const [rapida, setRapida] = useState<number | null>(null)
  const [nueva, setNueva] = useState<DatosNuevaTarea | null>(null)
  const [menu, setMenu] = useState<{ t: Tarea; x: number; y: number } | null>(null)
  const { estaPlegado, alternar: alternarGrupo } = usePlegados(PLEGADOS, ['est-completada'])
  const { confirm } = useConfirm()
  const [buscarCli, setBuscarCli] = useState('')
  // Búsqueda en el servidor, pero solo cuando se deja de teclear.
  const textoCli = useDebounced(buscarCli, 250)
  const busqueda = useBuscarClientes(textoCli)
  const cerrarDetalle = useCallback(() => setRapida(null), [])

  const puedeCrear = can('general.editar') && can('tareas.crear')
  const puedeEditar = can('general.editar') && can('tareas.editar')
  const puedeBorrar = can('general.editar') && can('tareas.borrar')
  const permisosListas = { crear: puedeCrear, editar: puedeEditar, borrar: puedeBorrar }

  /* ?informe=1 (desde la ficha del cliente): abre su lista de informes, y la
     crea si aún no la tiene. Antes era un GET que escribía sin CSRF. */
  const informePedido = useRef(false)
  useEffect(() => {
    if (!pideInforme || !cli || informePedido.current) return
    informePedido.current = true
    api(`/api/v1/clientes/${cli}/informe`, { method: 'POST', schema: InformeListaRespuesta })
      .then((r) => navigate(`/tareas?view=cliente&cli=${cli}&list=${r.list_id}`, { replace: true }))
      .catch(() => navigate(`/tareas?view=cliente&cli=${cli}`, { replace: true }))
  }, [pideInforme, cli, navigate])

  const tareas = useMemo(() => consulta.data?.pages.flatMap((p) => p.items) ?? [], [consulta.data])
  // La API dice qué lista está enseñando (sin `list` en la URL usa la primera).
  const listaActual = consulta.data?.pages[0]?.list_id ?? (list || listas[0]?.id || null)
  const lista = listas.find((l) => l.id === listaActual) ?? null
  const esInforme = view === 'cliente' && lista?.tipo === 'informe'
  // Esqueleto en la primera carga y al cambiar de vista mientras se ve la anterior
  // vacía; las recargas en segundo plano no lo enseñan.
  const cargando = consulta.isPending || (consulta.isPlaceholderData && consulta.isFetching)
  const error = consulta.error?.message ?? null

  function cambiarFiltro(clave: string, valor: string) {
    const p = new URLSearchParams(params)
    if (valor) p.set(clave, valor)
    else p.delete(clave)
    setParams(p)
  }

  /* Cambio en línea: el valor llega como texto desde la fila y aquí se traduce
     al tipo que espera la API y a cómo debe verse la fila al momento. */
  function onCampo(t: Tarea, campo: CampoEnLinea, valor: string) {
    const optimista: Tarea = { ...t }
    const cambios: CambiosTarea = {}
    if (campo === 'estado') optimista.estado = cambios.estado = valor as Estado
    if (campo === 'prioridad') optimista.prioridad = cambios.prioridad = Number(valor)
    if (campo === 'due_date') optimista.due_date = cambios.due_date = valor || null
    if (campo === 'responsable_id') {
      const p = equipo.find((x) => x.id === Number(valor))
      optimista.responsable_id = cambios.responsable_id = p ? p.id : null
      optimista.asignados = p ? [p] : []
    }
    if (campo === 'asignados') {
      const ids = valor ? valor.split(',').map(Number) : []
      cambios.asignados = ids
      optimista.asignados = ids.map((id) => equipo.find((x) => x.id === id)).filter((x): x is (typeof equipo)[number] => !!x)
      optimista.responsable_id = ids[0] ?? null
    }
    actualizar.mutate({ antes: t, optimista, cambios })
  }

  async function onBorrar(t: Tarea) {
    const ok = await confirm({ title: '¿Borrar la tarea?', message: `«${t.titulo}» va a la papelera con sus comentarios, lista de control y adjuntos (las horas no se borran); podrás deshacerlo.`, danger: true, okLabel: 'Borrar' })
    if (ok) borrar.mutate(t)
  }

  async function publicar() {
    if (await confirm({ title: '¿Publicar ahora?', message: 'Se actualiza el Progreso y los Informes que ve el cliente en su portal.', okLabel: 'Publicar' })) accionesListas.publicar.mutate(cli)
  }

  /* Grupos: por cliente en las vistas generales, por estado en la de un cliente. */
  const grupos = useMemo(() => agruparTareas(tareas, view), [tareas, view])
  const idsPorGrupo = grupos.map((g) => g.tareas.map((t) => t.id))
  /* En la vista de un cliente, una lista vacía enseña al menos «En espera» para poder añadir. */
  const gruposVista = view === 'cliente' && grupos.length === 0 && listaActual && !error && !cargando ? [{ tipo: 'estado' as const, clave: 'est-pendiente', estado: 'pendiente' as Estado, tareas: [] as Tarea[] }] : grupos

  const empleado = equipo.find((p) => p.id === emp)
  const titulo =
    view === 'mine' ? 'Mis tareas' : view === 'emp' ? `Tareas de ${empleado?.username ?? '…'}` : view === 'cliente' ? (cliente?.name ?? 'Cliente') : 'Todas las tareas'

  // Resultados del buscador; el cliente que se está viendo siempre figura para
  // que el desplegable lo enseñe seleccionado aunque no salga en la búsqueda.
  const resultados = busqueda.data?.items ?? []
  const opcionesCli = cliente && !resultados.some((c) => c.id === cliente.id) ? [cliente, ...resultados] : resultados

  const TAB = 'inline-flex items-center gap-[7px] whitespace-nowrap rounded-lg px-3.5 py-[7px] text-[13.5px] font-semibold transition-colors'
  const TAB_ON = 'bg-tab-on text-white dark:text-zinc-900'
  const TAB_OFF = 'text-[#6b7280] hover:text-ink dark:text-zinc-400'

  if (pideInforme) return <div className="mx-auto max-w-[1400px] py-10 text-center text-[13px] text-muted">Abriendo el informe…</div>

  return (
    <div className="mx-auto max-w-[1400px]">
      {/* Pestañas y saltos */}
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <div className="flex flex-wrap gap-1 rounded-[11px] border border-line bg-page p-1">
          <Link to="/tareas?view=all" className={`${TAB} ${view === 'all' ? TAB_ON : TAB_OFF}`}>
            <Inbox className="size-[15px]" strokeWidth={1.8} /> Todas las tareas
          </Link>
          <Link to="/tareas?view=mine" className={`${TAB} ${view === 'mine' ? TAB_ON : TAB_OFF}`}>
            <UserCheck className="size-[15px]" strokeWidth={1.8} /> Mis tareas
          </Link>
          <Menu
            label="Tareas de una persona"
            panelClassName="max-h-80 overflow-y-auto"
            trigger={(abierto) => (
              <span className={`${TAB} ${view === 'emp' ? TAB_ON : TAB_OFF}`}>
                {view === 'emp' && empleado ? (
                  <>
                    <Avatar nombre={empleado.username} foto={empleado.foto} size={18} /> {empleado.username}
                  </>
                ) : (
                  <>
                    <UserRound className="size-[15px]" strokeWidth={1.8} /> Tareas de…
                  </>
                )}
                <ChevronRight className={`size-3.5 transition-transform ${abierto ? 'rotate-90' : ''}`} />
              </span>
            )}
          >
            {(cerrar) =>
              equipo.map((p) => (
                <MenuItem
                  key={p.id}
                  on={view === 'emp' && emp === p.id}
                  onClick={() => {
                    cerrar()
                    navigate(`/tareas?view=emp&emp=${p.id}`)
                  }}
                >
                  <Avatar nombre={p.username} foto={p.foto} size={20} />
                  <span className="truncate">{p.username}</span>
                  {p.id === me?.id && <span className="ml-auto text-[11px] text-muted">tú</span>}
                </MenuItem>
              ))
            }
          </Menu>
        </div>

        <Desplegable
          label="Ir a un cliente"
          className="w-[230px] max-sm:w-full"
          value={view === 'cliente' && cli ? String(cli) : ''}
          onChange={(v) => v && navigate(`/tareas?view=cliente&cli=${v}`)}
        >
          <option value="">Ir a un cliente...</option>
          {opcionesCli.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
          {opcionesCli.length === 0 && !busqueda.isPending && <option disabled>Sin coincidencias</option>}
        </Desplegable>
        <input
          type="search"
          value={buscarCli}
          onChange={(e) => setBuscarCli(e.target.value)}
          placeholder="Buscar cliente..."
          aria-label="Buscar cliente"
          className="h-[37px] w-[170px] rounded-[9px] border border-line bg-page px-[11px] text-[13px] text-ink placeholder:text-muted focus:border-[#c9ccd1] focus:outline-none max-sm:w-full max-sm:text-[16px]"
        />
      </div>

      {/* Título y filtros */}
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <h1 className="mr-auto flex min-w-0 items-center gap-2.5 text-[23px] font-semibold tracking-[-.5px] text-ink-strong">
          {view === 'cliente' && cliente && <Avatar nombre={cliente.name} inicialesGuardadas={cliente.iniciales} size={28} forma="cuadrado" />}
          <span className="truncate">{titulo}</span>
          {view === 'cliente' && cliente && (
            <Link to={`/clientes/${cliente.id}`} className="rounded-md p-1 text-label hover:bg-soft hover:text-ink" aria-label="Ficha del cliente" title="Ficha del cliente">
              <ExternalLink className="size-4" />
            </Link>
          )}
        </h1>
        {view === 'all' && (
          <Desplegable label="Responsable" className="w-[200px] max-sm:flex-1" value={fr} onChange={(v) => cambiarFiltro('fr', v)}>
            <option value="">Todos los responsables</option>
            {equipo.map((p) => (
              <option key={p.id} value={p.id}>
                {p.username}
              </option>
            ))}
          </Desplegable>
        )}
        {!esInforme && (
          <Desplegable label="Estado" className="w-[184px] max-sm:flex-1" value={fe} onChange={(v) => cambiarFiltro('fe', v)}>
            <option value="">Todos los estados</option>
            {ORDEN_ESTADOS.map((e) => (
              <option key={e} value={e}>
                {ESTADOS[e].label}
              </option>
            ))}
          </Desplegable>
        )}
        {view !== 'cliente' && puedeCrear && (
          <Button icon={<Plus />} onClick={() => setNueva({})}>
            Nueva tarea
          </Button>
        )}
      </div>

      {/* Listas del cliente */}
      {view === 'cliente' && cliente && listas.length > 0 && <ListasCliente cli={cli} listas={listas} actual={listaActual} fe={fe} permisos={permisosListas} />}

      {view === 'cliente' && cliente && listasData && listas.length === 0 && (
        <EmptyState
          variant="dashed"
          icon={<ListPlus />}
          title="Este cliente aún no tiene listas"
          text="Las listas ordenan su trabajo: tareas, estrategia, lo que tiene que hacer el cliente y sus informes."
          actions={
            puedeCrear ? (
              <Button loading={accionesListas.crear.isPending} onClick={() => accionesListas.crear.mutate({ client_id: cli, por_defecto: true })}>
                Crear listas por defecto (Tareas · Estrategia · Tarea cliente · Informes)
              </Button>
            ) : undefined
          }
        />
      )}

      {/* Barra de la lista de tareas */}
      {view === 'cliente' && lista && !esInforme && (
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <span className="mr-auto text-[13px] text-muted">{consulta.data?.pages[0]?.total ?? 0} tarea(s)</span>
          {can('general.editar') && can('clientes.portal') && (
            <Button variant="ghost" size="sm" icon={<Send />} onClick={() => void publicar()} loading={accionesListas.publicar.isPending}>
              Publicar al portal
            </Button>
          )}
          {puedeCrear && (
            <Button size="sm" icon={<Plus />} onClick={() => setNueva({ cli, list: lista.id })}>
              Añadir tarea
            </Button>
          )}
        </div>
      )}

      {esInforme && lista && (
        <InformeLista
          cli={cli}
          lista={lista}
          mes={mes}
          onMes={(m) => cambiarFiltro('mes', m)}
          onNueva={(m) => setNueva({ cli, list: lista.id, mes: m })}
          permisos={permisosListas}
        />
      )}

      {error && <div className="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-[13px] text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">{error}</div>}

      {!esInforme && cargando && tareas.length === 0 && !error && (
        <div className="space-y-3" aria-hidden="true">
          {[0, 1].map((i) => (
            <div key={i} className="h-[150px] animate-pulse rounded-[14px] border border-line bg-head" />
          ))}
        </div>
      )}

      {!esInforme && view !== 'cliente' && !cargando && !error && grupos.length === 0 && (
        <div className="rounded-[14px] border border-dashed border-line px-6 py-16 text-center">
          <ClipboardList className="mx-auto mb-3 size-8 text-label" strokeWidth={1.5} />
          <p className="text-[14px] font-semibold text-ink-strong">No hay tareas aquí</p>
          <p className="mt-1 text-[13px] text-muted">{fe ? 'Prueba con otro estado.' : 'Cuando haya tareas pendientes aparecerán en esta vista. Elige un cliente arriba para crear tareas.'}</p>
        </div>
      )}

      {!esInforme &&
        gruposVista.map((g, gi) => (
          <GroupCard
            key={g.clave}
            count={g.tareas.length}
            collapsed={estaPlegado(g.clave)}
            onToggle={() => alternarGrupo(g.clave)}
            title={
              g.tipo === 'estado' ? (
                <span className="inline-flex items-center gap-2">
                  <EstadoCirculo estado={g.estado} size={15} />
                  {ESTADOS[g.estado].label}
                </span>
              ) : (
                <span className="inline-flex min-w-0 items-center gap-2.5">
                  <Avatar nombre={g.nombre} inicialesGuardadas={g.iniciales} size={24} forma="cuadrado" />
                  <span className="truncate">{g.nombre}</span>
                </span>
              )
            }
          >
            <div role="table">
              <CabeceraColumnas />
              {view === 'cliente' ? (
                <SortableList
                  as="div"
                  items={g.tareas}
                  getId={(t) => t.id}
                  disabled={!puedeEditar}
                  onReorder={(ids) => listaActual && reordenar.mutate({ list_id: listaActual, ids: ordenTrasMover(idsPorGrupo, gi, ids.map(Number)) })}
                  renderItem={(t, { handleProps }) => (
                    <TareaFila
                      t={t}
                      equipo={equipo}
                      puedeEditar={puedeEditar}
                      puedeBorrar={puedeBorrar}
                      mostrarLista={false}
                      onCampo={onCampo}
                      onAbrir={(x) => navigate(`/tareas/${x.id}`)}
                      onVistaRapida={(x) => setRapida(x.id)}
                      onBorrar={onBorrar}
                      onMenu={(x, cx, cy) => setMenu({ t: x, x: cx, y: cy })}
                      asa={puedeEditar ? handleProps : undefined}
                    />
                  )}
                />
              ) : (
                g.tareas.map((t) => (
                  <TareaFila
                    key={t.id}
                    t={t}
                    equipo={equipo}
                    puedeEditar={puedeEditar}
                    puedeBorrar={puedeBorrar}
                    mostrarLista
                    onCampo={onCampo}
                    onAbrir={(x) => navigate(`/tareas/${x.id}`)}
                    onVistaRapida={(x) => setRapida(x.id)}
                    onBorrar={onBorrar}
                    onMenu={(x, cx, cy) => setMenu({ t: x, x: cx, y: cy })}
                  />
                ))
              )}
              {view === 'cliente' && g.tipo === 'estado' && puedeCrear && listaActual && (
                <InlineAddRow
                  placeholder="Añadir tarea…"
                  onEmptySubmit={() => setNueva({ cli, list: listaActual, estado: g.estado })}
                  onAdd={(titulo) => crear.mutateAsync({ client_id: cli, list_id: listaActual, titulo, estado: g.estado })}
                />
              )}
            </div>
          </GroupCard>
        ))}

      {/* Las vistas grandes llegan de 100 en 100. */}
      {!esInforme && consulta.hasNextPage && !error && (
        <div className="mb-5 flex justify-center">
          <button
            type="button"
            onClick={() => void consulta.fetchNextPage()}
            disabled={consulta.isFetchingNextPage}
            className="inline-flex items-center rounded-[9px] border border-line px-[13px] py-[7px] text-[12.5px] font-semibold text-ink hover:bg-soft disabled:cursor-default disabled:opacity-60"
          >
            {consulta.isFetchingNextPage ? 'Cargando…' : 'Cargar más'}
          </button>
        </div>
      )}

      <MenuPanel open={!!menu} onClose={() => setMenu(null)} anchor={menu ? { x: menu.x, y: menu.y } : null} label="Acciones de la tarea">
        {menu && (
          <>
            <MenuItem icon={<ExternalLink />} onSelect={() => navigate(`/tareas/${menu.t.id}`)}>
              Abrir tarea
            </MenuItem>
            {puedeEditar && completar(menu.t) && (
              <MenuItem icon={<CheckCircle2 />} onSelect={() => onCampo(menu.t, 'estado', 'completada')}>
                Marcar completada
              </MenuItem>
            )}
            {puedeBorrar && (
              <>
                <MenuSeparator />
                <MenuItem icon={<Trash2 />} danger onSelect={() => void onBorrar(menu.t)}>
                  Borrar tarea
                </MenuItem>
              </>
            )}
          </>
        )}
      </MenuPanel>

      <NuevaTareaModal abierto={nueva !== null} onCerrar={() => setNueva(null)} datos={nueva ?? {}} />
      {rapida !== null && <TareaDetalle id={rapida} onCerrar={cerrarDetalle} />}
    </div>
  )
}
