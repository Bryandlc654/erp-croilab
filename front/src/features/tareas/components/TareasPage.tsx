import { useCallback, useMemo, useState, type ReactNode } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { ChevronDown, ChevronRight, ClipboardList, Inbox, List, UserCheck, UserRound } from 'lucide-react'
import { useAuth } from '../../auth/useAuth'
import { useEquipo } from '../../nav/api'
import { useBuscarClientes, useCliente } from '../../clientes/api'
import Avatar from '../../../shared/ui/Avatar'
import Menu, { MenuItem } from '../../../shared/ui/Menu'
import { useDebounced } from '../../../shared/lib/useDebounced'
import EstadoCirculo from './EstadoCirculo'
import TareaFila, { COLUMNAS } from './TareaFila'
import TareaDetalle from './TareaDetalle'
import { ESTADOS, ORDEN_ESTADOS } from '../constantes'
import { agruparTareas } from '../agrupar'
import { useActualizarTarea, useBorrarTarea, useTareas, type Vista } from '../api'
import type { CambiosTarea, CampoEnLinea, Estado, Tarea } from '../schemas'

const PLEGADOS = 'croilab:ws_collapsed'
function leerPlegados(): Set<string> {
  try {
    return new Set(JSON.parse(localStorage.getItem(PLEGADOS) || '[]'))
  } catch {
    return new Set()
  }
}

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

/* Una tarjeta de grupo (un cliente o un estado) con su cabecera plegable. */
function Grupo({
  clave,
  cabecera,
  n,
  plegados,
  alternar,
  children,
}: {
  clave: string
  cabecera: ReactNode
  n: number
  plegados: Set<string>
  alternar: (k: string) => void
  children: ReactNode
}) {
  const plegado = plegados.has(clave)
  return (
    <section className="mb-5 rounded-[14px] border border-line bg-page">
      <button
        type="button"
        onClick={() => alternar(clave)}
        aria-expanded={!plegado}
        className={`flex w-full items-center gap-2.5 rounded-t-[14px] bg-head px-[18px] py-[15px] text-left text-[13px] font-semibold text-ink transition-colors select-none hover:bg-[#f4f5f7] dark:hover:bg-white/5 ${
          plegado ? 'rounded-b-[14px]' : 'border-b border-line2'
        }`}
      >
        <ChevronRight className={`size-4 shrink-0 text-muted transition-transform ${plegado ? '-rotate-90' : ''}`} />
        {cabecera}
        <span className="ml-auto rounded-full bg-soft px-[9px] py-px text-[11.5px] font-semibold text-muted">{n}</span>
      </button>
      {!plegado && (
        <div role="table">
          <div role="row" className={`${COLUMNAS} border-b border-line bg-head px-4 py-3 max-md:hidden text-[10.5px] font-[650] tracking-[.5px] text-muted uppercase`}>
            <span role="columnheader">Nombre</span>
            <span role="columnheader">Persona asignada</span>
            <span role="columnheader">Fecha límite</span>
            <span role="columnheader">Prioridad</span>
            <span role="columnheader" aria-label="Acciones" />
          </div>
          {children}
        </div>
      )}
    </section>
  )
}

