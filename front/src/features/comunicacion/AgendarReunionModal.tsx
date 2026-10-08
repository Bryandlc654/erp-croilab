import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { CalendarClock, Copy, Mail, MessageCircle, Trash2 } from 'lucide-react'
import { api } from '../../shared/api/client'
import Button from '../../shared/ui/Button'
import { DateInput } from '../../shared/ui/DatePicker'
import Field from '../../shared/ui/Field'
import Modal, { ModalBody, ModalFooter } from '../../shared/ui/Modal'
import Notice from '../../shared/ui/Notice'
import Segmented from '../../shared/ui/Segmented'
import Select from '../../shared/ui/Select'
import Switch from '../../shared/ui/Switch'
import { TextArea, TextInput } from '../../shared/ui/TextInput'
import { useConfirm } from '../../shared/ui/useConfirm'
import { useToast } from '../../shared/ui/useToast'
import { clavesCom, mensaje, useAgendar, useDestinatario, type DatosAgendar } from './api'
import InvitadosInput from './components/InvitadosInput'
import { LogoGemini, LogoMeet } from './components/Logos'
import { enlaceWhatsapp, isoDia, sumarMinutos, textoReserva } from './logica'
import { EventoRespuesta, MsgRespuesta, type AgendarResultado, type Reunion, type Solicitud } from './schemas'

/* Para quién es la reunión cuando se abre desde otra pantalla (ficha de
   cliente, CRM…). Con `cli`/`contacto` el modal completa lo que falte. */
export type DestinatarioAgendar = { nombre?: string; email?: string; whatsapp?: string; contactId?: number | null; clientId?: number | null }

export type AgendarReunionModalProps = {
  open: boolean
  onClose: () => void
  /* Datos ya conocidos (nombre, correo, WhatsApp, contacto)… */
  destinatario?: DestinatarioAgendar
  /* …o ids para que los busque la API (/v1/reuniones/destinatario). */
  cli?: number
  contacto?: number
  /* Aprobar una solicitud del portal. */
  solicitud?: Solicitud | null
  /* Editar una reunión de Google ya creada. */
  evento?: Reunion | null
  /* Tras guardar (p. ej. para refrescar la ficha). */
  onHecho?: (r: AgendarResultado | null) => void
}

const DURACIONES = [
  { value: 30, label: '30 min' },
  { value: 60, label: '1 hora' },
  { value: 90, label: '1 h 30' },
  { value: 120, label: '2 horas' },
]
const RECORDATORIOS = [
  { value: '', label: 'Predeterminado de Google' },
  { value: '10', label: '10 min antes' },
  { value: '30', label: '30 min antes' },
  { value: '60', label: '1 hora antes' },
  { value: '120', label: '2 horas antes' },
  { value: '1440', label: '1 día antes' },
  { value: 'no', label: 'Sin recordatorio' },
]

/* «Agendar reunión» (erpAgendar + agendar.php del antiguo) reutilizable:
   «La agendo yo» crea la reunión en el CRM (si hay contacto) y en Google
   Calendar con Meet; «Que elija el cliente» le pasa tu enlace de reservas por
   correo o WhatsApp. También aprueba solicitudes del portal y edita reuniones.

     <AgendarReunionModal open={abierto} onClose={…} contacto={id} />
     <AgendarReunionModal open cli={clienteId} onHecho={() => refetch()} /> */
export default function AgendarReunionModal(props: AgendarReunionModalProps) {
  return props.open ? <Formulario {...props} /> : null
}

function minutosEntre(a: string, b: string) {
  const [h1, m1] = a.split(':').map(Number)
  const [h2, m2] = b.split(':').map(Number)
  return h2 * 60 + m2 - (h1 * 60 + m1)
}

