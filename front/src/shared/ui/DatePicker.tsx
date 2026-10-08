import { useId, useRef, useState, type KeyboardEvent } from 'react'
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react'
import Popover, { type AnclaPopover } from './Popover'
import { useCampo } from './campoContexto'
import { claseCampo } from './clases'
import { diasCalendario, parsearFecha, sumarDias } from '../lib/fechas'
import { fechaCorta, isoDia, mesNombre } from '../lib/formato'

const SEMANA = ['L', 'M', 'X', 'J', 'V', 'S', 'D']
const NOMBRES_SEMANA = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo']

type Vista = { anio: number; mes0: number }

function vistaDe(iso: string) {
  const [y, m] = iso.split('-').map(Number)
  return { anio: y, mes0: m - 1 }
}

function moverMes(v: Vista, n: number): Vista {
  const d = new Date(v.anio, v.mes0 + n, 1)
  return { anio: d.getFullYear(), mes0: d.getMonth() }
}

/* Teclas del calendario: flechas ±1/±7 días, RePág/AvPág ±1 mes. Devuelve el
   nuevo día enfocado o null si la tecla no es suya. */
function teclaCalendario(e: KeyboardEvent, foco: string, horizontal: boolean): string | null {
  const [y, m, d] = foco.split('-').map(Number)
  switch (e.key) {
    case 'ArrowDown':
      return sumarDias(foco, 7)
    case 'ArrowUp':
      return sumarDias(foco, -7)
    case 'ArrowLeft':
      return horizontal ? sumarDias(foco, -1) : null
    case 'ArrowRight':
      return horizontal ? sumarDias(foco, 1) : null
    case 'PageUp':
      return isoDia(new Date(y, m - 2, Math.min(d, new Date(y, m - 1, 0).getDate())))
    case 'PageDown':
      return isoDia(new Date(y, m, Math.min(d, new Date(y, m + 1, 0).getDate())))
    default:
      return null
  }
}

function PanelCalendario({
  value,
  foco,
  vista,
  setVista,
  onPick,
  onBorrar,
  idBase,
}: {
  value: string | null
  foco: string
  vista: Vista
  setVista: (v: Vista) => void
  onPick: (iso: string) => void
  onBorrar: () => void
  idBase: string
}) {
  const hoy = isoDia(new Date())
  const dias = diasCalendario(vista.anio, vista.mes0)
  const BOTON_MES = 'flex size-7 items-center justify-center rounded-lg text-[#6b7280] hover:bg-soft hover:text-ink max-sm:size-[34px] dark:text-muted'
  const PIE = 'rounded-lg px-[9px] py-[5px] text-[12px] font-semibold text-[#6b7280] dark:text-muted'
  return (
    <div>
      <div className="mb-2 flex items-center justify-between">
        <button type="button" tabIndex={-1} className={BOTON_MES} onClick={() => setVista(moverMes(vista, -1))} aria-label="Mes anterior">
          <ChevronLeft className="size-4" />
        </button>
        <span className="text-[13px] font-bold text-ink-strong" aria-live="polite">
          {mesNombre(vista.mes0 + 1)} {vista.anio}
        </span>
        <button type="button" tabIndex={-1} className={BOTON_MES} onClick={() => setVista(moverMes(vista, 1))} aria-label="Mes siguiente">
          <ChevronRight className="size-4" />
        </button>
      </div>
      <div role="grid" aria-label={`${mesNombre(vista.mes0 + 1)} ${vista.anio}`}>
        <div role="row" className="mb-1 grid grid-cols-7 gap-0.5">
          {SEMANA.map((s, i) => (
            <span key={s} role="columnheader" aria-label={NOMBRES_SEMANA[i]} className="text-center text-[10px] font-bold text-label">
              {s}
            </span>
          ))}
        </div>
        <div className="grid grid-cols-7 gap-0.5">
          {dias.map((c) => {
            const sel = c.iso === value
            return (
              <button
                key={c.iso}
                id={`${idBase}-${c.iso}`}
                type="button"
                role="gridcell"
                tabIndex={-1}
                aria-selected={sel}
                aria-label={fechaCorta(c.iso)}
                onClick={() => onPick(c.iso)}
                className={`h-[30px] rounded-lg text-[12.5px] transition-colors max-sm:h-9 ${
                  sel
                    ? 'bg-accent font-semibold text-white dark:text-accent-fg'
                    : `hover:bg-soft ${c.iso === hoy ? 'font-bold text-accent' : c.delMes ? 'text-ink' : 'text-label/60'}`
                } ${c.iso === foco && !sel ? 'ring-1 ring-line-strong ring-inset' : ''}`}
              >
                {c.dia}
              </button>
            )
          })}
        </div>
      </div>
      <div className="mt-[9px] flex justify-between border-t border-line pt-[9px]">
        <button type="button" tabIndex={-1} onClick={onBorrar} className={`${PIE} hover:bg-[#fde8e8] hover:text-[#c0392b] dark:hover:bg-danger-bg dark:hover:text-danger`}>
          Borrar
        </button>
        <button type="button" tabIndex={-1} onClick={() => onPick(hoy)} className={`${PIE} hover:bg-accent-soft hover:text-accent`}>
          Hoy
        </button>
      </div>
    </div>
  )
}

