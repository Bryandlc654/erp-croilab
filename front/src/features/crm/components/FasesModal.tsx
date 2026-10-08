import { Lock, Plus, Trash2 } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import IconButton from '../../../shared/ui/IconButton'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import SortableList, { RowGrip } from '../../../shared/ui/SortableList'
import { TextInput } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { mensajeError, useCatalogos, useFases } from '../api'

/* «Editar fases del embudo» (solo el dueño): nombre, color y probabilidad de
   cada fase; añadir pasos y quitar los abiertos que no sean estructurales.
   Cada cambio se guarda al salir del campo. */
export default function FasesModal({ onClose }: { onClose: () => void }) {
  const { data: cat } = useCatalogos()
  const acc = useFases()
  const { confirm, prompt } = useConfirm()
  const { aviso } = useToast()
  const fases = cat?.fases ?? []
  const fallo = (e: unknown) => aviso(mensajeError(e), { tipo: 'error' })

  async function anadir() {
    const nombre = await prompt({ title: 'Nombre de la nueva fase', placeholder: 'Ej: Demo agendada', okLabel: 'Añadir' })
    if (nombre) acc.crear.mutate({ nombre }, { onSuccess: () => aviso(`Fase «${nombre}» añadida`), onError: fallo })
  }

  return (
    <Modal open onClose={onClose} size="lg" title="Editar fases del embudo" subtitle="Nombre, color y probabilidad de cierre (%) de cada fase. Puedes añadir pasos nuevos o quitar los que ya no uses.">
      <ModalBody className="!gap-1">
        <SortableList
          items={fases}
          getId={(f) => f.id}
          onReorder={(ids) => acc.orden.mutate(ids.map(Number), { onError: fallo })}
          className="flex flex-col gap-1.5"
          renderItem={(f, { handleProps }) => (
            <div className="flex items-center gap-2 rounded-[10px] py-0.5">
              <RowGrip {...handleProps} className="!opacity-60" aria-label={`Mover ${f.nombre}`} />
              <input
                type="color"
                defaultValue={f.color}
                aria-label={`Color de ${f.nombre}`}
                onBlur={(e) => e.target.value !== f.color && acc.editar.mutate({ id: f.id, color: e.target.value }, { onError: fallo })}
                className="size-8 shrink-0 cursor-pointer rounded-lg border border-line bg-field p-0.5"
              />
              <TextInput
                size="sm"
                defaultValue={f.nombre}
                key={`n${f.nombre}`}
                aria-label="Nombre de la fase"
                maxLength={80}
                onBlur={(e) => e.target.value.trim() && e.target.value.trim() !== f.nombre && acc.editar.mutate({ id: f.id, nombre: e.target.value.trim() }, { onError: fallo })}
              />
              <TextInput
                size="sm"
                unit="%"
                defaultValue={String(f.probabilidad)}
                key={`p${f.probabilidad}`}
                inputMode="numeric"
                aria-label="Probabilidad de cierre"
                className="w-[86px] shrink-0"
                onBlur={(e) => {
                  const n = Number(e.target.value)
                  if (Number.isInteger(n) && n !== f.probabilidad) acc.editar.mutate({ id: f.id, probabilidad: n }, { onError: fallo })
                }}
              />
              {f.tipo === 'abierta' && !f.estructural ? (
                <IconButton
                  label={`Quitar ${f.nombre}`}
                  tone="danger"
                  icon={<Trash2 />}
                  onClick={async () => {
                    const ok = await confirm({ title: `¿Quitar la fase «${f.nombre}»?`, message: `Los negocios que estén en «${f.nombre}» pasarán a la primera fase abierta del embudo; no se pierde ninguno.`, danger: true, okLabel: 'Quitar' })
                    if (ok) acc.borrar.mutate(f.id, { onSuccess: (r) => aviso(`Fase eliminada; sus negocios pasaron a «${r.destino}»`), onError: fallo })
                  }}
                />
              ) : (
                <span className="flex size-[30px] shrink-0 items-center justify-center text-label" title="Fase estructural del ERP: no se puede quitar">
                  <Lock className="size-[15px]" aria-label="Fase estructural del ERP: no se puede quitar" />
                </span>
              )}
            </div>
          )}
        />
      </ModalBody>
      <ModalFooter className="!justify-between">
        <Button variant="ghost" icon={<Plus />} onClick={() => void anadir()}>
          Añadir fase
        </Button>
        <Button onClick={onClose}>Listo</Button>
      </ModalFooter>
    </Modal>
  )
}
