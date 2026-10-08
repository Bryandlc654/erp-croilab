import { useId, useRef, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { Search } from 'lucide-react'
import Popover from '../../../shared/ui/Popover'
import { buscarAjustes } from '../logica'

/* Cabecera de las pantallas de ajustes (aj_head del antiguo): título,
   descripción, el buscador «Buscar un ajuste…» y la acción de la derecha. */
export default function AjustesCabecera({ titulo, sub, accion, icono }: { titulo: ReactNode; sub?: ReactNode; accion?: ReactNode; icono?: ReactNode }) {
  return (
    <header className="mb-[26px] flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
      <div className="min-w-0 flex-1">
        <h1 className="flex items-center gap-2.5 text-[26px] leading-[1.2] font-semibold tracking-[-.5px] text-ink-strong max-sm:text-[23px] [&>svg]:size-6 [&>svg]:text-muted">
          {icono}
          {titulo}
        </h1>
        {sub && <p className="mt-2 max-w-[75ch] text-[13.5px] leading-[1.55] text-muted">{sub}</p>}
      </div>
      <div className="flex flex-wrap items-center gap-2.5 max-sm:w-full">
        <BuscadorAjustes />
        {accion}
      </div>
    </header>
  )
}

function BuscadorAjustes() {
  const [q, setQ] = useState('')
  const [abierto, setAbierto] = useState(false)
  const [activo, setActivo] = useState(0)
  const caja = useRef<HTMLLabelElement>(null)
  const lista = useId()
  const navigate = useNavigate()
  const res = buscarAjustes(q)

  function ir(i: number) {
    const r = res[i]
    if (!r) return
    setAbierto(false)
    setQ('')
    navigate(r.to)
  }

  return (
    <>
      <label
        ref={caja}
        className="flex w-[230px] cursor-text items-center gap-2 rounded-[10px] border border-line bg-field px-3 py-2 transition-colors focus-within:border-ring hover:border-line-strong max-sm:w-full"
      >
        <Search className="size-[15px] shrink-0 text-label" aria-hidden="true" />
        <input
          type="search"
          value={q}
          role="combobox"
          aria-expanded={abierto && q.trim().length >= 2}
          aria-controls={lista}
          aria-label="Buscar un ajuste"
          placeholder="Buscar un ajuste…"
          onChange={(e) => {
            setQ(e.target.value)
            setActivo(0)
            setAbierto(true)
          }}
          onFocus={() => setAbierto(true)}
          onKeyDown={(e) => {
            if (e.key === 'ArrowDown') {
              e.preventDefault()
              setActivo((a) => Math.min(a + 1, Math.max(0, res.length - 1)))
            } else if (e.key === 'ArrowUp') {
              e.preventDefault()
              setActivo((a) => Math.max(0, a - 1))
            } else if (e.key === 'Enter') {
              e.preventDefault()
              ir(activo)
            } else if (e.key === 'Escape') {
              setAbierto(false)
            }
          }}
          className="min-w-0 flex-1 bg-transparent text-[13px] text-ink placeholder:text-label focus:outline-none max-sm:text-[16px] [&::-webkit-search-cancel-button]:hidden"
        />
      </label>
      <Popover open={abierto && q.trim().length >= 2} onClose={() => setAbierto(false)} anchor={caja} placement="bottom-end" width={300} maxHeight={340} returnFocus={false}>
        <ul id={lista} role="listbox" className="p-1">
          {res.length === 0 && <li className="px-3 py-2.5 text-[12.5px] text-muted">Nada con «{q.trim()}».</li>}
          {res.map((r, i) => (
            <li key={r.titulo} role="option" aria-selected={i === activo}>
              <button
                type="button"
                onMouseEnter={() => setActivo(i)}
                onClick={() => ir(i)}
                className={`flex w-full flex-col items-start rounded-lg px-3 py-2 text-left ${i === activo ? 'bg-soft' : ''}`}
              >
                <span className="text-[13px] font-semibold text-ink-strong">{r.titulo}</span>
                <span className="text-[11.5px] text-muted">{r.donde}</span>
              </button>
            </li>
          ))}
        </ul>
      </Popover>
    </>
  )
}
