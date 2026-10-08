import { useEffect, useRef, type InputHTMLAttributes, type ReactNode } from 'react'

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'size' | 'onChange'> & {
  checked: boolean
  onChange: (v: boolean) => void
  /* «Todos» a medias: algunas filas marcadas. */
  indeterminate?: boolean
  size?: 15 | 17
  label?: ReactNode
}

/* Casilla nativa con el color de acento del ERP; en móvil 20px para el dedo. */
export default function Checkbox({ checked, onChange, indeterminate = false, size = 15, label, className = '', ...resto }: Props) {
  const ref = useRef<HTMLInputElement>(null)
  useEffect(() => {
    // indeterminate solo existe como propiedad del DOM, no como atributo.
    if (ref.current) ref.current.indeterminate = indeterminate
  }, [indeterminate])
  const caja = (
    <input
      ref={ref}
      type="checkbox"
      checked={checked}
      aria-checked={indeterminate ? 'mixed' : checked}
      onChange={(e) => onChange(e.target.checked)}
      onClick={(e) => e.stopPropagation()}
      className={`shrink-0 cursor-pointer accent-accent max-sm:!size-5 ${size === 17 ? 'size-[17px]' : 'size-[15px]'} ${label ? '' : className}`}
      {...resto}
    />
  )
  if (!label) return caja
  return (
    <label className={`inline-flex cursor-pointer items-center gap-[9px] text-[13.5px] text-ink ${className}`}>
      {caja}
      {label}
    </label>
  )
}
