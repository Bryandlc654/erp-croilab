import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AtSign, Bell, BriefcaseBusiness, CheckCheck, Clock, FileText, Inbox, MessageCircle, RotateCcw, Ticket, Trash2, Undo2, X } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import Checkbox from '../../../shared/ui/Checkbox'
import EmptyState from '../../../shared/ui/EmptyState'
import EstadoCirculo from '../../../shared/ui/EstadoCirculo'
import Menu, { MenuItem } from '../../../shared/ui/Menu'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { rutaDesdeLegado } from '../../../shared/lib/rutas'
import { useAccionesAvisos, useBandeja, type AccionAviso } from '../api'
import { agruparPorFecha, horaAviso, pospuestoHasta } from '../logica'
import type { Aviso, AvisosPagina, Bandeja } from '../schemas'

const PESTANAS: { b: Bandeja; t: string; s: string; color: string }[] = [
  { b: 'principal', t: 'Principal', s: 'para ti', color: '#ef4444' },
  { b: 'otras', t: 'Otras', s: 'del equipo', color: '#7b8794' },
  { b: 'chat', t: 'Chat', s: 'equipo', color: '#12a150' },
  { b: 'tarde', t: 'Más tarde', s: 'pospuestas', color: '#e0a000' },
  { b: 'papelera', t: 'Borradas', s: '', color: '#7b8794' },
]

const VACIOS: Record<Bandeja, string> = {
  principal: 'Nada para ti por ahora.',
  otras: 'Sin actividad del equipo por ahora.',
  chat: 'No hay mensajes del chat de equipo.',
  tarde: 'No has pospuesto ninguna notificación.',
  papelera: 'La papelera está vacía.',
}

/* Color del círculo por tipo cuando el aviso no es de una tarea visible. */
const TIPOS: Record<string, string> = { tarea: '#3b82f6', lead: '#8b5cf6', factura: '#0ea5e9', ticket: '#f59e0b', tkreply: '#f59e0b', chat: '#12a150', mencion: '#5b5fc7' }
/* Icono dentro del círculo de los avisos que no son de una tarea. */
const ICONO_TIPO: Record<string, typeof Bell> = { chat: MessageCircle, mencion: AtSign, ticket: Ticket, tkreply: Ticket, factura: FileText, lead: BriefcaseBusiness }

const POSPONER = [
  { label: 'Mañana a las 9:00', horas: undefined },
  { label: 'Dentro de 3 horas', horas: 3 },
  { label: 'Dentro de 1 hora', horas: 1 },
]

/* Bandeja de entrada (notifications.php): pestañas con contadores, filas por
   día, acciones al pasar el ratón y modo selección para hacer en bloque. */
