import { useNavigate, useSearchParams } from 'react-router-dom'
import { CheckCircle2, ExternalLink, PlayCircle, Plus, Ticket, Trash2, X } from 'lucide-react'
import { api } from '../../../shared/api/client'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import EmptyState from '../../../shared/ui/EmptyState'
import { MenuItem, MenuPanel } from '../../../shared/ui/Menu'
import Select from '../../../shared/ui/Select'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useContextMenu } from '../../../shared/ui/useContextMenu'
import { useToast } from '../../../shared/ui/useToast'
import { useAuth } from '../../auth/useAuth'
import { mensaje, useInvalidarSoporte, useTickets } from '../api'
import { actualizadoRelativo } from '../logica'
import { PapeleraRespuesta, RestaurarRespuesta, TicketRespuesta, type Ticket as TicketT } from '../schemas'
import { OPCIONES_ESTADO } from './estados'
import { NuevoTicketModal, PildoraEstado, TextoPrioridad } from './piezas'

const COLUMNAS = 'grid-cols-[minmax(0,1fr)_150px_120px_120px_96px]'

/* Tickets de soporte (support.php): KPIs por estado, tabla y alta.
   ?estado= filtra, ?cli=N filtra por cliente (y lo preselecciona al crear),
   ?nuevo=1 abre el alta. */
export default function SoportePage() {
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const estado = params.get('estado') ?? ''
  const cli = Number(params.get('cli') ?? 0) || 0
  const nuevo = params.get('nuevo') === '1' || params.get('new') === '1'
  const q = useTickets(estado, cli)
  const d = q.data

  function cambiar(cambios: Record<string, string | null>) {
    const p = new URLSearchParams(params)
    for (const [k, v] of Object.entries(cambios)) {
      if (v === null || v === '') p.delete(k)
      else p.set(k, v)
    }
    setParams(p, { replace: true })
  }

  if (!can('ver.soporte')) return <EmptyState icon={<Ticket />} title="Sin acceso" text="No tienes permiso para ver los tickets de soporte." />

  const puedeEditar = d?.puede_editar ?? can('general.editar')
  return (
    <div>
      <header className="mb-[26px] flex flex-wrap items-center justify-between gap-x-6 gap-y-4">
        <div className="flex min-w-0 flex-wrap items-center gap-3">
          <h1 className="text-[26px] leading-[1.2] font-semibold tracking-[-.5px] text-ink-strong max-sm:text-[23px]">Tickets de Soporte</h1>
          {d?.cliente && (
            <span className="inline-flex items-center gap-1 rounded-lg bg-[#eef2fb] py-[5px] pr-1.5 pl-[11px] text-[12px] font-semibold text-[#33507f] dark:bg-[#1b2333] dark:text-[#a9c1ea]">
              Cliente: {d.cliente.nombre}
              <button type="button" aria-label="Quitar el filtro de cliente" onClick={() => cambiar({ cli: null })} className="rounded p-0.5 hover:bg-black/5">
                <X className="size-3.5" />
              </button>
            </span>
          )}
        </div>
        <div className="flex flex-wrap items-center gap-2.5">
          <div className="w-[200px] max-sm:w-[170px]">
            <Select
              value={estado}
              onChange={(v) => cambiar({ estado: v })}
              aria-label="Filtrar por estado"
              options={[{ value: '', label: 'Todos los estados' }, ...OPCIONES_ESTADO.filter((o) => o.value !== 'cerrado').map((o) => (o.value === 'resuelto' ? { ...o, label: 'Resueltos y cerrados' } : o))]}
            />
          </div>
          {puedeEditar && (
            <Button icon={<Plus />} onClick={() => cambiar({ nuevo: '1' })}>
              Nuevo ticket
            </Button>
          )}
        </div>
      </header>

      <div className="mb-6 grid grid-cols-4 gap-4 max-[900px]:grid-cols-2 max-sm:gap-3">
        {(
          [
            ['abierto', 'Abiertos'],
            ['en_curso', 'En curso'],
            ['esperando', 'Esperando'],
            ['resuelto', 'Resueltos'],
          ] as const
        ).map(([k, label]) => (
          <button
            key={k}
            type="button"
            onClick={() => cambiar({ estado: estado === k ? null : k })}
            aria-pressed={estado === k}
            className={`rounded-[14px] border bg-card px-[22px] py-5 text-left transition-[transform,border-color,box-shadow] duration-150 hover:-translate-y-0.5 hover:shadow-card-hover max-sm:px-4 max-sm:py-4 ${estado === k ? 'border-accent' : 'border-line'}`}
          >
            <div className="text-[25px] leading-none font-[650] tracking-[-.5px] text-ink-strong tabular-nums">{d ? d.contadores[k] : '–'}</div>
            <div className="mt-3 text-[11.5px] font-semibold tracking-[.4px] text-muted uppercase">{label}</div>
          </button>
        ))}
      </div>

      {q.isError ? (
        <EmptyState title="No se han podido cargar los tickets" text={mensaje(q.error, 'Inténtalo de nuevo en un momento.')} actions={<Button variant="ghost" onClick={() => void q.refetch()}>Reintentar</Button>} />
      ) : (
        <Tabla items={d?.items} cargando={q.isPending} filtrado={estado !== ''} puedeEditar={puedeEditar} puedeResponder={d?.puede_responder ?? false} onNuevo={() => cambiar({ nuevo: '1' })} />
      )}

      <NuevoTicketModal open={nuevo && puedeEditar} cliente={cli} onClose={() => cambiar({ nuevo: null, new: null })} />
    </div>
  )
}

