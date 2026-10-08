import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, CalendarDays, CalendarPlus, MessageSquare, Send } from 'lucide-react'
import { ApiError } from '../../../../shared/api/client'
import { fechaLarga, horaRelativa, isoDia } from '../../../../shared/lib/formato'
import Modal, { ModalBody, ModalFooter } from '../../../../shared/ui/Modal'
import { useToast } from '../../../../shared/ui/useToast'
import { useEscribir, useSolicitarReunion, useTicket } from '../../api'
import { usePortal } from '../../contexto'
import type { Reunion } from '../../schemas'
import { ESTADO_REUNION, ESTADO_SOLICITUD, ESTADO_TICKET, FRANJAS } from '../../textos'
import { BotonP, CabeceraVista, PAvatar, Pastilla, Tarjeta, Vacio } from '../ui'

const MES3 = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']
const campo = 'w-full rounded-xl border border-(--p-line) bg-(--p-card) px-3.5 py-2.5 text-[14px] text-(--p-ink-strong) outline-none focus:border-(--p-muted) max-sm:text-[16px]'

/* ---------- Reuniones ---------- */

export function Reuniones() {
  const { datos: d } = usePortal()
  const [abierto, setAbierto] = useState(false)
  const hoy = isoDia(new Date())
  const proximas = d.reuniones.filter((r) => r.fecha && r.fecha >= hoy && r.estado !== 'cancelada' && r.estado !== 'realizada').reverse()
  const anteriores = d.reuniones.filter((r) => !proximas.includes(r))
  const pendientes = d.solicitudes.filter((s) => s.estado === 'pendiente')
  return (
    <div>
      <CabeceraVista
        titulo="Tus reuniones"
        sub="Aquí tienes tus reuniones con el equipo. ¿Necesitas hablar? Pide una nueva y te la confirmamos."
        accion={
          <BotonP icono={<CalendarPlus />} onClick={() => setAbierto(true)} disabled={!d.puede_enviar} title={d.puede_enviar ? undefined : 'Vista previa — solicitud desactivada'}>
            {d.puede_enviar ? 'Solicitar reunión' : 'Vista previa — solicitud desactivada'}
          </BotonP>
        }
      />
      {pendientes.length > 0 && (
        <section className="mb-5">
          <h3 className="mb-2 px-1 text-[12px] font-bold tracking-[.08em] text-(--p-muted) uppercase">Tus solicitudes</h3>
          <div className="flex flex-col gap-2.5">
            {pendientes.map((s) => {
              const e = ESTADO_SOLICITUD[s.estado] ?? ESTADO_SOLICITUD.pendiente
              return (
                <Tarjeta key={s.id} className="flex items-center gap-3.5 px-[18px] py-3.5">
                  <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-(--p-soft) text-(--p-muted)">
                    <CalendarDays className="size-5" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <b className="block truncate text-[15px] text-(--p-ink-strong)">{s.motivo}</b>
                    <span className="text-[12.5px] text-(--p-muted)">{s.fecha ? `${fechaLarga(s.fecha)}${s.franja ? ' · ' + s.franja : ''}` : s.franja || 'Sin día preferido'}</span>
                  </span>
                  <Pastilla color={e.color} solida>
                    {e.label}
                  </Pastilla>
                </Tarjeta>
              )
            })}
          </div>
        </section>
      )}
      <ListaReuniones titulo="Próximas" lista={proximas} vacio="No tienes reuniones programadas ahora mismo. Cuando agendemos una contigo, la verás aquí." />
      {anteriores.length > 0 && <ListaReuniones titulo="Anteriores" lista={anteriores} />}
      <SolicitudModal open={abierto} onClose={() => setAbierto(false)} />
    </div>
  )
}