export default function NotificacionesPage() {
  const [bandeja, setBandeja] = useState<Bandeja>('principal')
  const consulta = useBandeja(bandeja)
  const acc = useAccionesAvisos()
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const navigate = useNavigate()
  const [seleccion, setSeleccion] = useState<Set<number> | null>(null)
  const items = useMemo(() => consulta.data?.pages.flatMap((p) => p.items) ?? [], [consulta.data])
  const contadores: AvisosPagina['contadores'] | undefined = consulta.data?.pages[0]?.contadores
  const grupos = useMemo(() => agruparPorFecha(items), [items])
  const enPapelera = bandeja === 'papelera'
  const sinNada = contadores ? Object.values(contadores).every((c) => c.total === 0) : false

  function cambiarBandeja(b: Bandeja) {
    setBandeja(b)
    setSeleccion(null)
  }

  function hacer(accion: AccionAviso, ids: number[], horas?: number, msg?: string) {
    acc.accion.mutate(
      { accion, ids, horas },
      {
        onSuccess: (r) => {
          setSeleccion(null)
          if (msg) aviso(ids.length > 1 ? `${r.cambiadas} ${msg.toLowerCase()}` : msg)
        },
      },
    )
  }

  function abrir(a: Aviso) {
    if (!a.leido) acc.accion.mutate({ accion: 'leer', ids: [a.id] })
    if (a.url) navigate(rutaDesdeLegado(a.url))
  }

  async function purgar(ids: number[]) {
    if (await confirm({ title: '¿Borrar definitivamente?', message: 'Ya no se podrán recuperar.', danger: true, okLabel: 'Borrar' })) hacer('purgar', ids, undefined, 'Borradas definitivamente')
  }

  const sel = seleccion ?? new Set<number>()
  const ids = [...sel]
  const todas = items.length > 0 && items.every((a) => sel.has(a.id))

  return (
    <div className="mx-auto max-w-[1400px]">
      <h1 className="mb-5 text-[26px] font-semibold tracking-[-.5px] text-ink-strong">Bandeja de entrada</h1>

      <div role="tablist" aria-label="Bandejas" className="mb-5 flex overflow-x-auto border-b border-line [scrollbar-width:none]">
        {PESTANAS.map((p) => {
          const c = contadores?.[p.b]
          const n = p.b === 'tarde' || p.b === 'papelera' ? (c?.total ?? 0) : (c?.no_leidas ?? 0)
          const on = bandeja === p.b
          return (
            <button
              key={p.b}
              role="tab"
              type="button"
              aria-selected={on}
              onClick={() => cambiarBandeja(p.b)}
              className={`mr-[26px] flex shrink-0 items-start gap-2 border-b-2 py-3 pr-[22px] text-left transition-colors max-sm:mr-3 max-sm:pr-2 ${on ? 'border-ink-strong' : 'border-transparent hover:border-line-strong'}`}
            >
              <span>
                <b className={`block text-[14px] font-semibold ${on ? 'text-ink-strong' : 'text-muted'}`}>{p.t}</b>
                <span className="block text-[11.5px] text-label">{p.b === 'papelera' ? `${c?.total ?? 0} en papelera` : p.s}</span>
              </span>
              {n > 0 && p.b !== 'papelera' && (
                <span className="mt-0.5 rounded-full px-1.5 text-[11px] font-bold text-white" style={{ backgroundColor: p.color }}>
                  {n > 99 ? '99+' : n}
                </span>
              )}
            </button>
          )
        })}
      </div>

      {/* Barra de acciones */}
      {items.length > 0 && (
        <div className="mb-4 flex flex-wrap items-center gap-2">
          {seleccion === null ? (
            <Button variant="ghost" size="sm" onClick={() => setSeleccion(new Set())}>
              Seleccionar
            </Button>
          ) : (
            <>
              <Checkbox checked={todas} indeterminate={sel.size > 0 && !todas} onChange={(v) => setSeleccion(v ? new Set(items.map((a) => a.id)) : new Set())} label="Todo" />
              <span className="mr-1 text-[12.5px] font-semibold text-muted">
                {sel.size} seleccionada{sel.size === 1 ? '' : 's'}
              </span>
              {sel.size > 0 && !enPapelera && (
                <>
                  <Button variant="ghost" size="sm" onClick={() => hacer('leer', ids, undefined, 'Marcadas como leídas')}>
                    Marcar leídas
                  </Button>
                  <Button variant="ghost" size="sm" onClick={() => hacer('no_leer', ids, undefined, 'Marcadas como no leídas')}>
                    No leídas
                  </Button>
                  {bandeja === 'tarde' ? (
                    <Button variant="ghost" size="sm" onClick={() => hacer('traer', ids, undefined, 'Traídas a Principal')}>
                      Traer ahora
                    </Button>
                  ) : (
                    <Button variant="ghost" size="sm" onClick={() => hacer('posponer', ids, undefined, 'Pospuestas a mañana 9:00')}>
                      Posponer
                    </Button>
                  )}
                  <Button variant="danger" size="sm" onClick={() => hacer('borrar', ids, undefined, 'Movidas a la papelera')}>
                    Borrar
                  </Button>
                </>
              )}
              {sel.size > 0 && enPapelera && (
                <>
                  <Button variant="ghost" size="sm" onClick={() => hacer('restaurar', ids, undefined, 'Restauradas')}>
                    Restaurar
                  </Button>
                  <Button variant="danger" size="sm" onClick={() => void purgar(ids)}>
                    Borrar definitivo
                  </Button>
                </>
              )}
              <Button variant="ghost" size="sm" onClick={() => setSeleccion(null)}>
                Cancelar
              </Button>
            </>
          )}
          <span className="flex-1" />
          {!enPapelera ? (
            <>
              <Button variant="ghost" size="sm" icon={<CheckCheck />} onClick={() => acc.leerTodas.mutate()}>
                Marcar todas leídas
              </Button>
              <Button
                variant="ghost"
                size="sm"
                icon={<Trash2 />}
                onClick={async () => {
                  if (await confirm({ title: '¿Enviar las leídas a la papelera?', message: 'Las que aún no has leído se quedan. Podrás recuperarlas desde «Borradas».', okLabel: 'Enviar' })) acc.leidasAPapelera.mutate()
                }}
              >
                Enviar leídas a papelera
              </Button>
            </>
          ) : (
            <Button
              variant="danger"
              size="sm"
              icon={<Trash2 />}
              onClick={async () => {
                if (await confirm({ title: '¿Vaciar la papelera?', message: 'Se borrarán definitivamente las notificaciones de la papelera. Esto no se puede deshacer.', danger: true, okLabel: 'Vaciar' })) acc.vaciar.mutate()
              }}
            >
              Vaciar papelera
            </Button>
          )}
        </div>
      )}

      {consulta.error && <p className="mb-4 text-[13px] text-[#b91c1c]">{consulta.error.message}</p>}
      {consulta.isPending && <div className="h-[200px] animate-pulse rounded-[14px] border border-line bg-head" aria-hidden="true" />}

      {!consulta.isPending && items.length === 0 && (
        <EmptyState icon={sinNada ? <Bell /> : <Inbox />} title={sinNada ? 'Todo al día' : VACIOS[bandeja]} text={sinNada ? 'No tienes notificaciones.' : undefined} />
      )}

      {grupos.map((g) => (
        <section key={g.titulo} aria-label={g.titulo} className="mt-5 first-of-type:mt-0">
          <h2 className="px-1 pb-2.5 text-[12px] font-[650] text-muted">{g.titulo}</h2>
          <ul className="overflow-hidden rounded-[14px] border border-line bg-page">
            {g.items.map((a) => (
              <Fila
                key={a.id}
                a={a}
                bandeja={bandeja}
                seleccionando={seleccion !== null}
                elegida={sel.has(a.id)}
                onElegir={(v) => setSeleccion((s) => {
                  const n = new Set(s ?? [])
                  if (v) n.add(a.id)
                  else n.delete(a.id)
                  return n
                })}
                onAbrir={() => abrir(a)}
                onAccion={(accion, horas, msg) => hacer(accion, [a.id], horas, msg)}
                onPurgar={() => void purgar([a.id])}
              />
            ))}
          </ul>
        </section>
      ))}

      {consulta.hasNextPage && (
        <div className="mt-4 flex justify-center">
          <Button variant="ghost" size="sm" onClick={() => void consulta.fetchNextPage()} loading={consulta.isFetchingNextPage}>
            Cargar más
          </Button>
        </div>
      )}
    </div>
  )
}

