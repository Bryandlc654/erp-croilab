import { useCallback, useEffect, useMemo, useState, type MouseEvent, type ReactNode } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { AlertTriangle, ChevronLeft, ChevronRight, Copy, ExternalLink, Pencil, Plus, Trash2, UserRound, Video } from 'lucide-react'
import { useHotkeys } from '../../../shared/lib/useHotkeys'
import { ESTADOS_VIVOS, ORDEN_ESTADOS } from '../../../shared/lib/paletas'
import Button from '../../../shared/ui/Button'
import Cargando from '../../../shared/ui/Cargando'
import { MenuItem, MenuPanel, MenuSeparator } from '../../../shared/ui/Menu'
import Notice from '../../../shared/ui/Notice'
import type { AnclaPopover } from '../../../shared/ui/Popover'
import Segmented from '../../../shared/ui/Segmented'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useContextMenu } from '../../../shared/ui/useContextMenu'
import { useToast } from '../../../shared/ui/useToast'
import { mensaje, urlConectarGoogle, useCalendario } from '../api'
import { CajaLogo, LogoGcal } from '../components/Logos'
import type { EventoCal } from '../schemas'
import Bienvenida from './Bienvenida'
import { borradorDeEvento, borradorNuevo, type Borrador, type Preset } from './borrador'
import DialogoSerie from './DialogoSerie'
import { COLOR_GOOGLE } from './estilos'
import { alternarEquipo, aIso, esIso, leerEquipo, leerVista, moverADia, moverEnRejilla, navegar, rangoVista, repartirPorDia, tituloVista, type Vista } from './logicaCalendario'
import ModalEvento from './ModalEvento'
import { useBorrarEvento, useMoverEvento, type Movimiento } from './mutaciones'
import PopoverEvento from './PopoverEvento'
import { useDialogoSerie } from './useDialogoSerie'
import VistaAgenda from './VistaAgenda'
import VistaHoras from './VistaHoras'
import VistaMes, { type AccionesVista } from './VistaMes'

const CLAVE_SOLO_TAREAS = 'croilab:calendario-solo-tareas'

function leerLocal(k: string) {
  try {
    return localStorage.getItem(k) === '1'
  } catch {
    return false
  }
}

function guardarLocal(k: string) {
  try {
    localStorage.setItem(k, '1')
  } catch {
    /* modo privado: solo dura esta visita */
  }
}

const SEGMENTOS: { value: Vista; label: string }[] = [
  { value: 'mes', label: 'Mes' },
  { value: 'semana', label: 'Semana' },
  { value: 'dia', label: 'Día' },
  { value: 'agenda', label: 'Agenda' },
]

type MenuDato = { tipo: 'hueco'; fecha: string; hora?: string } | { tipo: 'evento'; ev: EventoCal }

/* Calendario (calendar.php): tareas por fecha de entrega, mi Google Calendar
   y, si quiero, el de compañeros; festivos. Vista y fecha van en la URL
   (?view=mes|semana|dia|agenda&d=AAAA-MM-DD&team=2,3). */
