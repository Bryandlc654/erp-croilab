import { useState } from 'react'
import Select from '../../../shared/ui/Select'
import { useDebounced } from '../../../shared/lib/useDebounced'
import { useContactos } from '../api'

/* Elegir un contacto («nombre — empresa») con buscador: filtra en el
   navegador y, al teclear, pide al servidor los que coinciden. */
export default function ContactoPicker({
  value,
  onChange,
  invalid,
  placeholder = 'Selecciona un contacto…',
  id,
}: {
  value: number | null
  onChange: (id: number) => void
  invalid?: boolean
  placeholder?: string
  id?: string
}) {
  const [q, setQ] = useState('')
  const busca = useDebounced(q, 250)
  const { data } = useContactos(busca ? { q: busca } : {})
  const items = data?.pages.flatMap((p) => p.items) ?? []
  const [elegido, setElegido] = useState<{ value: number; label: string } | null>(null)
  const opciones = items.map((c) => ({ value: c.id, label: c.empresa ? `${c.nombre} — ${c.empresa}` : c.nombre }))
  // Lo elegido sigue viéndose aunque la búsqueda ya no lo traiga.
  if (elegido && elegido.value === value && !opciones.some((o) => o.value === value)) opciones.unshift(elegido)
  return (
    <Select
      id={id}
      searchable
      searchPlaceholder="Buscar contacto…"
      onSearchChange={setQ}
      value={value}
      invalid={invalid}
      placeholder={placeholder}
      onChange={(v) => {
        const o = opciones.find((x) => x.value === v)
        if (o) setElegido(o)
        onChange(v)
      }}
      emptyText={busca ? 'Ningún contacto coincide' : 'Sin contactos'}
      options={opciones}
    />
  )
}
