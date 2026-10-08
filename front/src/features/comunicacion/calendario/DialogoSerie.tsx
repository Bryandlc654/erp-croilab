import { Repeat } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Modal, { ModalBody } from '../../../shared/ui/Modal'
import type { Eleccion } from './useDialogoSerie'

/* Mini diálogo de los eventos repetidos: «Solo este evento» / «Toda la serie». */
export default function DialogoSerie({ titulo, responder }: { titulo: string | null; responder: (v: Eleccion | null) => void }) {
  return (
    <Modal open={titulo !== null} onClose={() => responder(null)} size="sm" title={titulo ?? ''} logo={<Repeat className="size-5 text-label" />}>
      <ModalBody>
        <p className="text-[13px] text-muted">Este evento se repite. ¿A qué quieres aplicarlo?</p>
        <div className="flex flex-col gap-2">
          <Button onClick={() => responder('este')} className="w-full">
            Solo este evento
          </Button>
          <Button variant="ghost" size="sm" onClick={() => responder('serie')} className="w-full">
            Toda la serie
          </Button>
        </div>
      </ModalBody>
    </Modal>
  )
}