export default function TareasPage() {
  const [params, setParams] = useSearchParams()
  const navigate = useNavigate()
  const { me, can } = useAuth()

  const view = (VISTAS as string[]).includes(params.get('view') || '') ? (params.get('view') as Vista) : 'all'
  const emp = Number(params.get('emp') || 0)
  const cli = Number(params.get('cli') || 0)
  const list = Number(params.get('list') || 0)
  const fe = params.get('fe') || ''
  const fr = params.get('fr') || ''

  const consulta = useTareas({ view, emp, cli, list, fe, fr })
  const { data: equipoData } = useEquipo()
  const equipo = useMemo(() => equipoData ?? [], [equipoData])
  const { data: clienteData } = useCliente(view === 'cliente' ? cli : 0)
  const cliente = clienteData?.cliente

  const actualizar = useActualizarTarea()
  const borrar = useBorrarTarea()

  const [abierta, setAbierta] = useState<number | null>(null)
  const [plegados, setPlegados] = useState<Set<string>>(leerPlegados)
  const [buscarCli, setBuscarCli] = useState('')
  // Búsqueda en el servidor, pero solo cuando se deja de teclear.
  const textoCli = useDebounced(buscarCli, 250)
  const busqueda = useBuscarClientes(textoCli)
  const cerrarDetalle = useCallback(() => setAbierta(null), [])

  const puedeEditar = can('general.editar') && can('tareas.editar')
  const puedeBorrar = can('general.editar') && can('tareas.borrar')

  const tareas = useMemo(() => consulta.data?.pages.flatMap((p) => p.items) ?? [], [consulta.data])
  // La API dice qué lista está enseñando (sin `list` en la URL usa la primera).
  const listaActual = consulta.data?.pages[0]?.list_id ?? null
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

  function alternarGrupo(k: string) {
    setPlegados((prev) => {
      const s = new Set(prev)
      if (s.has(k)) s.delete(k)
      else s.add(k)
      try {
        localStorage.setItem(PLEGADOS, JSON.stringify([...s]))
      } catch {
        // Sin localStorage los grupos se recuerdan solo mientras dura la pantalla.
      }
      return s
    })
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
    actualizar.mutate({ antes: t, optimista, cambios })
  }

  function onBorrar(t: Tarea) {
    if (!window.confirm(`¿Borrar «${t.titulo}»?\nLa tarea va a la papelera; podrás deshacerlo.`)) return
    borrar.mutate(t)
  }

  /* Grupos: por cliente en las vistas generales, por estado en la de un cliente. */
  const grupos = useMemo(() => agruparTareas(tareas, view), [tareas, view])

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
          className="w-[230px]"
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
          className="h-[37px] w-[170px] rounded-[9px] border border-line bg-page px-[11px] text-[13px] text-ink placeholder:text-muted focus:border-[#c9ccd1] focus:outline-none"
        />
      </div>

      {/* Título y filtros */}
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <h1 className="mr-auto flex items-center gap-2.5 text-[23px] font-semibold tracking-[-.5px] text-ink-strong">
          {view === 'cliente' && cliente && <Avatar nombre={cliente.name} inicialesGuardadas={cliente.iniciales} size={28} forma="cuadrado" />}
          {titulo}
        </h1>
        {view === 'all' && (
          <Desplegable label="Responsable" className="w-[200px]" value={fr} onChange={(v) => cambiarFiltro('fr', v)}>
            <option value="">Todos los responsables</option>
            {equipo.map((p) => (
              <option key={p.id} value={p.id}>
                {p.username}
              </option>
            ))}
          </Desplegable>
        )}
        <Desplegable label="Estado" className="w-[184px]" value={fe} onChange={(v) => cambiarFiltro('fe', v)}>
          <option value="">Todos los estados</option>
          {ORDEN_ESTADOS.map((e) => (
            <option key={e} value={e}>
              {ESTADOS[e].label}
            </option>
          ))}
        </Desplegable>
      </div>

      {/* Listas del cliente */}
      {view === 'cliente' && cliente && cliente.listas.length > 0 && (
        <div className="mb-5 flex flex-wrap gap-1 rounded-[11px] border border-line bg-page p-1">
          {cliente.listas.map((l) => {
            const on = l.id === listaActual
            const Icono = l.tipo === 'informe' ? Inbox : List
            return (
              <Link key={l.id} to={`/tareas?view=cliente&cli=${cli}&list=${l.id}${fe ? `&fe=${encodeURIComponent(fe)}` : ''}`} className={`${TAB} ${on ? TAB_ON : TAB_OFF}`}>
                <Icono className="size-[15px]" strokeWidth={1.8} /> {l.nombre}
              </Link>
            )
          })}
        </div>
      )}

      {error && <div className="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-[13px] text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">{error}</div>}

      {cargando && tareas.length === 0 && !error && (
        <div className="space-y-3" aria-hidden="true">
          {[0, 1].map((i) => (
            <div key={i} className="h-[150px] animate-pulse rounded-[14px] border border-line bg-head" />
          ))}
        </div>
      )}

      {!cargando && !error && grupos.length === 0 && (
        <div className="rounded-[14px] border border-dashed border-line px-6 py-16 text-center">
          <ClipboardList className="mx-auto mb-3 size-8 text-label" strokeWidth={1.5} />
          <p className="text-[14px] font-semibold text-ink-strong">No hay tareas aquí</p>
          <p className="mt-1 text-[13px] text-muted">{fe ? 'Prueba con otro estado.' : 'Cuando haya tareas pendientes aparecerán en esta vista.'}</p>
        </div>
      )}

      {grupos.map((g) => (
        <Grupo
          key={g.clave}
          clave={g.clave}
          n={g.tareas.length}
          plegados={plegados}
          alternar={alternarGrupo}
          cabecera={
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
          {g.tareas.map((t) => (
            <TareaFila
              key={t.id}
              t={t}
              equipo={equipo}
              puedeEditar={puedeEditar}
              puedeBorrar={puedeBorrar}
              mostrarLista={view !== 'cliente'}
              onCampo={onCampo}
              onAbrir={(x) => setAbierta(x.id)}
              onBorrar={onBorrar}
            />
          ))}
        </Grupo>
      ))}

      {/* Las vistas grandes llegan de 100 en 100. */}
      {consulta.hasNextPage && !error && (
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

      {abierta !== null && <TareaDetalle id={abierta} onCerrar={cerrarDetalle} />}
    </div>
  )
}
