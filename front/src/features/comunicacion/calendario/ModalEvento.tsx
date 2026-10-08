import { useRef, useState } from 'react'
import { ApiError } from '../../../shared/api/client'
import Button from '../../../shared/ui/Button'
import { DateInput } from '../../../shared/ui/DatePicker'
import Field from '../../../shared/ui/Field'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import Select, { type OpcionSelect } from '../../../shared/ui/Select'
import Switch from '../../../shared/ui/Switch'
import { TextArea, TextInput } from '../../../shared/ui/TextInput'
import { useToast } from '../../../shared/ui/useToast'
import { mensaje } from '../api'
import InvitadosInput from '../components/InvitadosInput'
import { LogoGcal, LogoGemini, LogoMeet } from '../components/Logos'
import { cambiosEdicion, datosNuevo, type Borrador } from './borrador'
import { aMinutos, esIso, sumarHora } from './logicaCalendario'
import { useCrearEvento, useEditarEvento } from './mutaciones'

const REPETICION: OpcionSelect<string>[] = [
  { value: '', label: 'No se repite' },
  { value: 'DAILY', label: 'Cada día' },
  { value: 'WEEKLY', label: 'Cada semana' },
  { value: 'MONTHLY', label: 'Cada mes' },
]

const RECORDATORIO: OpcionSelect<string>[] = [
  { value: '', label: 'Predeterminado de Google' },
  { value: '10', label: '10 minutos antes' },
  { value: '30', label: '30 minutos antes' },
  { value: '60', label: '1 hora antes' },
  { value: '120', label: '2 horas antes' },
  { value: '1440', label: '1 día antes' },
  { value: 'no', label: 'Sin recordatorio' },
]

type Errores = Partial<Record<'titulo' | 'fecha' | 'hora' | 'hora_fin' | 'invitados' | 'recordar', string>>

/* Crear / editar un evento de MI Google Calendar (modal .gm del antiguo). Se
   monta al abrir, así el estado sale limpio de `inicial` cada vez. */
export default function ModalEvento({ inicial, onClose }: { inicial: Borrador | null; onClose: () => void }) {
  if (!inicial) return null
  return <Formulario inicial={inicial} onClose={onClose} />
}

