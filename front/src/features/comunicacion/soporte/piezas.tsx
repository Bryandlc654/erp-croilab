import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../../../shared/api/client'
import Button from '../../../shared/ui/Button'
import Field from '../../../shared/ui/Field'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import Select, { type OpcionSelect } from '../../../shared/ui/Select'
import { OPCIONES_PRIORIDAD, tonoEstado, tonoPrioridad } from './estados'
import { TextArea, TextInput } from '../../../shared/ui/TextInput'
import { useToast } from '../../../shared/ui/useToast'
import { useEquipo } from '../../nav/api'
import { mensaje, useClientesSoporte, useInvalidarSoporte } from '../api'
import { TicketRespuesta } from '../schemas'

/* Pastilla de estado con punto (tabla y detalle). */
export function PildoraEstado({ estado }: { estado: string }) {
  const t = tonoEstado(estado)
  return (
    <span className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-[3px] text-[12px] font-semibold whitespace-nowrap" style={{ color: t.color, backgroundColor: `${t.color}1a` }}>
      <span className="size-1.5 rounded-full" style={{ backgroundColor: t.color }} aria-hidden="true" />
      {t.label}
    </span>
  )
}

export function TextoPrioridad({ prioridad }: { prioridad: number }) {
  const p = tonoPrioridad(prioridad)
  return (
    <span className="inline-flex items-center gap-1.5 text-[12.5px] font-semibold whitespace-nowrap" style={{ color: p.color }}>
      <span className="size-2 rounded-full" style={{ backgroundColor: p.color }} aria-hidden="true" />
      {p.label}
    </span>
  )
}

/* Modal «Nuevo ticket». `cliente` preselecciona (?cli=N). */
export function NuevoTicketModal({ open, onClose, cliente }: { open: boolean; onClose: () => void; cliente: number }) {
  return open ? <Formulario onClose={onClose} cliente={cliente} /> : null
}

function Formulario({ onClose, cliente }: { onClose: () => void; cliente: number }) {
  const { aviso } = useToast()
  const navegar = useNavigate()
  const invalidar = useInvalidarSoporte()
  const equipo = useEquipo()
  const clientes = useClientesSoporte()
  const [asunto, setAsunto] = useState('')
  const [cuerpo, setCuerpo] = useState('')
  const [prioridad, setPrioridad] = useState(2)
  const [asignado, setAsignado] = useState(0)
  const [cli, setCli] = useState(cliente)
  const [error, setError] = useState('')
  const [guardando, setGuardando] = useState(false)

  const personas = useMemo<OpcionSelect<number>[]>(() => [{ value: 0, label: 'Sin asignar' }, ...(equipo.data ?? []).map((p) => ({ value: p.id, label: p.username }))], [equipo.data])
  const opcionesCli = useMemo<OpcionSelect<number>[]>(() => [{ value: 0, label: 'Sin cliente' }, ...(clientes.data ?? []).map((c) => ({ value: c.id, label: c.nombre, hint: c.activo ? undefined : 'De baja' }))], [clientes.data])

  async function crear() {
    if (!asunto.trim()) {
      setError('Escribe un asunto.')
      return
    }
    setGuardando(true)
    try {
      const r = await api('/api/v1/soporte/tickets', { method: 'POST', body: { asunto, cuerpo, prioridad, assignee_id: asignado || null, client_id: cli || null }, schema: TicketRespuesta })
      invalidar()
      aviso('Ticket creado')
      onClose()
      navegar(`/soporte/${r.ticket.id}`)
    } catch (e) {
      aviso(mensaje(e, 'No se ha podido crear el ticket.'), { tipo: 'error' })
    } finally {
      setGuardando(false)
    }
  }

  return (
    <Modal open onClose={onClose} size="lg" title="Nuevo ticket" subtitle="Una incidencia o petición de un cliente">
      <form
        onSubmit={(e) => {
          e.preventDefault()
          void crear()
        }}
        className="contents"
      >
        <ModalBody>
          <Field label="Asunto" required error={error || undefined}>
            <TextInput autoFocus value={asunto} maxLength={200} placeholder="Resumen del problema" onChange={(e) => { setAsunto(e.target.value); setError('') }} />
          </Field>
          <Field label="Descripción">
            <TextArea rows={4} value={cuerpo} placeholder="Detalla la incidencia…" onChange={(e) => setCuerpo(e.target.value)} />
          </Field>
          <div className="grid grid-cols-2 gap-[15px] max-sm:grid-cols-1">
            <Field label="Prioridad">
              <Select value={prioridad} onChange={setPrioridad} options={OPCIONES_PRIORIDAD} />
            </Field>
            <Field label="Asignar a">
              <Select value={asignado} onChange={setAsignado} options={personas} searchable />
            </Field>
          </div>
          <Field label="Cliente" hint="Opcional">
            <Select value={cli} onChange={setCli} options={opcionesCli} searchable searchPlaceholder="Buscar cliente…" />
          </Field>
        </ModalBody>
        <ModalFooter>
          <Button variant="ghost" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" loading={guardando} loadingText="Creando…">
            Crear ticket
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
