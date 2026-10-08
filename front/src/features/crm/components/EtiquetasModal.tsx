import { useState, type FormEvent } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import IconButton from '../../../shared/ui/IconButton'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import { TextInput } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { mensajeError, useCatalogos, useEtiquetas } from '../api'
import { usePermisosCrm } from '../permisos'

/* Etiquetas del CRM (modal «Etiquetas» de Contactos): crear, renombrar,
   cambiar el color y borrar (se quita de todos los contactos y negocios). */
export default function EtiquetasModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { data: cat } = useCatalogos()
  const acc = useEtiquetas()
  const p = usePermisosCrm()
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const [nombre, setNombre] = useState('')
  const [color, setColor] = useState('#5b8def')

  async function crear(e: FormEvent) {
    e.preventDefault()
    if (!nombre.trim()) return
    try {
      await acc.crear.mutateAsync({ nombre: nombre.trim(), color })
      setNombre('')
      aviso('Etiqueta creada')
    } catch (x) {
      aviso(mensajeError(x), { tipo: 'error' })
    }
  }

  const fallo = (x: unknown) => aviso(mensajeError(x), { tipo: 'error' })
  const etiquetas = cat?.etiquetas ?? []

  return (
    <Modal open={open} onClose={onClose} title="Etiquetas" subtitle="Para agrupar contactos y negocios. Se ven en la tabla y en el embudo." size="md">
      <ModalBody>
        {etiquetas.length === 0 && <p className="text-[13px] text-muted">Aún no hay etiquetas.</p>}
        <ul className="flex flex-col gap-1.5">
          {etiquetas.map((t) => (
            <li key={t.id} className="flex items-center gap-2">
              <input
                type="color"
                defaultValue={t.color}
                disabled={!p.editar}
                aria-label={`Color de ${t.nombre}`}
                onBlur={(e) => e.target.value !== t.color && acc.editar.mutate({ id: t.id, color: e.target.value }, { onError: fallo })}
                className="size-8 shrink-0 cursor-pointer rounded-lg border border-line bg-field p-0.5"
              />
              <TextInput
                size="sm"
                defaultValue={t.nombre}
                key={t.nombre}
                readOnly={!p.editar}
                aria-label="Nombre de la etiqueta"
                onBlur={(e) => e.target.value.trim() && e.target.value.trim() !== t.nombre && acc.editar.mutate({ id: t.id, nombre: e.target.value.trim() }, { onError: fallo })}
              />
              {p.borrar && (
                <IconButton
                  label={`Eliminar ${t.nombre}`}
                  tone="danger"
                  icon={<Trash2 />}
                  onClick={async () => {
                    const ok = await confirm({ title: '¿Eliminar la etiqueta?', message: 'Se quita de todos los contactos y negocios.', danger: true })
                    if (ok) acc.borrar.mutate(t.id, { onError: fallo })
                  }}
                />
              )}
            </li>
          ))}
        </ul>
        {p.editar && (
          <form onSubmit={(e) => void crear(e)} className="mt-2 flex items-center gap-2 border-t border-line2 pt-4">
            <input type="color" value={color} onChange={(e) => setColor(e.target.value)} aria-label="Color" className="size-8 shrink-0 cursor-pointer rounded-lg border border-line bg-field p-0.5" />
            <TextInput size="sm" value={nombre} onChange={(e) => setNombre(e.target.value)} placeholder="Nueva etiqueta" maxLength={80} aria-label="Nombre de la etiqueta nueva" />
            <Button type="submit" size="sm" icon={<Plus />} loading={acc.crear.isPending} disabled={!nombre.trim()}>
              Crear
            </Button>
          </form>
        )}
      </ModalBody>
      <ModalFooter>
        <Button variant="ghost" onClick={onClose}>
          Cerrar
        </Button>
      </ModalFooter>
    </Modal>
  )
}