function Formulario({ inicial, onClose }: { inicial: Borrador; onClose: () => void }) {
  const [b, setB] = useState(inicial)
  const [err, setErr] = useState<Errores>({})
  const titulo = useRef<HTMLInputElement>(null)
  const { aviso } = useToast()
  const crear = useCrearEvento()
  const editar = useEditarEvento()
  const editando = inicial.id !== ''
  const guardando = crear.isPending || editar.isPending
  // El Select de recordatorio no conoce valores raros que vengan de Google (p. ej. 15 min).
  const recordatorios = RECORDATORIO.some((o) => o.value === b.recordar) ? RECORDATORIO : [...RECORDATORIO, { value: b.recordar, label: `${b.recordar} minutos antes` }]

  const poner = <K extends keyof Borrador>(k: K, v: Borrador[K]) => {
    setB((x) => ({ ...x, [k]: v }))
    if (k in err) setErr((e) => ({ ...e, [k]: undefined }))
  }

  function cambiarInicio(h: string) {
    setB((x) => {
      // Al mover el inicio se conserva la duración (como Google).
      const dur = aMinutos(x.hora_fin) - aMinutos(x.hora)
      return { ...x, hora: h, hora_fin: h && dur > 0 ? sumarHora(h, dur) : x.hora_fin }
    })
  }

  async function guardar() {
    if (guardando) return
    if (!b.titulo.trim()) {
      setErr({ titulo: 'Escribe un título' })
      aviso('Escribe un título', { tipo: 'error' })
      titulo.current?.focus()
      return
    }
    if (!esIso(b.fecha)) {
      setErr({ fecha: 'Elige una fecha' })
      aviso('Elige una fecha', { tipo: 'error' })
      return
    }
    if (b.conHora && (!b.hora || !b.hora_fin)) {
      setErr({ hora: 'Pon la hora de inicio y de fin' })
      return
    }
    try {
      if (editando) {
        await editar.mutateAsync(cambiosEdicion(b, inicial))
        aviso('Evento actualizado ✓')
      } else {
        await crear.mutateAsync(datosNuevo(b))
        aviso('Evento creado ✓')
      }
      onClose()
    } catch (e) {
      if (e instanceof ApiError && e.campo && ['titulo', 'fecha', 'hora', 'hora_fin', 'invitados', 'recordar'].includes(e.campo)) {
        setErr({ [e.campo]: e.message })
      }
      aviso(mensaje(e, 'No se ha podido guardar el evento.'), { tipo: 'error' })
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title={editando ? 'Editar evento' : b.meet ? 'Nueva reunión' : 'Nuevo evento'}
      subtitle={
        <span className="inline-flex items-center gap-1.5">
          <LogoGcal size={12} /> Se guarda en tu Google Calendar
        </span>
      }
      logo={<LogoGcal size={26} />}
      initialFocus={titulo}
      closeOnMask={!guardando}
    >
      <div
        className="flex min-h-0 flex-1 flex-col"
        onKeyDown={(e) => {
          if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault()
            void guardar()
          }
        }}
      >
        <ModalBody className="!gap-[13px]">
          <Field label="Título" error={err.titulo}>
            <TextInput ref={titulo} value={b.titulo} onChange={(e) => poner('titulo', e.target.value)} placeholder="Ej: Reunión de equipo" autoComplete="off" maxLength={300} />
          </Field>

          <div className="flex gap-2.5 max-sm:flex-col max-sm:gap-[13px]">
            <Field label="Fecha" error={err.fecha ?? err.hora ?? err.hora_fin} className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <DateInput value={b.fecha} onChange={(v) => poner('fecha', v ?? '')} className="!w-[130px]" aria-label="Fecha" />
                {b.conHora && (
                  <span className="inline-flex items-center gap-1.5">
                    <TextInput type="time" step={300} value={b.hora} onChange={(e) => cambiarInicio(e.target.value)} className="!w-[98px] !px-2" aria-label="Hora de inicio" />
                    <span className="text-muted">–</span>
                    <TextInput type="time" step={300} value={b.hora_fin} onChange={(e) => poner('hora_fin', e.target.value)} className="!w-[98px] !px-2" aria-label="Hora de fin" />
                  </span>
                )}
                <button
                  type="button"
                  onClick={() => poner('conHora', !b.conHora)}
                  className="rounded-lg px-1.5 py-1 text-[13px] font-semibold text-[#1a56db] hover:bg-[#eef4ff] dark:text-[#8ab4f8] dark:hover:bg-soft"
                >
                  {b.conHora ? 'Todo el día' : 'Añadir una hora'}
                </button>
              </div>
            </Field>
            {!b.recurrente && (
              <Field label="Repetición" className="w-[150px] shrink-0 max-sm:w-full">
                <Select value={b.recur} onChange={(v) => poner('recur', v)} options={REPETICION} />
              </Field>
            )}
          </div>

          <Field label={<>Invitados <span className="ml-1 font-normal text-label">correos separados por coma</span></>} error={err.invitados}>
            <InvitadosInput value={b.invitados} onChange={(v) => poner('invitados', v)} placeholder="cliente@empresa.com, otro@…" />
          </Field>

          <Field label="Ubicación">
            <TextInput value={b.ubicacion} onChange={(e) => poner('ubicacion', e.target.value)} placeholder="Sala, dirección o enlace" autoComplete="off" />
          </Field>

          <Field label="Descripción">
            <TextArea value={b.descripcion} onChange={(e) => poner('descripcion', e.target.value)} placeholder="Detalles del evento" rows={2} className="!min-h-[56px]" />
          </Field>

          <Field label={<>Recordatorio <span className="ml-1 font-normal text-label">el aviso que salta antes</span></>} error={err.recordar}>
            <Select value={b.recordar} onChange={(v) => poner('recordar', v)} options={recordatorios} />
          </Field>

          <div className="flex flex-col gap-1.5">
            {!editando && (
              <Switch rowVariant="box" checked={b.meet} onChange={(v) => setB((x) => ({ ...x, meet: v, gemini: v && x.gemini }))} logo={<LogoMeet size={22} />} label="Añadir videollamada de Google Meet" />
            )}
            <Switch
              rowVariant="box"
              checked={b.notificar}
              onChange={(v) => poner('notificar', v)}
              logo={<LogoGcal size={22} />}
              label="Avisar a los invitados por correo"
              description="Les llega la invitación de Google Calendar."
            />
            {!editando && b.meet && (
              <Switch
                rowVariant="box"
                checked={b.gemini}
                onChange={(v) => poner('gemini', v)}
                logo={<LogoGemini size={20} />}
                label="Tomar notas con Gemini"
                description="Deja un aviso para pulsar «Tomar notas» en la reunión de Meet."
              />
            )}
          </div>
        </ModalBody>
        <ModalFooter>
          <span className="mr-auto text-[11.5px] text-label max-sm:hidden">Ctrl + Enter para guardar</span>
          <Button variant="ghost" size="sm" onClick={onClose} disabled={guardando}>
            Cancelar
          </Button>
          <Button onClick={() => void guardar()} loading={guardando} loadingText="Guardando…">
            {editando ? 'Guardar cambios' : 'Guardar'}
          </Button>
        </ModalFooter>
      </div>
    </Modal>
  )
}