function ListaReuniones({ titulo, lista, vacio }: { titulo: string; lista: Reunion[]; vacio?: string }) {
  return (
    <section className="mb-5">
      <h3 className="mb-2 px-1 text-[12px] font-bold tracking-[.08em] text-(--p-muted) uppercase">{titulo}</h3>
      {!lista.length ? (
        <Tarjeta className="px-6 py-6 text-center text-[14px] text-(--p-muted)">{vacio}</Tarjeta>
      ) : (
        <div className="flex flex-col gap-2.5">
          {lista.map((r) => {
            const e = ESTADO_REUNION[r.estado] ?? ESTADO_REUNION.agendada
            const f = r.fecha ? r.fecha.split('-') : null
            return (
              <Tarjeta key={r.id} className="flex items-center gap-3.5 px-[18px] py-3.5">
                <span className="flex size-12 shrink-0 flex-col items-center justify-center rounded-xl bg-(--p-soft) leading-none">
                  <span className="text-[10.5px] font-bold text-(--p-muted) uppercase">{f ? MES3[Number(f[1]) - 1] : '—'}</span>
                  <span className="text-[18px] font-extrabold text-(--p-ink-strong)">{f ? Number(f[2]) : ''}</span>
                </span>
                <span className="min-w-0 flex-1">
                  <b className="block truncate text-[15px] text-(--p-ink-strong)">{r.titulo || 'Reunión con tu equipo'}</b>
                  <span className="text-[12.5px] text-(--p-muted)">
                    {fechaLarga(r.fecha)}
                    {r.hora ? ` · ${r.hora}` : ''}
                  </span>
                </span>
                <Pastilla color={e.color}>{e.label}</Pastilla>
              </Tarjeta>
            )
          })}
        </div>
      )}
    </section>
  )
}

function SolicitudModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [fecha, setFecha] = useState('')
  const [franja, setFranja] = useState('Sin preferencia')
  const [motivo, setMotivo] = useState('')
  const [error, setError] = useState('')
  const m = useSolicitarReunion()
  const { aviso } = useToast()
  const enviar = async () => {
    if (!motivo.trim()) return setError('Cuéntanos brevemente el motivo.')
    try {
      await m.mutateAsync({ motivo: motivo.trim(), fecha, franja })
      aviso('¡Solicitud enviada! Te confirmaremos la reunión.', { tipo: 'ok' })
      setMotivo('')
      setFecha('')
      setError('')
      onClose()
    } catch (e) {
      setError(e instanceof ApiError && e.status !== 500 ? e.message : 'No se pudo enviar. Inténtalo de nuevo.')
    }
  }
  return (
    <Modal open={open} onClose={onClose} title="Solicitar una reunión" subtitle="Te la confirmamos nosotros">
      <ModalBody>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="text-[13px] font-medium text-(--p-ink)">
            Día que prefieres (opcional)
            <input type="date" min={isoDia(new Date())} value={fecha} onChange={(e) => setFecha(e.target.value)} className={`${campo} mt-1.5`} />
          </label>
          <label className="text-[13px] font-medium text-(--p-ink)">
            Franja (opcional)
            <select value={franja} onChange={(e) => setFranja(e.target.value)} className={`${campo} mt-1.5`}>
              {FRANJAS.map((f) => (
                <option key={f}>{f}</option>
              ))}
            </select>
          </label>
        </div>
        <label className="text-[13px] font-medium text-(--p-ink)">
          ¿De qué quieres hablar?
          <textarea
            rows={4}
            value={motivo}
            onChange={(e) => setMotivo(e.target.value)}
            placeholder="Ej. Revisar los resultados del mes y los próximos pasos"
            className={`${campo} mt-1.5 resize-y`}
            maxLength={2000}
          />
        </label>
        {error && <p className="text-[13px] font-medium text-(--p-red)">{error}</p>}
      </ModalBody>
      <ModalFooter>
        <BotonP variante="claro" onClick={onClose}>
          Cancelar
        </BotonP>
        <BotonP onClick={() => void enviar()} disabled={m.isPending} icono={<Send />}>
          Enviar solicitud
        </BotonP>
      </ModalFooter>
    </Modal>
  )
}