const CAJA_CAL = 'w-[252px] rounded-[14px] border border-line bg-pop p-3 shadow-cal max-sm:w-[290px] dark:shadow-[0_20px_50px_rgba(0,0,0,.5)]'

/* Calendario suelto anclado a cualquier cosa (chip de fecha, botón de celda). */
export function DatePicker({
  open,
  onClose,
  anchor,
  value,
  onChange,
}: {
  open: boolean
  onClose: () => void
  anchor: AnclaPopover
  value: string | null
  onChange: (iso: string | null) => void
}) {
  return open ? <DatePickerAbierto onClose={onClose} anchor={anchor} value={value} onChange={onChange} /> : null
}

function DatePickerAbierto({ onClose, anchor, value, onChange }: Omit<Parameters<typeof DatePicker>[0], 'open'>) {
  const idBase = useId()
  const [foco, setFoco] = useState(value ?? isoDia(new Date()))
  const [vista, setVista] = useState<Vista>(() => vistaDe(value ?? isoDia(new Date())))
  const elegir = (iso: string | null) => {
    onClose()
    if (iso !== value) onChange(iso)
  }
  return (
    <Popover
      open
      onClose={onClose}
      anchor={anchor}
      offset={6}
      unstyled
      tabIndex={-1}
      initialFocus="panel"
      aria-label="Calendario"
      aria-activedescendant={`${idBase}-${foco}`}
      className={`${CAJA_CAL} outline-none`}
      onKeyDown={(e) => {
        if (e.key === 'Enter') {
          e.preventDefault()
          elegir(foco)
          return
        }
        const n = teclaCalendario(e, foco, true)
        if (!n) return
        e.preventDefault()
        setFoco(n)
        setVista(vistaDe(n))
      }}
    >
      <PanelCalendario value={value} foco={foco} vista={vista} setVista={setVista} onPick={elegir} onBorrar={() => elegir(null)} idBase={idBase} />
    </Popover>
  )
}

type DateInputProps = {
  value: string | null | undefined
  onChange: (iso: string | null) => void
  placeholder?: string
  variant?: 'box' | 'inline'
  size?: 'md' | 'sm'
  /* Color de la fecha según su valor (vencida / vence pronto). */
  tone?: (iso: string) => 'late' | 'soon' | null
  disabled?: boolean
  invalid?: boolean
  id?: string
  'aria-label'?: string
  className?: string
}