function Tabla({
  items,
  cargando,
  filtrado,
  puedeEditar,
  puedeResponder,
  onNuevo,
}: {
  items: TicketT[] | undefined
  cargando: boolean
  filtrado: boolean
  puedeEditar: boolean
  puedeResponder: boolean
  onNuevo: () => void
}) {
  const navegar = useNavigate()
  const cm = useContextMenu<TicketT>()
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const invalidar = useInvalidarSoporte()

  async function marcar(t: TicketT, estado: 'en_curso' | 'resuelto') {
    try {
      await api(`/api/v1/soporte/tickets/${t.id}`, { method: 'PATCH', body: { estado }, schema: TicketRespuesta })
      invalidar(t.id)
      aviso(estado === 'resuelto' ? 'Ticket resuelto' : 'Ticket en curso')
    } catch (e) {
      aviso(mensaje(e), { tipo: 'error' })
    }
  }

  async function borrar(t: TicketT) {
    const ok = await confirm({ title: '¿Eliminar el ticket?', message: 'Se borra el ticket con toda su conversación.', okLabel: 'Eliminar', danger: true })
    if (!ok) return
    try {
      const r = await api(`/api/v1/soporte/tickets/${t.id}`, { method: 'DELETE', schema: PapeleraRespuesta })
      invalidar(t.id)
      aviso(`Ticket «${t.asunto}» eliminado`, {
        accion: {
          label: 'Deshacer',
          fn: () => void api(`/api/v1/soporte/papelera/${r.papelera_id}/restaurar`, { method: 'POST', schema: RestaurarRespuesta }).then(() => invalidar(t.id)).catch((e: unknown) => aviso(mensaje(e), { tipo: 'error' })),
        },
        ms: 7000,
      })
    } catch (e) {
      aviso(mensaje(e), { tipo: 'error' })
    }
  }

  const t = cm.dato
  return (
    <div className="overflow-hidden rounded-2xl border border-line bg-card">
      <div className={`grid ${COLUMNAS} items-center gap-3 border-b border-line bg-head px-[22px] py-3.5 text-[11px] font-semibold tracking-[.5px] text-muted uppercase max-md:hidden`}>
        <span>Asunto</span>
        <span>Estado</span>
        <span>Prioridad</span>
        <span>Asignado</span>
        <span>Actualizado</span>
      </div>
      {cargando && !items ? (
        <div className="space-y-3 p-[22px]" aria-busy="true">
          {[0, 1, 2].map((i) => (
            <div key={i} className="h-9 animate-pulse rounded-lg bg-soft" />
          ))}
        </div>
      ) : !items?.length ? (
        filtrado ? (
          <EmptyState variant="inline" icon={<Ticket />} title="Sin tickets con este estado" text="Prueba a quitar el filtro de estado." />
        ) : (
          <EmptyState
            variant="inline"
            icon={<Ticket />}
            title="No hay tickets todavía"
            text="Cuando un cliente escriba desde su portal o crees un ticket, aparecerá aquí."
            actions={puedeEditar ? <Button icon={<Plus />} onClick={onNuevo}>Crear ticket</Button> : undefined}
          />
        )
      ) : (
        <ul>
          {items.map((tk) => (
            <li key={tk.id} className="border-b border-line last:border-b-0">
              <button
                type="button"
                onClick={() => navegar(`/soporte/${tk.id}`)}
                onContextMenu={(e) => cm.onContextMenu(e, tk)}
                className={`grid w-full ${COLUMNAS} items-center gap-3 px-[22px] py-[17px] text-left transition-colors hover:bg-hover-row max-md:grid-cols-[minmax(0,1fr)_auto] max-md:gap-y-2 max-md:px-4 max-md:py-3.5`}
              >
                <span className="min-w-0">
                  <span className="block truncate text-[14px] font-semibold text-ink-strong">{tk.asunto}</span>
                  <span className="mt-0.5 block truncate text-[12px] text-muted">
                    #{tk.id} · {tk.cliente ?? 'Sin cliente'}
                    {tk.desde_portal ? ' · desde el portal' : ''}
                    {tk.respuestas ? ` · ${tk.respuestas} respuesta${tk.respuestas === 1 ? '' : 's'}` : ''}
                  </span>
                </span>
                <span>
                  <PildoraEstado estado={tk.estado} />
                </span>
                <span className="max-md:hidden">
                  <TextoPrioridad prioridad={tk.prioridad} />
                </span>
                <span className="flex min-w-0 items-center gap-2 text-[12.5px] text-ink max-md:hidden">
                  {tk.asignado ? (
                    <>
                      <Avatar nombre={tk.asignado.username} foto={tk.asignado.foto} size={22} />
                      <span className="truncate">{tk.asignado.username}</span>
                    </>
                  ) : (
                    <span className="text-label">—</span>
                  )}
                </span>
                <span className="text-[12.5px] text-muted tabular-nums max-md:hidden" title={tk.actualizado}>
                  {actualizadoRelativo(tk.actualizado)}
                </span>
                <span className="col-span-2 hidden items-center gap-3 text-[12px] text-muted max-md:flex">
                  <TextoPrioridad prioridad={tk.prioridad} />
                  {tk.asignado && <span className="truncate">· {tk.asignado.username}</span>}
                  <span className="ml-auto tabular-nums">{actualizadoRelativo(tk.actualizado)}</span>
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
      <MenuPanel {...cm.panel} label="Acciones del ticket">
        {t && (
          <>
            <MenuItem icon={<ExternalLink />} onSelect={() => navegar(`/soporte/${t.id}`)}>
              Abrir ticket
            </MenuItem>
            {puedeResponder && t.estado !== 'en_curso' && (
              <MenuItem icon={<PlayCircle />} onSelect={() => void marcar(t, 'en_curso')}>
                Marcar en curso
              </MenuItem>
            )}
            {puedeResponder && t.estado !== 'resuelto' && (
              <MenuItem icon={<CheckCircle2 />} onSelect={() => void marcar(t, 'resuelto')}>
                Marcar resuelto
              </MenuItem>
            )}
            {puedeEditar && (
              <MenuItem icon={<Trash2 />} danger onSelect={() => void borrar(t)}>
                Eliminar
              </MenuItem>
            )}
          </>
        )}
      </MenuPanel>
    </div>
  )
}