/* ---------- Soporte ---------- */

export function Soporte() {
  const { datos: d, ruta } = usePortal()
  const [params, setParams] = useSearchParams()
  const abierto = params.get('nuevo') === '1' && d.puede_enviar
  const abrir = (on: boolean) => setParams(on ? { nuevo: '1' } : {}, { replace: true })
  const boton = (
    <BotonP icono={<MessageSquare />} onClick={() => abrir(true)} disabled={!d.puede_enviar} title={d.puede_enviar ? undefined : 'Vista previa — envío desactivado'}>
      {d.puede_enviar ? 'Escríbenos' : 'Vista previa — envío desactivado'}
    </BotonP>
  )
  return (
    <div>
      <CabeceraVista titulo="Soporte" sub="Cuéntanos cualquier cosa —una duda, una petición, un problema— y te respondemos. Aquí ves tus mensajes y en qué estado están." accion={boton} />
      {!d.tickets.length ? (
        <Vacio icono={<MessageSquare />} titulo="Aquí verás tus conversaciones" accion={d.puede_enviar ? <BotonP onClick={() => abrir(true)}>Escribir a tu equipo</BotonP> : undefined}>
          Cuando nos escribas, verás aquí tus mensajes y nuestras respuestas.
        </Vacio>
      ) : (
        <>
          <h3 className="mb-2 px-1 text-[12px] font-bold tracking-[.08em] text-(--p-muted) uppercase">Tus mensajes</h3>
          <Tarjeta className="overflow-hidden">
            {d.tickets.map((t) => {
              const e = ESTADO_TICKET[t.estado] ?? ESTADO_TICKET.abierto
              return (
                <Link key={t.id} to={ruta('soporte', String(t.id))} className="flex items-center gap-3.5 border-b border-(--p-line) px-4 py-3.5 last:border-b-0 hover:bg-(--p-soft)">
                  <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-(--p-soft) text-(--p-muted)">
                    <MessageSquare className="size-5" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <b className="block truncate text-[14.5px] text-(--p-ink-strong)">{t.asunto}</b>
                    <span className="text-[12.5px] text-(--p-muted)">
                      {t.respuestas ? `${t.respuestas} respuesta${t.respuestas === 1 ? '' : 's'} de tu equipo` : 'Sin respuesta todavía'}
                    </span>
                  </span>
                  <Pastilla color={e.color}>{e.label}</Pastilla>
                </Link>
              )
            })}
          </Tarjeta>
        </>
      )}
      <EscribenosModal open={abierto} onClose={() => abrir(false)} />
    </div>
  )
}

function EscribenosModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { datos: d, ruta } = usePortal()
  const [asunto, setAsunto] = useState('')
  const [cuerpo, setCuerpo] = useState('')
  const [error, setError] = useState('')
  const m = useEscribir()
  const { aviso } = useToast()
  const nav = useNavigate()
  const enviar = async () => {
    if (!cuerpo.trim()) return setError('Escribe tu mensaje.')
    try {
      const r = await m.mutateAsync({ asunto: asunto.trim(), cuerpo: cuerpo.trim() })
      aviso('¡Mensaje enviado! Tu equipo te responderá en breve.', { tipo: 'ok' })
      setAsunto('')
      setCuerpo('')
      setError('')
      nav(ruta('soporte', String(r.ticket.id)), { replace: true })
    } catch (e) {
      setError(e instanceof ApiError && e.status !== 500 ? e.message : 'No se pudo enviar. Inténtalo de nuevo.')
    }
  }
  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Escríbenos"
      subtitle={
        <>
          Nos llega directamente a tu equipo de <b>{d.marca.name}</b>. Te respondemos por aquí o por email lo antes posible.
        </>
      }
    >
      <ModalBody>
        <label className="text-[13px] font-medium text-(--p-ink)">
          Asunto (opcional)
          <input value={asunto} onChange={(e) => setAsunto(e.target.value)} maxLength={120} className={`${campo} mt-1.5`} />
        </label>
        <label className="text-[13px] font-medium text-(--p-ink)">
          Tu mensaje
          <textarea rows={6} value={cuerpo} onChange={(e) => setCuerpo(e.target.value)} className={`${campo} mt-1.5 resize-y`} maxLength={10000} />
        </label>
        {error && <p className="text-[13px] font-medium text-(--p-red)">{error}</p>}
      </ModalBody>
      <ModalFooter>
        <BotonP variante="claro" onClick={onClose}>
          Cancelar
        </BotonP>
        <BotonP onClick={() => void enviar()} disabled={m.isPending} icono={<Send />}>
          Enviar
        </BotonP>
      </ModalFooter>
    </Modal>
  )
}

