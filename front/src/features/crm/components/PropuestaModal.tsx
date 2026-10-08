import { useState, type FormEvent } from 'react'
import Button from '../../../shared/ui/Button'
import DateInput from '../../../shared/ui/DatePicker'
import Field from '../../../shared/ui/Field'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import Select from '../../../shared/ui/Select'
import { TextInput } from '../../../shared/ui/TextInput'
import { useToast } from '../../../shared/ui/useToast'
import { isoDia } from '../../../shared/lib/formato'
import { ApiError } from '../../../shared/api/client'
import { useCatalogos } from '../api'

type Datos = { nombre: string; importe: string; estado: string; fecha_envio: string; url_archivo: string }

/* «Nueva propuesta»: el antiguo solo pedía nombre e importe con dos prompts;
   aquí también estado, fecha y enlace al documento. */
export default function PropuestaModal({ open, onClose, onCrear }: { open: boolean; onClose: () => void; onCrear: (d: Datos) => Promise<unknown> }) {
  const vacio = (): Datos => ({ nombre: '', importe: '', estado: 'enviada', fecha_envio: isoDia(new Date()), url_archivo: '' })
  const [d, setD] = useState<Datos>(vacio)
  const [error, setError] = useState<{ campo: string | null; msg: string } | null>(null)
  const [enviando, setEnviando] = useState(false)
  const { data: cat } = useCatalogos()
  const { aviso } = useToast()

  function cerrar() {
    setD(vacio())
    setError(null)
    onClose()
  }

  async function enviar(e: FormEvent) {
    e.preventDefault()
    setEnviando(true)
    try {
      await onCrear(d)
      aviso('Propuesta añadida')
      cerrar()
    } catch (x) {
      setError({ campo: x instanceof ApiError ? x.campo : null, msg: x instanceof ApiError ? x.message : 'No se ha podido guardar.' })
    } finally {
      setEnviando(false)
    }
  }

  const err = (k: string) => (error?.campo === k ? error.msg : undefined)
  return (
    <Modal open={open} onClose={cerrar} title="Nueva propuesta" size="md">
      <form onSubmit={(e) => void enviar(e)} className="contents">
        <ModalBody>
          <Field label="Nombre de la propuesta" error={err('nombre')}>
            <TextInput value={d.nombre} onChange={(e) => setD({ ...d, nombre: e.target.value })} placeholder="Propuesta" maxLength={200} autoFocus />
          </Field>
          <div className="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
            <Field label="Importe (€)" hint="Puedes dejarlo vacío si aún no lo sabes." error={err('importe')}>
              <TextInput value={d.importe} onChange={(e) => setD({ ...d, importe: e.target.value })} inputMode="decimal" placeholder="0" />
            </Field>
            <Field label="Estado">
              <Select value={d.estado} onChange={(v) => setD({ ...d, estado: v })} options={(cat?.estados_propuesta ?? []).map((x) => ({ value: x.value, label: x.label }))} />
            </Field>
            <Field label="Fecha de envío" error={err('fecha_envio')}>
              <DateInput value={d.fecha_envio || null} onChange={(v) => setD({ ...d, fecha_envio: v ?? '' })} />
            </Field>
            <Field label="Enlace al documento" error={err('url_archivo')}>
              <TextInput type="url" value={d.url_archivo} onChange={(e) => setD({ ...d, url_archivo: e.target.value })} placeholder="https://…" />
            </Field>
          </div>
          {error && !error.campo && <p className="text-[12.5px] font-medium text-[#ef4444]">{error.msg}</p>}
        </ModalBody>
        <ModalFooter>
          <Button variant="ghost" onClick={cerrar}>
            Cancelar
          </Button>
          <Button type="submit" loading={enviando} loadingText="Guardando…">
            Añadir propuesta
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