function Formulario({ onClose, destinatario, cli = 0, contacto = 0, solicitud, evento, onHecho }: AgendarReunionModalProps) {
  const { aviso } = useToast()
  const { confirm } = useConfirm()
  const qc = useQueryClient()
  const agendar = useAgendar()
  const ctx = useDestinatario(cli, contacto)
  const d = ctx.data
  const nombre = destinatario?.nombre || d?.nombre || solicitud?.cliente || ''
  const email = destinatario?.email || d?.email || solicitud?.email || ''
  const whatsapp = destinatario?.whatsapp || d?.whatsapp || ''
  const contactId = destinatario?.contactId ?? d?.contact_id ?? null
  const manana = isoDia(new Date(Date.now() + 86_400_000))

  const [modo, setModo] = useState<'yo' | 'cliente'>('yo')
  const [titulo, setTitulo] = useState(evento?.titulo ?? (solicitud ? solicitud.motivo || 'Reunión solicitada por el cliente' : ''))
  const [fecha, setFecha] = useState<string | null>(evento?.dia ?? solicitud?.fecha_deseada ?? manana)
  const [hora, setHora] = useState(evento?.hora || '10:00')
  const [duracion, setDuracion] = useState(() => {
    if (!evento?.hora || !evento.hora_fin) return 60
    const m = minutosEntre(evento.hora, evento.hora_fin)
    return DURACIONES.some((x) => x.value === m) ? m : 60
  })
  const [invitados, setInvitados] = useState<string | null>(evento ? evento.invitados.join(', ') : null)
  const [recordar, setRecordar] = useState(evento ? evento.recordar : '30')
  const [meet, setMeet] = useState(evento ? evento.meet : true)
  const [notificar, setNotificar] = useState(true)
  const [gemini, setGemini] = useState(false)
  const [descripcion, setDescripcion] = useState(evento?.descripcion ?? '')
  const [errores, setErrores] = useState<{ titulo?: string; fecha?: string }>({})
  const [guardando, setGuardando] = useState(false)

  // El título y los invitados por defecto dependen de datos que pueden llegar después.
  const tituloFinal = titulo || (nombre ? `Reunión con ${nombre}` : '')
  const invitadosFinal = invitados ?? email
  const editar = !!evento
  const google = d?.google ?? true
  const enlace = d?.meeting_url ?? ''

  async function guardar() {
    const e: typeof errores = {}
    if (!tituloFinal.trim()) e.titulo = 'Escribe un título'
    if (!fecha) e.fecha = 'Elige una fecha'
    setErrores(e)
    if (e.titulo || e.fecha || !fecha) return
    setGuardando(true)
    try {
      if (evento) {
        await api('/api/v1/calendario/eventos', {
          method: 'PATCH',
          body: { id: evento.id, titulo: tituloFinal, fecha, hora, hora_fin: sumarMinutos(hora, duracion), invitados: invitadosFinal, meet, gemini, notificar, recordar, descripcion },
          schema: EventoRespuesta,
        })
        aviso('Reunión actualizada ✓')
        invalidar()
        onHecho?.(null)
      } else {
        const datos: DatosAgendar = { titulo: tituloFinal, fecha, hora, duracion, invitados: invitadosFinal, meet, gemini: meet && gemini, notificar, recordar, descripcion, contact_id: contactId, req_id: solicitud?.id ?? null }
        const r = await agendar.mutateAsync(datos)
        aviso(r.msg.startsWith('Reunión guardada,') || r.msg.startsWith('Solicitud aprobada.') ? r.msg : `${r.msg} ✓`, { tipo: r.msg.includes('aviso') ? 'error' : 'ok', ms: r.msg.length > 60 ? 6000 : undefined })
        onHecho?.(r)
      }
      onClose()
    } catch (err) {
      aviso(mensaje(err, 'No se ha podido guardar la reunión.'), { tipo: 'error' })
    } finally {
      setGuardando(false)
    }
  }

  function invalidar() {
    void qc.invalidateQueries({ queryKey: clavesCom.reunionesTodo })
    void qc.invalidateQueries({ queryKey: clavesCom.calendarioTodo })
  }

  async function borrar() {
    if (!evento) return
    const ok = await confirm({ title: 'Borrar reunión', message: `Se elimina «${evento.titulo}» de Google Calendar y se avisa a los invitados.`, okLabel: 'Borrar', danger: true })
    if (!ok) return
    try {
      await api(`/api/v1/calendario/eventos?id=${encodeURIComponent(evento.id)}`, { method: 'DELETE', schema: MsgRespuesta })
      aviso('Reunión eliminada')
      invalidar()
      onHecho?.(null)
      onClose()
    } catch (err) {
      aviso(mensaje(err), { tipo: 'error' })
    }
  }

  const textoInvitacion = textoReserva(nombre, enlace)
  const tituloModal = editar ? 'Editar reunión' : solicitud ? 'Aprobar y agendar reunión' : nombre ? `Agendar reunión con ${nombre}` : 'Crear reunión'
  return (
    <Modal open onClose={onClose} size="lg" align="top" title={tituloModal} subtitle={google ? 'Se crea en tu Google Calendar' : 'Se guarda en el CRM'} logo={<LogoMeet size={20} />}>
      <form
        className="contents"
        onSubmit={(e) => {
          e.preventDefault()
          void guardar()
        }}
        onKeyDown={(e) => {
          if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault()
            void guardar()
          }
        }}
      >
        <ModalBody>
          {!editar && !solicitud && (
            <Segmented
              value={modo}
              onChange={setModo}
              items={[
                { value: 'yo', label: 'La agendo yo' },
                { value: 'cliente', label: 'Que elija el cliente' },
              ]}
            />
          )}
          {modo === 'cliente' ? (
            enlace ? (
              <div className="space-y-3">
                <p className="text-[13px] leading-[1.55] text-muted">Pásale tu enlace de reservas de Google Calendar y que elija el hueco que mejor le venga.</p>
                <div className="flex items-center gap-2 rounded-[10px] border border-line bg-soft px-3 py-2.5">
                  <CalendarClock className="size-4 shrink-0 text-label" aria-hidden="true" />
                  <span className="min-w-0 flex-1 truncate font-mono text-[12.5px] text-ink">{enlace}</span>
                  <Button size="sm" variant="ghost" icon={<Copy />} onClick={() => void navigator.clipboard?.writeText(enlace).then(() => aviso('Enlace copiado'))}>
                    Copiar
                  </Button>
                </div>
                <div className="flex flex-wrap gap-2">
                  {email && (
                    <Button variant="ghost" icon={<Mail />} href={`mailto:${encodeURIComponent(email)}?subject=${encodeURIComponent('Agendemos nuestra reunión')}&body=${encodeURIComponent(textoInvitacion)}`}>
                      Enviar por email
                    </Button>
                  )}
                  {whatsapp && (
                    <Button variant="ghost" icon={<MessageCircle />} href={enlaceWhatsapp(whatsapp, textoInvitacion)} target="_blank" rel="noopener noreferrer">
                      Enviar por WhatsApp
                    </Button>
                  )}
                </div>
              </div>
            ) : (
              <Notice tone="warn">
                Aún no tienes enlace de reservas. Créalo en Google Calendar (Crear → Horario de citas) y pégalo en Ajustes › Portal › Contacto.
              </Notice>
            )
          ) : (
            <>
              {!google && (
                <Notice tone="info">
                  {contactId ? 'Google Calendar no está conectado: la reunión se guardará solo en el CRM.' : 'Conecta Google Calendar (en el Calendario) para crear la reunión con Meet e invitados.'}
                </Notice>
              )}
              <Field label="Título" required error={errores.titulo}>
                <TextInput autoFocus value={tituloFinal} maxLength={200} placeholder="Ej: Llamada con Cliente X" onChange={(e) => setTitulo(e.target.value)} />
              </Field>
              <div className="grid grid-cols-[1.3fr_1fr_1fr] gap-[15px] max-sm:grid-cols-2">
                <Field label="Fecha" required error={errores.fecha} className="max-sm:col-span-2">
                  <DateInput value={fecha} onChange={setFecha} />
                </Field>
                <Field label="Hora">
                  <TextInput type="time" step={300} value={hora} onChange={(e) => setHora(e.target.value || '10:00')} />
                </Field>
                <Field label="Duración">
                  <Select value={duracion} onChange={setDuracion} options={DURACIONES} />
                </Field>
              </div>
              <Field label="Invitados" hint="Correos separados por comas. Les llega la invitación de Google.">
                <InvitadosInput value={invitadosFinal} onChange={setInvitados} />
              </Field>
              {editar && (
                <Field label="Descripción">
                  <TextArea rows={3} value={descripcion} onChange={(e) => setDescripcion(e.target.value)} />
                </Field>
              )}
              <Field label="Recordatorio">
                <Select value={recordar} onChange={setRecordar} options={RECORDATORIOS} />
              </Field>
              <div className="space-y-1 rounded-xl border border-line px-3.5 py-1.5">
                <Switch checked={meet} onChange={setMeet} label={<span className="inline-flex items-center gap-2"><LogoMeet size={14} /> Añadir videollamada de Google Meet</span>} />
                <Switch checked={notificar} onChange={setNotificar} label="Avisar a los invitados por correo" />
                {meet && (
                  <Switch
                    checked={gemini}
                    onChange={setGemini}
                    label={<span className="inline-flex items-center gap-2"><LogoGemini size={14} /> Tomar notas con Gemini</span>}
                    description="Google no deja activarlo por API: se deja el recordatorio en la descripción."
                  />
                )}
              </div>
            </>
          )}
        </ModalBody>
        <ModalFooter className={editar ? '!justify-between' : ''}>
          {editar && (
            <Button variant="danger" icon={<Trash2 />} onClick={() => void borrar()}>
              Borrar
            </Button>
          )}
          <div className="flex flex-wrap items-center gap-2.5">
            <Button variant="ghost" onClick={onClose}>
              {modo === 'cliente' ? 'Cerrar' : 'Cancelar'}
            </Button>
            {modo === 'yo' && (
              <Button type="submit" loading={guardando} loadingText="Guardando…">
                {editar ? 'Guardar cambios' : solicitud ? 'Crear y avisar al cliente' : google ? 'Crear en Google Calendar' : 'Guardar reunión'}
              </Button>
            )}
          </div>
        </ModalFooter>
      </form>
    </Modal>
  )
}

