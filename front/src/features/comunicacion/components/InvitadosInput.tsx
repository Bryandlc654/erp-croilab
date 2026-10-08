import { useMemo, useRef, useState } from 'react'
import Popover from '../../../shared/ui/Popover'
import { TextInput } from '../../../shared/ui/TextInput'
import { useCorreos } from '../api'
import { sugerirCorreos, ultimoToken, reemplazarUltimoToken } from '../logica'

/* Invitados como texto con comas («a@x.com, b@y.com») y autocompletado del
   último correo que se escribe: equipo, contactos del CRM y clientes (máx. 6,
   ↑ ↓ Intro/Tab Esc), como el antiguo. */
export default function InvitadosInput({
  value,
  onChange,
  id,
  placeholder = 'correo@ejemplo.com, otro@ejemplo.com',
}: {
  value: string
  onChange: (v: string) => void
  id?: string
  placeholder?: string
}) {
  const ref = useRef<HTMLInputElement>(null)
  const [foco, setFoco] = useState(false)
  const [activo, setActivo] = useState(0)
  const [cerrado, setCerrado] = useState(false)
  const correos = useCorreos(foco)
  const token = ultimoToken(value)
  const items = useMemo(() => sugerirCorreos(correos.data ?? [], token, value), [correos.data, token, value])
  const abierto = foco && !cerrado && token.length >= 1 && items.length > 0

  function elegir(email: string) {
    onChange(reemplazarUltimoToken(value, email))
    setActivo(0)
    requestAnimationFrame(() => ref.current?.focus())
  }

  return (
    <>
      <TextInput
        ref={ref}
        id={id}
        value={value}
        placeholder={placeholder}
        autoComplete="off"
        onFocus={() => setFoco(true)}
        onBlur={() => setTimeout(() => setFoco(false), 150)}
        onChange={(e) => {
          onChange(e.target.value)
          setActivo(0)
          setCerrado(false)
        }}
        onKeyDown={(e) => {
          if (!abierto) return
          if (e.key === 'ArrowDown') { e.preventDefault(); setActivo((a) => (a + 1) % items.length) }
          else if (e.key === 'ArrowUp') { e.preventDefault(); setActivo((a) => (a - 1 + items.length) % items.length) }
          else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); elegir(items[activo].email) }
          else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); setCerrado(true) }
        }}
      />
      <Popover open={abierto} onClose={() => setCerrado(true)} anchor={ref} placement="bottom-start" width="anchor" initialFocus="none" returnFocus={false} role="listbox">
        <ul className="p-[5px]">
          {items.map((c, i) => (
            <li key={c.email}>
              <button
                type="button"
                role="option"
                aria-selected={i === activo}
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => elegir(c.email)}
                onMouseEnter={() => setActivo(i)}
                className={`flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-[13px] ${i === activo ? 'bg-soft' : ''}`}
              >
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-semibold text-ink-strong">{c.nombre || c.email}</span>
                  <span className="block truncate text-[11.5px] text-muted">{c.email}</span>
                </span>
                <span className="shrink-0 rounded-md bg-chip px-1.5 py-px text-[10.5px] font-semibold text-label capitalize">{c.tipo}</span>
              </button>
            </li>
          ))}
        </ul>
      </Popover>
    </>
  )
}
