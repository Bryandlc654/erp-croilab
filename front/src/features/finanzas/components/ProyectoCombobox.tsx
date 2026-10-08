import { useRef, useState } from 'react'
import { ChevronDown, Plus, X } from 'lucide-react'
import Popover from '../../../shared/ui/Popover'
import { useDebounced } from '../../../shared/lib/useDebounced'
import { useBuscarProyectos } from '../api'

import type { ProyectoElegido } from '../lib/cuerpos'

/* Combobox de proyecto (proj_combobox del antiguo): busca en el servidor, pone
   primero los del cliente y permite «Crear «X»» en línea. */
export default function ProyectoCombobox({
  value,
  onChange,
  clientId = null,
  placeholder = 'Sin proyecto — buscar o crear…',
  disabled = false,
}: {
  value: ProyectoElegido
  onChange: (p: ProyectoElegido) => void
  clientId?: number | null
  placeholder?: string
  disabled?: boolean
}) {
  const boton = useRef<HTMLButtonElement>(null)
  const [abierto, setAbierto] = useState(false)
  const [q, setQ] = useState('')
  const qd = useDebounced(q.trim(), 200)
  const { data } = useBuscarProyectos(qd, clientId, abierto)
  const items = data?.items ?? []
  const delCliente = items.filter((p) => p.is_client)
  const otros = items.filter((p) => !p.is_client)
  const existe = items.some((p) => p.nombre.toLowerCase() === qd.toLowerCase())

  function elegir(p: ProyectoElegido) {
    onChange(p)
    setAbierto(false)
    setQ('')
  }

  const opcion = (p: (typeof items)[number]) => (
    <button
      key={p.id}
      type="button"
      onClick={() => elegir({ id: p.id, nombre: p.nombre, color: p.color })}
      className="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[13px] text-[#4c515b] hover:bg-soft hover:text-ink dark:text-nav-ink"
    >
      <span className="size-2.5 shrink-0 rounded-full" style={{ backgroundColor: p.color }} />
      <span className="min-w-0 flex-1 truncate">{p.nombre}</span>
      {!p.activo && <span className="text-[10.5px] text-label">archivado</span>}
      {p.nmov > 0 && <span className="text-[11px] text-label">{p.nmov} mov.</span>}
    </button>
  )

  return (
    <div className="relative">
      <button
        ref={boton}
        type="button"
        disabled={disabled}
        onClick={() => setAbierto((v) => !v)}
        aria-haspopup="listbox"
        aria-expanded={abierto}
        className="flex min-h-[43px] w-full items-center gap-2 rounded-[10px] border border-line bg-field px-3 py-[9px] text-left text-[13.5px] transition-colors hover:border-line-strong disabled:opacity-60 max-sm:text-[16px]"
      >
        {value ? (
          <>
            <span className="size-2.5 shrink-0 rounded-full" style={{ backgroundColor: value.color ?? '#2f6df6' }} />
            <span className="min-w-0 flex-1 truncate text-ink">
              {value.nombre}
              {value.id === null && <span className="ml-1.5 text-[12px] text-muted">(nuevo)</span>}
            </span>
            <span className="w-6" aria-hidden="true" />
          </>
        ) : (
          <span className="min-w-0 flex-1 truncate text-label">{placeholder}</span>
        )}
        <ChevronDown className={`size-4 shrink-0 text-label transition-transform ${abierto ? 'rotate-180' : ''}`} />
      </button>
      {value && !disabled && (
        <button
          type="button"
          aria-label="Quitar proyecto"
          onClick={() => onChange(null)}
          className="absolute top-1/2 right-9 flex size-6 -translate-y-1/2 items-center justify-center rounded-md text-label hover:bg-soft"
        >
          <X className="size-3.5" />
        </button>
      )}
      <Popover open={abierto} onClose={() => setAbierto(false)} anchor={boton} width="anchor" maxHeight={320} initialFocus="first">
        <input
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Buscar o crear proyecto…"
          aria-label="Buscar proyecto"
          className="mb-1 w-full rounded-lg border border-line bg-field px-2.5 py-2 text-[13px] text-ink outline-none focus:border-ring"
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault()
              const exacto = items.find((p) => p.nombre.toLowerCase() === qd.toLowerCase())
              if (exacto) elegir({ id: exacto.id, nombre: exacto.nombre, color: exacto.color })
              else if (q.trim()) elegir({ id: null, nombre: q.trim() })
            }
          }}
        />
        <div role="listbox" aria-label="Proyectos">
          {delCliente.length > 0 && <p className="px-2.5 pt-1.5 pb-1 text-[10.5px] font-bold tracking-[.5px] text-muted uppercase">Del cliente</p>}
          {delCliente.map(opcion)}
          {otros.length > 0 && <p className="px-2.5 pt-1.5 pb-1 text-[10.5px] font-bold tracking-[.5px] text-muted uppercase">{delCliente.length ? 'Otros' : 'Activos'}</p>}
          {otros.map(opcion)}
          {items.length === 0 && !q.trim() && <p className="px-2.5 py-2 text-[12.5px] text-muted">Aún no hay proyectos.</p>}
          {q.trim() && !existe && (
            <button
              type="button"
              onClick={() => elegir({ id: null, nombre: q.trim() })}
              className="mt-1 flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-[13px] font-semibold text-ink hover:bg-soft"
            >
              <Plus className="size-3.5" /> Crear «{q.trim()}»
            </button>
          )}
        </div>
      </Popover>
    </div>
  )
}