function Fila({
  a,
  bandeja,
  seleccionando,
  elegida,
  onElegir,
  onAbrir,
  onAccion,
  onPurgar,
}: {
  a: Aviso
  bandeja: Bandeja
  seleccionando: boolean
  elegida: boolean
  onElegir: (v: boolean) => void
  onAbrir: () => void
  onAccion: (accion: AccionAviso, horas?: number, msg?: string) => void
  onPurgar: () => void
}) {
  const conTarea = a.tarea !== ''
  const principal = conTarea ? a.tarea : a.titulo
  const linea = conTarea ? [a.titulo, a.cuerpo].filter(Boolean).join(' · ') : a.cuerpo
  const BOTON = 'flex size-7 items-center justify-center rounded-lg text-label transition-colors hover:bg-soft hover:text-ink'

  return (
    <li
      onClick={() => (seleccionando ? onElegir(!elegida) : onAbrir())}
      className={`group grid cursor-pointer grid-cols-[22px_minmax(150px,300px)_1fr_auto] items-center gap-4 border-b border-line px-[22px] py-[15px] transition-colors last:border-b-0 max-md:grid-cols-[22px_1fr_auto] max-md:gap-x-3 max-md:gap-y-1 max-md:px-4 ${
        elegida ? 'bg-accent-soft' : a.leido ? 'bg-soft/60 hover:bg-soft' : 'hover:bg-hover-row'
      }`}
    >
      <span className="flex justify-center" onClick={(e) => seleccionando && e.stopPropagation()}>
        {seleccionando ? (
          <Checkbox checked={elegida} onChange={onElegir} aria-label={`Elegir ${principal}`} />
        ) : a.tarea_estado ? (
          <EstadoCirculo estado={a.tarea_estado} />
        ) : ICONO_TIPO[a.tipo] ? (
          <IconoTipo tipo={a.tipo} />
        ) : (
          <span className="size-4 rounded-full border-2" style={{ borderColor: TIPOS[a.tipo] ?? '#b0b4bb' }} aria-hidden="true" />
        )}
      </span>
      <span className={`min-w-0 truncate text-[14px] text-ink-strong ${a.leido ? 'font-semibold' : 'font-bold'}`}>{principal}</span>
      <span className="flex min-w-0 items-center gap-2 text-[13px] text-muted max-md:col-start-2 max-md:row-start-2">
        {a.actor && <Avatar nombre={a.actor} foto={a.actor_foto} size={22} />}
        <span className="min-w-0 truncate">
          {a.actor && <b className="font-semibold text-ink">{a.actor} </b>}
          {linea}
        </span>
      </span>
      <span className="flex items-center justify-end gap-2.5 max-md:col-start-3 max-md:row-span-2 max-md:row-start-1" onClick={(e) => e.stopPropagation()}>
        {!seleccionando && (
          <span className="hidden items-center gap-0.5 md:group-hover:flex">
            {bandeja === 'papelera' ? (
              <>
                <button type="button" className={BOTON} title="Restaurar" aria-label="Restaurar" onClick={() => onAccion('restaurar', undefined, 'Restaurada')}>
                  <Undo2 className="size-4" />
                </button>
                <button type="button" className={`${BOTON} hover:!bg-[#fdecec] hover:!text-[#e5484d]`} title="Borrar definitivamente" aria-label="Borrar definitivamente" onClick={onPurgar}>
                  <Trash2 className="size-4" />
                </button>
              </>
            ) : (
              <>
                {bandeja === 'tarde' ? (
                  <button type="button" className={BOTON} title="Traer ahora" aria-label="Traer ahora" onClick={() => onAccion('traer', undefined, 'Traída a Principal')}>
                    <RotateCcw className="size-4" />
                  </button>
                ) : (
                  <Menu
                    label="Posponer"
                    align="right"
                    trigger={() => (
                      <span className={BOTON} title="Posponer">
                        <Clock className="size-4" />
                      </span>
                    )}
                  >
                    {(cerrar) =>
                      POSPONER.map((o) => (
                        <MenuItem
                          key={o.label}
                          onClick={() => {
                            cerrar()
                            onAccion('posponer', o.horas, o.horas ? 'Pospuesta' : 'Pospuesta a mañana 9:00')
                          }}
                        >
                          {o.label}
                        </MenuItem>
                      ))
                    }
                  </Menu>
                )}
                <button type="button" className={`${BOTON} hover:!bg-[#fdecec] hover:!text-[#e5484d]`} title="Borrar" aria-label="Borrar" onClick={() => onAccion('borrar', undefined, 'Movida a la papelera')}>
                  <X className="size-4" />
                </button>
              </>
            )}
          </span>
        )}
        {!a.leido && <span className="size-2 shrink-0 rounded-full bg-[#ef4444]" aria-label="Sin leer" />}
        {a.snooze_until && bandeja === 'tarde' && (
          <span className="shrink-0 rounded-full bg-[#fff7e6] px-2 py-0.5 text-[11.5px] font-semibold text-[#c08a00] dark:bg-[#2e2412] dark:text-warn">🕒 {pospuestoHasta(a.snooze_until)}</span>
        )}
        <span className="min-w-[52px] text-right text-[12px] text-label">{horaAviso(a.created_at)}</span>
      </span>
    </li>
  )
}

function IconoTipo({ tipo }: { tipo: string }) {
  const I = ICONO_TIPO[tipo] ?? Bell
  return (
    <span className="flex size-[18px] items-center justify-center rounded-full text-white" style={{ backgroundColor: TIPOS[tipo] ?? '#b0b4bb' }} aria-hidden="true">
      <I className="size-[11px]" strokeWidth={2.4} />
    </span>
  )
}
