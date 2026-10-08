import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Globe, Ticket, Trash2 } from 'lucide-react'
import { api } from '../../../shared/api/client'
import { horaRelativa } from '../../../shared/lib/formato'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import EmptyState from '../../../shared/ui/EmptyState'
import Select, { type OpcionSelect } from '../../../shared/ui/Select'
import { TextArea } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { useEquipo } from '../../nav/api'
import { mensaje, useClientesSoporte, useInvalidarSoporte, useTicket } from '../api'
import { PapeleraRespuesta, ResponderRespuesta, RestaurarRespuesta, TicketRespuesta, type TicketDetalle } from '../schemas'
import { OPCIONES_ESTADO, OPCIONES_PRIORIDAD } from './estados'
import { PildoraEstado } from './piezas'

/* Detalle de un ticket (support.php?t=): conversación, respuesta y propiedades
   que se guardan al cambiar (ahora con aviso si fallan). */
export default function TicketPage() {
  const id = Number(useParams().id ?? 0)
  const q = useTicket(id)
  if (q.isPending) return <div className="h-64 animate-pulse rounded-2xl bg-soft" aria-busy="true" />
  if (q.isError || !q.data)
    return (
      <EmptyState
        icon={<Ticket />}
        title="Ticket no encontrado"
        text={mensaje(q.error, 'Puede que se haya borrado o que no tengas acceso.')}
        actions={<Button variant="ghost" to="/soporte">Volver a tickets</Button>}
      />
    )
  return <Detalle d={q.data} />
}

function Detalle({ d }: { d: TicketDetalle }) {
  const t = d.ticket
  return (
    <div className="max-w-[1180px]">
      <Link to="/soporte" className="mb-5 inline-flex items-center gap-1.5 text-[13px] font-semibold text-muted hover:text-ink">
        <ArrowLeft className="size-4" /> Volver a tickets
      </Link>
      <div className="grid grid-cols-[minmax(0,1fr)_300px] items-start gap-5 max-[900px]:grid-cols-1">
        <section className="rounded-2xl border border-line bg-card px-[30px] py-7 max-[900px]:order-2 max-sm:px-4 max-sm:py-5">
          <div className="mb-2 flex flex-wrap items-center gap-2.5">
            <PildoraEstado estado={t.estado} />
            {t.desde_portal && (
              <span className="inline-flex items-center gap-1 rounded-md bg-chip px-2 py-[3px] text-[11px] font-semibold text-label">
                <Globe className="size-3" /> Desde el portal
              </span>
            )}
          </div>
          <h2 className="text-[22px] leading-[1.3] font-semibold tracking-[-.3px] text-ink-strong">{t.asunto}</h2>
          <p className="mt-1.5 text-[12.5px] text-muted">
            #{t.id} · Abierto por {t.creador?.username ?? (t.desde_portal ? 'el cliente' : '—')} · {horaRelativa(t.creado)}
            {t.cliente ? (
              <>
                {' · Cliente: '}
                <Link to={`/clientes/${t.client_id}`} className="font-semibold text-ink hover:underline">
                  {t.cliente}
                </Link>
              </>
            ) : null}
          </p>
          {t.cuerpo ? <p className="mt-5 text-[14px] leading-[1.65] whitespace-pre-wrap text-ink">{t.cuerpo}</p> : <p className="mt-5 text-[13.5px] text-label italic">Sin descripción.</p>}

          <h3 className="mt-8 mb-3 text-[11px] font-bold tracking-[.5px] text-muted uppercase">Respuestas · {d.respuestas.length}</h3>
          {d.respuestas.length === 0 ? (
            <p className="rounded-xl bg-soft px-4 py-3 text-[13px] text-muted">Sin respuestas todavía.</p>
          ) : (
            <ul className="space-y-4">
              {d.respuestas.map((r) => (
                <li key={r.id} className="flex gap-3">
                  <Avatar nombre={r.autor?.username ?? '?'} foto={r.autor?.foto} size={32} />
                  <div className="min-w-0 flex-1 rounded-xl border border-line bg-soft/60 px-4 py-3">
                    <div className="mb-1 flex items-baseline gap-2">
                      <span className="text-[13px] font-semibold text-ink-strong">{r.autor?.username ?? 'Equipo'}</span>
                      <span className="text-[11.5px] text-label" title={r.creado}>
                        {horaRelativa(r.creado)}
                      </span>
                    </div>
                    <p className="text-[13.5px] leading-[1.6] whitespace-pre-wrap text-ink">{r.cuerpo}</p>
                  </div>
                </li>
              ))}
            </ul>
          )}
          {d.puede_responder && <Responder id={t.id} />}
        </section>
        <Propiedades d={d} />
      </div>
    </div>
  )
}

function Responder({ id }: { id: number }) {
  const [texto, setTexto] = useState('')
  const [enviando, setEnviando] = useState(false)
  const { aviso } = useToast()
  const invalidar = useInvalidarSoporte()
  async function enviar() {
    if (!texto.trim()) return
    setEnviando(true)
    try {
      await api(`/api/v1/soporte/tickets/${id}/respuestas`, { method: 'POST', body: { cuerpo: texto }, schema: ResponderRespuesta })
      setTexto('')
      invalidar(id)
    } catch (e) {
      aviso(mensaje(e, 'No se ha podido enviar la respuesta.'), { tipo: 'error' })
    } finally {
      setEnviando(false)
    }
  }
  return (
    <div className="mt-6 border-t border-line pt-5">
      <TextArea
        rows={3}
        value={texto}
        aria-label="Escribe una respuesta"
        placeholder="Escribe una respuesta…"
        onChange={(e) => setTexto(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault()
            void enviar()
          }
        }}
      />
      <div className="mt-2.5 flex items-center justify-end gap-3">
        <span className="text-[11.5px] text-label max-sm:hidden">Ctrl+Intro para enviar</span>
        <Button onClick={() => void enviar()} loading={enviando} loadingText="Enviando…" disabled={!texto.trim()}>
          Responder
        </Button>
      </div>
    </div>
  )
}

