import { useRef, useState, type FormEvent } from 'react'
import Button from '../../../shared/ui/Button'
import Field from '../../../shared/ui/Field'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import Select from '../../../shared/ui/Select'
import { TextInput } from '../../../shared/ui/TextInput'
import { useToast } from '../../../shared/ui/useToast'
import { ApiError } from '../../../shared/api/client'
import { useEquipo } from '../../nav/api'
import { useCatalogos, useCrearContacto } from '../api'
import { usePermisosCrm } from '../permisos'
import type { Contacto } from '../schemas'

const VACIO = { nombre: '', empresa: '', sector: '', email: '', telefono: '', origen_lead: '', propietario_id: 0 }

/* «Nuevo contacto» (crm.php new_contact): solo el nombre es obligatorio. */
export default function NuevoContactoModal({ open, onClose, onCreado }: { open: boolean; onClose: () => void; onCreado?: (c: Contacto) => void }) {
  const [d, setD] = useState(VACIO)
  const [error, setError] = useState<{ campo: string | null; msg: string } | null>(null)
  const crear = useCrearContacto()
  const { data: cat } = useCatalogos()
  const { data: equipo = [] } = useEquipo()
  const p = usePermisosCrm()
  const { aviso } = useToast()
  const primero = useRef<HTMLInputElement>(null)

  function cerrar() {
    setD(VACIO)
    setError(null)
    onClose()
  }

  async function enviar(e: FormEvent) {
    e.preventDefault()
    if (!d.nombre.trim()) return setError({ campo: 'nombre', msg: 'El nombre es obligatorio.' })
    try {
      const r = await crear.mutateAsync({ ...d, propietario_id: d.propietario_id || null })
      aviso('Contacto creado')
      cerrar()
      onCreado?.(r.contacto)
    } catch (x) {
      setError({ campo: x instanceof ApiError ? x.campo : null, msg: x instanceof ApiError ? x.message : 'No se ha podido crear.' })
    }
  }

  const campo = (k: keyof typeof VACIO) => ({
    value: String(d[k]),
    onChange: (e: { target: { value: string } }) => setD((x) => ({ ...x, [k]: e.target.value })),
  })
  const err = (k: string) => (error?.campo === k ? error.msg : undefined)
  /* Por defecto, quien lo crea (como en el antiguo, se puede cambiar). */
  const personas = equipo
  if (open && d === VACIO && p.yo && equipo.some((x) => x.id === p.yo)) setD({ ...VACIO, propietario_id: p.yo })

  return (
    <Modal open={open} onClose={cerrar} title="Nuevo contacto" size="md" initialFocus={primero}>
      <form onSubmit={(e) => void enviar(e)} className="contents">
        <ModalBody>
          <Field label="Nombre" required error={err('nombre')}>
            <TextInput ref={primero} {...campo('nombre')} placeholder="Persona de contacto" maxLength={200} />
          </Field>
          <Field label="Empresa">
            <TextInput {...campo('empresa')} maxLength={200} />
          </Field>
          <div className="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
            <Field label="Sector">
              <TextInput {...campo('sector')} list="crm-sectores-nuevo" maxLength={80} />
            </Field>
            <Field label="Origen">
              <Select value={d.origen_lead} onChange={(v) => setD((x) => ({ ...x, origen_lead: v }))} options={[{ value: '', label: '—' }, ...(cat?.origenes ?? []).map((o) => ({ value: o, label: o }))]} />
            </Field>
            <Field label="Email" error={err('email')}>
              <TextInput type="email" {...campo('email')} maxLength={160} />
            </Field>
            <Field label="Teléfono">
              <TextInput type="tel" {...campo('telefono')} maxLength={60} />
            </Field>
          </div>
          <Field label="Propietario" error={err('propietario_id')}>
            <Select value={d.propietario_id} onChange={(v) => setD((x) => ({ ...x, propietario_id: v }))} options={[{ value: 0, label: 'Sin propietario' }, ...personas.map((x) => ({ value: x.id, label: x.username }))]} />
          </Field>
          {error && !error.campo && <p className="text-[12.5px] font-medium text-[#ef4444]">{error.msg}</p>}
          <datalist id="crm-sectores-nuevo">
            {(cat?.sectores ?? []).map((s) => (
              <option key={s} value={s} />
            ))}
          </datalist>
        </ModalBody>
        <ModalFooter>
          <Button variant="ghost" onClick={cerrar}>
            Cancelar
          </Button>
          <Button type="submit" loading={crear.isPending} loadingText="Creando…">
            Crear contacto
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
