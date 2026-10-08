import { useState } from 'react'
import { Search } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import { TextInput } from '../../../shared/ui/TextInput'
import { useToast } from '../../../shared/ui/useToast'
import { useDebounced } from '../../../shared/lib/useDebounced'
import { mensajeError, useAccionListas, useContactos } from '../api'
import type { Lista } from '../schemas'

/* «Añadir contacto a la lista»: entra aunque no cumpla las condiciones y
   queda fijo hasta que se quite. */
export default function AnadirMiembroModal({ open, lista, onClose }: { open: boolean; lista: Lista; onClose: () => void }) {
  const [q, setQ] = useState('')
  const busca = useDebounced(q, 250)
  const { data } = useContactos(busca ? { q: busca } : {})
  const acc = useAccionListas()
  const { aviso } = useToast()
  const items = (data?.pages.flatMap((p) => p.items) ?? []).slice(0, 60)

  return (
    <Modal open={open} onClose={onClose} size="md" title="Añadir contacto a la lista" subtitle={`Entra en «${lista.nombre}» aunque no cumpla las condiciones. Quedará fijo hasta que lo quites.`}>
      <ModalBody>
        <TextInput value={q} onChange={(e) => setQ(e.target.value)} placeholder="Buscar contacto…" leftIcon={<Search />} autoFocus aria-label="Buscar contacto" />
        <ul className="max-h-[320px] overflow-y-auto">
          {items.map((c) => (
            <li key={c.id}>
              <button
                type="button"
                onClick={() =>
                  acc.miembro.mutate(
                    { id: lista.id, contacto: c.id, on: true },
                    { onSuccess: () => aviso(`${c.nombre} añadido a la lista`), onError: (e) => aviso(mensajeError(e), { tipo: 'error' }) },
                  )
                }
                className="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-left hover:bg-soft"
              >
                <Avatar nombre={c.nombre} size={26} />
                <span className="min-w-0 flex-1 truncate text-[13.5px] font-semibold text-ink-strong">{c.nombre}</span>
                <span className="truncate text-[12px] text-muted">{c.empresa}</span>
              </button>
            </li>
          ))}
          {data && items.length === 0 && <li className="px-2 py-3 text-[12.5px] text-muted">Ningún contacto coincide.</li>}
        </ul>
      </ModalBody>
      <ModalFooter>
        <Button variant="ghost" onClick={onClose}>
          Cerrar
        </Button>
      </ModalFooter>
    </Modal>
  )
}