function Propiedades({ d }: { d: TicketDetalle }) {
  const t = d.ticket
  const { aviso } = useToast()
  const { confirm } = useConfirm()
  const navegar = useNavigate()
  const invalidar = useInvalidarSoporte()
  const equipo = useEquipo()
  const clientes = useClientesSoporte(d.puede_editar)
  const [guardando, setGuardando] = useState('')

  const personas = useMemo<OpcionSelect<number>[]>(() => [{ value: 0, label: 'Sin asignar' }, ...(equipo.data ?? []).map((p) => ({ value: p.id, label: p.username }))], [equipo.data])
  const opcionesCli = useMemo<OpcionSelect<number>[]>(() => {
    const base = [{ value: 0, label: 'Sin cliente' }, ...(clientes.data ?? []).map((c) => ({ value: c.id, label: c.nombre }))]
    // El cliente actual puede no estar en la lista (fuera de tu alcance): que se vea igual.
    if (t.client_id && !base.some((o) => o.value === t.client_id)) base.push({ value: t.client_id, label: t.cliente ?? `#${t.client_id}` })
    return base
  }, [clientes.data, t.client_id, t.cliente])

  async function guardar(campo: 'estado' | 'prioridad' | 'assignee_id' | 'client_id', valor: string | number | null) {
    setGuardando(campo)
    try {
      await api(`/api/v1/soporte/tickets/${t.id}`, { method: 'PATCH', body: { [campo]: valor }, schema: TicketRespuesta })
      invalidar(t.id)
      aviso('Guardado')
    } catch (e) {
      aviso(mensaje(e), { tipo: 'error' })
    } finally {
      setGuardando('')
    }
  }

  async function borrar() {
    const ok = await confirm({ title: '¿Eliminar el ticket?', message: 'Se borra el ticket con toda su conversación.', okLabel: 'Eliminar', danger: true })
    if (!ok) return
    try {
      const r = await api(`/api/v1/soporte/tickets/${t.id}`, { method: 'DELETE', schema: PapeleraRespuesta })
      invalidar()
      navegar('/soporte')
      aviso(`Ticket «${t.asunto}» eliminado`, {
        ms: 7000,
        accion: {
          label: 'Deshacer',
          fn: () => void api(`/api/v1/soporte/papelera/${r.papelera_id}/restaurar`, { method: 'POST', schema: RestaurarRespuesta }).then(() => { invalidar(); navegar(`/soporte/${t.id}`) }),
        },
      })
    } catch (e) {
      aviso(mensaje(e), { tipo: 'error' })
    }
  }

  const fila = 'mb-4 last:mb-0'
  const etiqueta = 'mb-[7px] block text-[11px] font-bold tracking-[.5px] text-muted uppercase'
  return (
    <aside className="space-y-4 max-[900px]:order-1">
      <div className="rounded-[14px] border border-line bg-card px-[22px] py-[21px]">
        <h4 className="mb-4 text-[11px] font-bold tracking-[.5px] text-muted uppercase">Propiedades</h4>
        <div className={fila}>
          <span className={etiqueta}>Estado</span>
          <Select value={t.estado} onChange={(v) => void guardar('estado', v)} options={OPCIONES_ESTADO} disabled={!d.puede_responder || guardando === 'estado'} />
        </div>
        <div className={fila}>
          <span className={etiqueta}>Prioridad</span>
          <Select value={t.prioridad} onChange={(v) => void guardar('prioridad', v)} options={OPCIONES_PRIORIDAD} disabled={!d.puede_editar || guardando === 'prioridad'} />
        </div>
        <div className={fila}>
          <span className={etiqueta}>Asignado a</span>
          <Select value={t.asignado?.id ?? 0} onChange={(v) => void guardar('assignee_id', v || null)} options={personas} searchable disabled={!d.puede_editar || guardando === 'assignee_id'} />
        </div>
        <div className={fila}>
          <span className={etiqueta}>Cliente</span>
          <Select value={t.client_id ?? 0} onChange={(v) => void guardar('client_id', v || null)} options={opcionesCli} searchable searchPlaceholder="Buscar cliente…" disabled={!d.puede_editar || guardando === 'client_id'} />
        </div>
      </div>
      <div className="rounded-[14px] border border-line bg-card px-[22px] py-[17px] text-[12.5px] text-muted">
        <div className="flex justify-between gap-2">
          <span>Creado</span>
          <span className="text-ink" title={t.creado}>{horaRelativa(t.creado)}</span>
        </div>
        <div className="mt-1.5 flex justify-between gap-2">
          <span>Actualizado</span>
          <span className="text-ink" title={t.actualizado}>{horaRelativa(t.actualizado)}</span>
        </div>
      </div>
      {d.puede_editar && (
        <Button variant="danger" icon={<Trash2 />} onClick={() => void borrar()} className="w-full justify-center">
          Eliminar ticket
        </Button>
      )}
    </aside>
  )
}
