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
import { useAccionSeguimientos, useCatalogos } from '../api'
import ContactoPicker from './ContactoPicker'

/* «Seguimiento manual»: contacto, canal, qué hay que hacer y cuándo (hoy por defecto). */
export default function SeguimientoManualModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { data: cat } = useCatalogos()
  const acc = useAccionSeguimientos()
  const { aviso } = useToast()
  const [contacto, setContacto] = useState<number | null>(null)
  const [canal, setCanal] = useState('llamar')
  const [desc, setDesc] = useState('')
  const [fecha, setFecha] = useState<string | null>(isoDia(new Date()))
  const [error, setError] = useState<{ campo: string | null; msg: string } | null>(null)

  function cerrar() {
    setContacto(null)
    setCanal('llamar')
    setDesc('')
    setFecha(isoDia(new Date()))
    setError(null)
    onClose()
  }

  async function enviar(e: FormEvent) {
    e.preventDefault()
    if (!contacto) return setError({ campo: 'contact_id', msg: 'Elige un contacto.' })
    if (!desc.trim()) return setError({ campo: 'descripcion', msg: 'Escribe qué hay que hacer.' })
    try {
      await acc.crear.mutateAsync({ contact_id: contacto, canal, descripcion: desc.trim(), fecha: fecha ?? '' })
      aviso('Seguimiento creado')
      cerrar()
    } catch (x) {
      setError({ campo: x instanceof ApiError ? x.campo : null, msg: x instanceof ApiError ? x.message : 'No se ha podido crear.' })
    }
  }

  const err = (k: string) => (error?.campo === k ? error.msg : undefined)
  return (
    <Modal open={open} onClose={cerrar} title="Seguimiento manual" size="md">
      <form onSubmit={(e) => void enviar(e)} className="contents">
        <ModalBody>
          <Field label="Contacto" required error={err('contact_id')}>
            <ContactoPicker value={contacto} onChange={setContacto} invalid={!!err('contact_id')} />
          </Field>
          <Field label="Canal">
            <Select value={canal} onChange={setCanal} options={(cat?.canales ?? []).map((c) => ({ value: c.value, label: c.label, color: c.color }))} />
          </Field>
          <Field label="Descripción" required error={err('descripcion')}>
            <TextInput value={desc} onChange={(e) => setDesc(e.target.value)} placeholder="Qué hay que hacer" maxLength={255} />
          </Field>
          <Field label="Fecha" error={err('fecha')}>
            <DateInput value={fecha} onChange={setFecha} />
          </Field>
          {error && !error.campo && <p className="text-[12.5px] font-medium text-[#ef4444]">{error.msg}</p>}
        </ModalBody>
        <ModalFooter>
          <Button variant="ghost" onClick={cerrar}>
            Cancelar
          </Button>
          <Button type="submit" loading={acc.crear.isPending} loadingText="Creando…">
            Crear
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
