import { useState, type FormEvent } from 'react'
import Button from '../../../shared/ui/Button'
import DateInput from '../../../shared/ui/DatePicker'
import Field from '../../../shared/ui/Field'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import { TextInput } from '../../../shared/ui/TextInput'
import { useToast } from '../../../shared/ui/useToast'
import { ApiError } from '../../../shared/api/client'
import { useAccionNegocio } from '../api'
import ContactoPicker from './ContactoPicker'

/* «Nuevo negocio»: contacto obligatorio; nombre, valor y cierre opcionales
   (por defecto, el nombre y el valor del contacto). */
export default function NuevoNegocioModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [contacto, setContacto] = useState<number | null>(null)
  const [nombre, setNombre] = useState('')
  const [valor, setValor] = useState('')
  const [cierre, setCierre] = useState<string | null>(null)
  const [error, setError] = useState<{ campo: string | null; msg: string } | null>(null)
  const acc = useAccionNegocio()
  const { aviso } = useToast()

  function cerrar() {
    setContacto(null)
    setNombre('')
    setValor('')
    setCierre(null)
    setError(null)
    onClose()
  }

  async function enviar(e: FormEvent) {
    e.preventDefault()
    if (!contacto) return setError({ campo: 'contact_id', msg: 'Selecciona un contacto.' })
    try {
      await acc.crear.mutateAsync({ contact_id: contacto, nombre, valor, fecha_cierre_prevista: cierre })
      aviso('Negocio creado')
      cerrar()
    } catch (x) {
      setError({ campo: x instanceof ApiError ? x.campo : null, msg: x instanceof ApiError ? x.message : 'No se ha podido crear.' })
    }
  }

  const err = (k: string) => (error?.campo === k ? error.msg : undefined)
  return (
    <Modal open={open} onClose={cerrar} title="Nuevo negocio" size="md">
      <form onSubmit={(e) => void enviar(e)} className="contents">
        <ModalBody>
          <Field label="Contacto" required error={err('contact_id')}>
            <ContactoPicker value={contacto} onChange={setContacto} invalid={!!err('contact_id')} />
          </Field>
          <Field label="Nombre del negocio" error={err('nombre')}>
            <TextInput value={nombre} onChange={(e) => setNombre(e.target.value)} placeholder="(por defecto, el del contacto)" maxLength={200} />
          </Field>
          <div className="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
            <Field label="Valor (€)" error={err('valor')}>
              <TextInput value={valor} onChange={(e) => setValor(e.target.value)} inputMode="decimal" placeholder="0" />
            </Field>
            <Field label="Cierre previsto" error={err('fecha_cierre_prevista')}>
              <DateInput value={cierre} onChange={setCierre} />
            </Field>
          </div>
          {error && !error.campo && <p className="text-[12.5px] font-medium text-[#ef4444]">{error.msg}</p>}
        </ModalBody>
        <ModalFooter>
          <Button variant="ghost" onClick={cerrar}>
            Cancelar
          </Button>
          <Button type="submit" loading={acc.crear.isPending} loadingText="Creando…">
            Crear negocio
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
