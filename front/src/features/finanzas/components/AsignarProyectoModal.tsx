import { useState } from 'react'
import Button from '../../../shared/ui/Button'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import { proyectoACuerpo, type ProyectoElegido } from '../lib/cuerpos'
import type { ProyectoMini } from '../schemas'
import ProyectoCombobox from './ProyectoCombobox'

/* «Asignar proyecto…» de una factura (uso interno: no sale en la factura). */
export default function AsignarProyectoModal({
  open,
  onClose,
  clientId,
  actual,
  onGuardar,
}: {
  open: boolean
  onClose: () => void
  clientId: number | null
  actual: ProyectoMini | null
  onGuardar: (cuerpo: ReturnType<typeof proyectoACuerpo>) => Promise<void>
}) {
  return (
    <Modal open={open} onClose={onClose} title="Asignar proyecto" subtitle="Uso interno, para la rentabilidad: no sale en la factura." size="md">
      {open && <Contenido onClose={onClose} clientId={clientId} actual={actual} onGuardar={onGuardar} />}
    </Modal>
  )
}

function Contenido({ onClose, clientId, actual, onGuardar }: Omit<Parameters<typeof AsignarProyectoModal>[0], 'open'>) {
  const [p, setP] = useState<ProyectoElegido>(actual ? { id: actual.id, nombre: actual.nombre, color: actual.color } : null)
  const [guardando, setGuardando] = useState(false)
  return (
    <>
      <ModalBody>
        <ProyectoCombobox value={p} onChange={setP} clientId={clientId} placeholder="Buscar o crear proyecto…" />
      </ModalBody>
      <ModalFooter>
        <Button variant="ghost" onClick={onClose}>
          Cancelar
        </Button>
        <Button
          loading={guardando}
          loadingText="Guardando…"
          onClick={async () => {
            setGuardando(true)
            try {
              await onGuardar(proyectoACuerpo(p))
            } finally {
              setGuardando(false)
            }
          }}
        >
          Guardar
        </Button>
      </ModalFooter>
    </>
  )
}