/* Campo de fecha del ERP (input.dpick): se ve «dd/mm/aa», admite escribir la
   fecha a mano y abre el calendario (lunes primero, Borrar/Hoy). Abrir no
   cambia nada: solo se guarda al elegir un día o al confirmar lo escrito. */
export function DateInput({
  value,
  onChange,
  placeholder = 'dd/mm/aa',
  variant = 'box',
  size = 'md',
  tone,
  disabled = false,
  invalid,
  id,
  'aria-label': ariaLabel,
  className = '',
}: DateInputProps) {
  const campo = useCampo()
  const idBase = useId()
  const input = useRef<HTMLInputElement>(null)
  const [abierto, setAbierto] = useState(false)
  // null = no se está escribiendo: se enseña el valor formateado.
  const [borrador, setBorrador] = useState<string | null>(null)
  const [foco, setFoco] = useState(isoDia(new Date()))
  const [vista, setVista] = useState<Vista>(() => vistaDe(isoDia(new Date())))

  const actual = value ?? null

  function abrir() {
    if (disabled || abierto) return
    const base = actual ?? isoDia(new Date())
    setFoco(base)
    setVista(vistaDe(base))
    setAbierto(true)
  }

  function confirmar() {
    if (borrador === null) return
    const t = borrador.trim()
    setBorrador(null)
    if (!t) {
      if (actual !== null) onChange(null)
      return
    }
    // Inválido: vuelve a lo que había (como el ERP).
    const iso = parsearFecha(t)
    if (iso && iso !== actual) onChange(iso)
  }

  function elegir(iso: string | null) {
    setBorrador(null)
    setAbierto(false)
    if (iso !== actual) onChange(iso)
  }

  const t = actual && tone ? tone(actual) : null
  const colorTono = t === 'late' ? '!text-[#e5484d] font-semibold' : t === 'soon' ? '!text-[#e0a000] font-semibold' : ''

  return (
    <>
      <div className={`relative ${className}`}>
        <input
          ref={input}
          id={id ?? campo?.id}
          type="text"
          inputMode="numeric"
          autoComplete="off"
          disabled={disabled}
          placeholder={placeholder}
          aria-label={ariaLabel}
          aria-invalid={(invalid ?? campo?.invalid) || undefined}
          aria-describedby={campo?.ayudaId}
          aria-haspopup="dialog"
          aria-expanded={abierto}
          value={borrador ?? fechaCorta(actual)}
          onChange={(e) => setBorrador(e.target.value)}
          onFocus={abrir}
          onClick={(e) => {
            e.stopPropagation()
            abrir()
          }}
          onBlur={() => {
            confirmar()
            setAbierto(false)
          }}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault()
              if (borrador !== null) confirmar()
              else if (abierto) elegir(foco)
              setAbierto(false)
              return
            }
            if (!abierto) {
              if (e.key === 'ArrowDown') {
                e.preventDefault()
                abrir()
              }
              return
            }
            const n = teclaCalendario(e, foco, false)
            if (!n) return
            e.preventDefault()
            setFoco(n)
            setVista(vistaDe(n))
          }}
          className={`${claseCampo({ variant, size, invalid: invalid ?? campo?.invalid })} ${variant === 'box' ? '!pr-9' : ''} ${colorTono}`}
        />
        {variant === 'box' && <CalendarDays className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-label" aria-hidden="true" />}
      </div>
      <Popover
        open={abierto}
        onClose={() => setAbierto(false)}
        anchor={input}
        offset={6}
        unstyled
        returnFocus={false}
        aria-label="Calendario"
        className={CAJA_CAL}
        // Que el clic en el calendario no quite el foco al input (si no, el blur cerraría antes de elegir).
        onMouseDown={(e) => e.preventDefault()}
      >
        <PanelCalendario value={actual} foco={foco} vista={vista} setVista={setVista} onPick={elegir} onBorrar={() => elegir(null)} idBase={idBase} />
      </Popover>
    </>
  )
}

export default DateInput
