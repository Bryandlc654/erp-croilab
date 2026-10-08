import { useState } from 'react'
import Button from '../../../shared/ui/Button'
import Field from '../../../shared/ui/Field'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import { TextInput } from '../../../shared/ui/TextInput'
import { useToast } from '../../../shared/ui/useToast'
import { mensajeError, useAccionNegocio, useCatalogos } from '../api'
import type { Negocio } from '../schemas'

/* «¿Por qué se perdió?»: el motivo decide cuándo se vuelve a intentar
   (reactivación) y alimenta el dashboard. */
export default function MotivoPerdidaModal({ negocio, fase, onClose }: { negocio: Negocio; fase?: string; onClose: () => void }) {
  const { data: cat } = useCatalogos()
  const acc = useAccionNegocio()
  const { aviso } = useToast()
  const [motivo, setMotivo] = useState('')
  const [comentario, setComentario] = useState('')
  const guardando = acc.perder.isPending || acc.mover.isPending

  function guardar() {
    if (!motivo) return aviso('Elige un motivo', { tipo: 'error' })
    const fin = { onSuccess: () => (aviso('Negocio marcado como perdido'), onClose()), onError: (e: unknown) => aviso(mensajeError(e), { tipo: 'error' }) }
    if (fase) acc.mover.mutate({ id: negocio.id, fase, motivo, comentario }, fin)
    else acc.perder.mutate({ id: negocio.id, motivo, comentario }, fin)
  }

  return (
    <Modal open onClose={onClose} size="md" title="¿Por qué se perdió?" subtitle={negocio.nombre}>
      <ModalBody>
        <div className="grid grid-cols-2 gap-2 max-sm:grid-cols-1" role="radiogroup" aria-label="Motivo">
          {(cat?.motivos_perdida ?? []).map((m) => (
            <button
              key={m.value}
              type="button"
              role="radio"
              aria-checked={motivo === m.value}
              onClick={() => setMotivo(m.value)}
              className={`rounded-[11px] border px-3 py-2.5 text-left transition-colors ${motivo === m.value ? 'border-ink-strong bg-ink-strong text-white dark:border-rev dark:bg-rev dark:text-rev-fg' : 'border-line bg-field hover:bg-soft'}`}
            >
              <span className="block text-[13px] font-semibold">{m.label}</span>
              <span className={`block text-[11.5px] ${motivo === m.value ? 'opacity-75' : 'text-muted'}`}>{m.meses ? `reactivar en ${m.meses} meses` : 'no se reactiva'}</span>
            </button>
          ))}
        </div>
        <Field label="Comentario (opcional)">
          <TextInput value={comentario} onChange={(e) => setComentario(e.target.value)} placeholder="Detalle…" maxLength={255} />
        </Field>
      </ModalBody>
      <ModalFooter>
        <Button variant="ghost" onClick={onClose}>
          Cancelar
        </Button>
        <Button variant="danger" onClick={guardar} loading={guardando} loadingText="Guardando…">
          Marcar perdido
        </Button>
      </ModalFooter>
    </Modal>
  )
}