export default function CalendarioPage() {
  const [params, setParams] = useSearchParams()
  const navigate = useNavigate()
  const { aviso } = useToast()
  const { confirm } = useConfirm()
  const hoy = aIso(new Date())
  // En el móvil se entra por la Agenda (la rejilla de 7 columnas no cabe cómoda).
  const [movil] = useState(() => typeof window !== 'undefined' && window.matchMedia?.('(max-width: 639px)').matches)
  const vista = leerVista(params.get('view'), movil ? 'agenda' : 'mes')
  const pd = params.get('d')
  const d = esIso(pd) ? pd : hoy
  const equipo = useMemo(() => leerEquipo(params.get('team')), [params])
  const rango = useMemo(() => rangoVista(vista, d), [vista, d])
  const q = useCalendario(rango.desde, rango.hasta, equipo)
  const datos = q.data

  const [soloTareas, setSoloTareas] = useState(() => leerLocal(CLAVE_SOLO_TAREAS))
  const [conectando, setConectando] = useState(false)
  const [pop, setPop] = useState<{ ev: EventoCal; anchor: AnclaPopover } | null>(null)
  const [borrador, setBorrador] = useState<Borrador | null>(null)
  const cm = useContextMenu<MenuDato>()
  const serie = useDialogoSerie()
  const borrar = useBorrarEvento()
  const mover = useMoverEvento()

  const google = datos?.google
  const conGoogle = !!google?.conectado

  const ir = useCallback(
    (v: Vista, fecha: string, team: number[] = equipo) => {
      const p = new URLSearchParams()
      p.set('view', v)
      p.set('d', fecha)
      if (team.length) p.set('team', team.join(','))
      setParams(p)
    },
    [equipo, setParams],
  )

  /* Vuelta del OAuth de Google: aviso una vez y se limpia la URL. */
  const vuelta = params.get('google')
  const msgVuelta = params.get('msg')
  useEffect(() => {
    if (!vuelta) return
    if (vuelta === 'ok') aviso('Google Calendar conectado ✓')
    else if (vuelta === 'cancelado') aviso('Has cancelado la conexión con Google.', { tipo: 'plain' })
    else aviso(msgVuelta || 'No se pudo conectar con Google', { tipo: 'error' })
    const p = new URLSearchParams(params)
    p.delete('google')
    p.delete('msg')
    setParams(p, { replace: true })
  }, [vuelta, msgVuelta, aviso, params, setParams])

  const porDia = useMemo(() => repartirPorDia(datos?.eventos ?? [], datos?.tareas ?? [], rango.dias), [datos, rango.dias])

  async function conectar() {
    setConectando(true)
    try {
      window.location.href = await urlConectarGoogle('/calendario')
    } catch (e) {
      aviso(mensaje(e, 'No se pudo conectar con Google'), { tipo: 'error' })
      setConectando(false)
    }
  }

  function nuevo(p: Preset) {
    if (!conGoogle) return
    setPop(null)
    setBorrador(borradorNuevo(p))
  }

  /* Eventos repetidos: «Solo este evento» (su id) o «Toda la serie» (serie_id). */
  async function idSegunSerie(ev: EventoCal, titulo: string) {
    if (!ev.recurrente || !ev.serie_id) return ev.id
    const r = await serie.preguntar(titulo)
    return r === null ? null : r === 'serie' ? ev.serie_id : ev.id
  }

  async function editar(ev: EventoCal) {
    setPop(null)
    const id = await idSegunSerie(ev, 'Editar evento repetido')
    if (id) setBorrador(borradorDeEvento(ev, id))
  }

  function duplicar(ev: EventoCal) {
    setPop(null)
    setBorrador(borradorDeEvento(ev, ''))
  }

  async function eliminar(ev: EventoCal) {
    setPop(null)
    const id = await idSegunSerie(ev, 'Eliminar evento repetido')
    if (!id) return
    const esSerie = id !== ev.id
    const ok = await confirm({
      title: esSerie ? 'Eliminar serie' : 'Eliminar evento',
      message: esSerie ? `¿Eliminar TODA la serie «${ev.titulo}»?` : `¿Eliminar «${ev.titulo}» de Google Calendar?`,
      danger: true,
    })
    if (!ok) return
    try {
      await borrar.mutateAsync(id)
      aviso('Evento eliminado')
    } catch (e) {
      aviso(mensaje(e, 'No se pudo eliminar'), { tipo: 'error' })
    }
  }

  async function moverEvento(ev: EventoCal, mov: Omit<Movimiento, 'id'>) {
    const id = await idSegunSerie(ev, 'Mover evento repetido')
    if (!id) return
    mover.mutate(
      { mov: { id, ...mov, origen: id !== ev.id ? { fecha: ev.dia, hora: ev.todo_el_dia ? '' : ev.hora, hora_fin: ev.todo_el_dia ? '' : ev.hora_fin, fecha_fin: ev.dia_fin } : undefined }, local: ev.id },
      {
        onSuccess: () => aviso('Evento movido'),
        onError: (e) => aviso(mensaje(e, 'No se pudo mover'), { tipo: 'error' }),
      },
    )
  }

  const acciones: AccionesVista = {
    conGoogle,
    onNuevo: (fecha, hora) => nuevo({ fecha, hora }),
    onMenuHueco: (e, fecha, hora) => cm.onContextMenu(e, { tipo: 'hueco', fecha, hora }),
    onEvento: (ev, el) => setPop({ ev, anchor: { current: el } }),
    onMenuEvento: (e: MouseEvent, ev) => cm.onContextMenu(e, { tipo: 'evento', ev }),
    onTarea: (id) => navigate(`/tareas/${id}`),
    onVerDia: (fecha) => ir('dia', fecha),
  }

  useHotkeys(
    {
      n: (e) => {
        if (!conGoogle) return
        e.preventDefault()
        nuevo({ fecha: d })
      },
      m: () => ir('mes', d),
      s: () => ir('semana', d),
      d: () => ir('dia', d),
      a: () => ir('agenda', d),
      t: () => ir(vista, hoy),
      arrowleft: () => ir(vista, navegar(vista, d, -1)),
      arrowright: () => ir(vista, navegar(vista, d, 1)),
    },
    { enabled: !borrador && serie.dialogo.titulo === null && !pop && !cm.open },
  )

  if (q.isPending) return <Cargando />
  if (q.isError && !datos) {
    return (
      <Notice tone="error" action={<Button variant="ghost" size="sm" onClick={() => void q.refetch()}>Reintentar</Button>}>
        {mensaje(q.error, 'No se ha podido cargar el calendario.')}
      </Notice>
    )
  }
  if (!datos || !google) return null

  if (!google.conectado && !soloTareas) {
    return (
      <Bienvenida
        configurado={google.configurado}
        puedeConfigurar={datos.puede_configurar}
        conectando={conectando}
        onConectar={() => void conectar()}
        onSoloTareas={() => {
          guardarLocal(CLAVE_SOLO_TAREAS)
          setSoloTareas(true)
        }}
      />
    )
  }

  const menu = cm.dato

  return (
    <div className="min-w-0">
      {google.revocado && (
        <div role="alert" className="mb-4 flex items-center gap-2.5 rounded-xl border border-[#f7c9c9] bg-[#fdecec] px-4 py-[11px] text-[13px] font-semibold text-[#c0343a] dark:border-danger-line dark:bg-danger-bg dark:text-danger">
          <AlertTriangle className="size-4 shrink-0" />
          <span className="min-w-0 flex-1">Tu conexión con Google ha caducado.</span>
          <button
            type="button"
            onClick={() => void conectar()}
            disabled={conectando}
            className="shrink-0 rounded-lg bg-[#c0343a] px-3.5 py-1.5 font-bold text-white transition-[filter] hover:brightness-110 disabled:opacity-60"
          >
            Reconectar
          </button>
        </div>
      )}
      {[...new Set(datos.avisos)].map((a) => (
        <Notice key={a} tone="warn">
          {a}
        </Notice>
      ))}

      {/* Cabecera */}
      <div className="mb-[18px] flex flex-wrap items-center gap-3 max-sm:gap-2.5">
        {conGoogle && (
          <span title={`Google Calendar${google.email ? ` · ${google.email}` : ''}`} className="max-sm:hidden">
            <CajaLogo size={52}>
              <LogoGcal size={36} />
            </CajaLogo>
          </span>
        )}
        <h1 className="min-w-[160px] text-[24px] font-semibold tracking-[-.3px] text-ink-strong max-sm:min-w-0 max-sm:flex-1 max-sm:text-[20px]">{tituloVista(vista, d)}</h1>
        <div className="flex items-center gap-1">
          <BotonNav label="Anterior" onClick={() => ir(vista, navegar(vista, d, -1))}>
            <ChevronLeft className="size-4" />
          </BotonNav>
          <BotonNav label="Siguiente" onClick={() => ir(vista, navegar(vista, d, 1))}>
            <ChevronRight className="size-4" />
          </BotonNav>
        </div>
        <button
          type="button"
          onClick={() => ir(vista, hoy)}
          className="h-[34px] rounded-[9px] border border-line bg-card px-3.5 text-[12.5px] font-semibold text-[#4c515b] transition-colors hover:bg-soft dark:text-ink"
        >
          Hoy
        </button>
        <Segmented
          items={SEGMENTOS}
          value={vista}
          onChange={(v) => ir(v, d)}
          aria-label="Vista"
          className="!gap-0.5 !rounded-[10px] !p-[3px] [&_[data-valor]]:!rounded-[7px] [&_[data-valor]]:!px-[13px] [&_[data-valor]]:!py-1.5 [&_[data-valor]]:!text-[12.5px] max-sm:w-full max-sm:[&_[data-valor]]:flex-1 max-sm:[&_[data-valor]]:justify-center"
        />
        {conGoogle ? (
          <Button size="sm" icon={<Plus />} onClick={() => nuevo({ fecha: d })} title="Nuevo evento (N)" className="ml-auto max-sm:hidden">
            Nuevo evento
          </Button>
        ) : google.configurado ? (
          <Button variant="ghost" size="sm" icon={<LogoGcal size={16} />} onClick={() => void conectar()} loading={conectando} className="ml-auto">
            Conectar Google Calendar
          </Button>
        ) : datos.puede_configurar ? (
          <Button variant="ghost" size="sm" icon={<LogoGcal size={16} />} to="/ajustes/integraciones?i=calendar" className="ml-auto">
            Configurar Google Calendar
          </Button>
        ) : null}
      </div>

      {/* Ver también (agendas de compañeros con Google) y leyenda */}
      <div className="-mt-1 mb-4 flex flex-wrap items-center gap-x-4 gap-y-2.5">
        {conGoogle && datos.companeros.length > 0 && (
          <div className="flex flex-wrap items-center gap-2">
            <span className="inline-flex items-center gap-[5px] text-[12px] font-semibold text-muted">
              <UserRound className="size-3.5" /> Ver también:
            </span>
            {datos.companeros.map((c) => {
              const on = equipo.includes(c.id)
              return (
                <button
                  key={c.id}
                  type="button"
                  aria-pressed={on}
                  onClick={() => ir(vista, d, alternarEquipo(equipo, c.id))}
                  className={`inline-flex items-center gap-1.5 rounded-full border px-[11px] py-[5px] text-[12.5px] font-semibold transition-colors hover:bg-soft max-sm:min-h-[34px] ${
                    on ? 'border-[#d5d7db] bg-[#fbfbfc] text-ink dark:border-line-strong dark:bg-soft' : 'border-line bg-card text-[#6b7280] dark:text-muted'
                  }`}
                >
                  <span className="size-[9px] shrink-0 rounded-full" style={{ backgroundColor: on ? c.color : '#c8ccd2' }} aria-hidden="true" />
                  {c.username}
                </button>
              )
            })}
          </div>
        )}
        <div className="ml-auto flex flex-wrap items-center gap-x-[13px] gap-y-1 max-sm:ml-0">
          {ORDEN_ESTADOS.map((k) => (
            <Leyenda key={k} color={ESTADOS_VIVOS[k].color} texto={ESTADOS_VIVOS[k].label} />
          ))}
          {conGoogle && <Leyenda color={COLOR_GOOGLE} texto="Google" />}
        </div>
      </div>

      <div className={`transition-opacity ${q.isFetching && q.isPlaceholderData ? 'opacity-60' : ''}`}>
        {vista === 'mes' ? (
          <VistaMes d={d} hoy={hoy} porDia={porDia} festivos={datos.festivos} acciones={acciones} onMoverADia={(ev, fecha) => void moverEvento(ev, moverADia(ev, fecha))} />
        ) : vista === 'agenda' ? (
          <VistaAgenda dias={rango.dias} hoy={hoy} porDia={porDia} festivos={datos.festivos} acciones={acciones} />
        ) : (
          <VistaHoras dias={rango.dias} hoy={hoy} porDia={porDia} festivos={datos.festivos} acciones={acciones} onMover={(ev, dia, ini, fin) => void moverEvento(ev, moverEnRejilla(dia, ini, fin))} />
        )}
      </div>

      {/* Botón flotante en el móvil (la cabecera no tiene sitio) */}
      {conGoogle && (
        <button
          type="button"
          onClick={() => nuevo({ fecha: d })}
          aria-label="Nuevo evento"
          className="fixed right-4 bottom-5 z-30 hidden size-[52px] items-center justify-center rounded-full bg-accent text-accent-fg shadow-btn max-sm:flex"
        >
          <Plus className="size-6" />
        </button>
      )}

      <PopoverEvento ev={pop?.ev ?? null} anchor={pop?.anchor ?? null} onClose={() => setPop(null)} onEditar={(ev) => void editar(ev)} onEliminar={(ev) => void eliminar(ev)} />

      <MenuPanel {...cm.panel} label="Acciones del calendario">
        {menu?.tipo === 'hueco' && (
          <>
            <MenuItem icon={<Plus />} onSelect={() => nuevo({ fecha: menu.fecha, hora: menu.hora })}>
              Nuevo evento
            </MenuItem>
            <MenuItem icon={<Video />} onSelect={() => nuevo({ fecha: menu.fecha, hora: menu.hora ?? '10:00', meet: true })}>
              Nueva reunión (Meet)
            </MenuItem>
          </>
        )}
        {menu?.tipo === 'evento' && (
          <>
            {menu.ev.editable && (
              <>
                <MenuItem icon={<Pencil />} onSelect={() => void editar(menu.ev)}>
                  Editar
                </MenuItem>
                <MenuItem icon={<Copy />} onSelect={() => duplicar(menu.ev)}>
                  Duplicar
                </MenuItem>
                <MenuSeparator />
                <MenuItem icon={<Trash2 />} danger onSelect={() => void eliminar(menu.ev)}>
                  Eliminar
                </MenuItem>
                <MenuSeparator />
              </>
            )}
            <MenuItem icon={<ExternalLink />} disabled={!menu.ev.link} onSelect={() => menu.ev.link && window.open(menu.ev.link, '_blank', 'noopener')}>
              Ver en Google
            </MenuItem>
          </>
        )}
      </MenuPanel>

      <ModalEvento inicial={borrador} onClose={() => setBorrador(null)} />
      <DialogoSerie {...serie.dialogo} />
    </div>
  )
}

function BotonNav({ label, onClick, children }: { label: string; onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      title={`${label} (${label === 'Anterior' ? '←' : '→'})`}
      className="flex size-[34px] items-center justify-center rounded-[9px] border border-line bg-card text-[#6b7280] transition-colors hover:bg-soft hover:text-ink dark:text-muted"
    >
      {children}
    </button>
  )
}

function Leyenda({ color, texto }: { color: string; texto: string }) {
  return (
    <span className="inline-flex items-center gap-1.5 text-[11.5px] text-muted">
      <span className="size-[9px] rounded-[3px]" style={{ backgroundColor: color }} aria-hidden="true" />
      {texto}
    </span>
  )
}