/* Un ticket con las respuestas del equipo (el antiguo no dejaba abrirlos). */
export function TicketDetalle() {
  const { origen, ruta, datos: d } = usePortal()
  const { ticket: idTxt } = useParams()
  const id = Number(idTxt)
  const q = useTicket(origen, id)
  const volver = (
    <Link to={ruta('soporte')} className="mb-4 inline-flex items-center gap-1.5 text-[13.5px] font-medium text-(--p-ink-strong)">
      <ArrowLeft className="size-4" /> Volver a soporte
    </Link>
  )
  if (q.isPending) return <div>{volver}</div>
  if (q.isError) return <div>{volver}<Vacio>No encontramos ese mensaje. Puede que ya no esté disponible.</Vacio></div>
  const t = q.data
  const e = ESTADO_TICKET[t.estado] ?? ESTADO_TICKET.abierto
  return (
    <div className="max-w-[820px]">
      {volver}
      <Tarjeta className="p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <h2 className="text-[19px] font-bold text-(--p-ink-strong)">{t.asunto}</h2>
          <Pastilla color={e.color}>{e.label}</Pastilla>
        </div>
        <p className="mt-1 text-[12.5px] text-(--p-muted)">Enviado {horaRelativa(t.fecha)}</p>
        <div className="mt-4 rounded-2xl bg-(--p-soft) px-4 py-3 text-[14px] whitespace-pre-wrap text-(--p-ink)">{t.cuerpo}</div>
      </Tarjeta>
      <h3 className="mt-5 mb-2 px-1 text-[12px] font-bold tracking-[.08em] text-(--p-muted) uppercase">Respuestas de tu equipo</h3>
      {!t.respuestas.length ? (
        <Tarjeta className="px-6 py-6 text-center text-[14px] text-(--p-muted)">Todavía no te hemos respondido. Te contestamos por aquí o por email lo antes posible.</Tarjeta>
      ) : (
        <div className="flex flex-col gap-2.5">
          {t.respuestas.map((r) => (
            <Tarjeta key={r.id} className="flex gap-3 p-4">
              <PAvatar nombre={r.autor?.nombre ?? d.marca.name} foto={r.autor?.foto} color={r.autor?.color || undefined} size={32} />
              <div className="min-w-0 flex-1">
                <p className="text-[13px]">
                  <b className="text-(--p-ink-strong)">{r.autor?.nombre ?? `Equipo de ${d.marca.name}`}</b> <span className="text-(--p-muted)">· {horaRelativa(r.fecha)}</span>
                </p>
                <p className="mt-1 text-[14px] whitespace-pre-wrap text-(--p-ink)">{r.cuerpo}</p>
              </div>
            </Tarjeta>
          ))}
        </div>
      )}
      {d.puede_enviar && (
        <p className="mt-4 text-[13px] text-(--p-muted)">
          ¿Algo más? <Link to={ruta('soporte') + '?nuevo=1'} className="font-semibold text-(--p-ink-strong) underline">Escríbenos otro mensaje</Link>.
        </p>
      )}
    </div>
  )
}
