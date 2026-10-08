import type { InputHTMLAttributes, ReactNode, Ref, TextareaHTMLAttributes } from 'react'
import { useCampo } from './campoContexto'
import { claseCampo, type VarianteCampo as Variante, type TamCampo as Tam } from './clases'

type InputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'size'> & {
  invalid?: boolean
  leftIcon?: ReactNode
  /* Unidad pegada a la derecha dentro del campo: '%', '€', 'h'. */
  unit?: string
  size?: Tam
  variant?: Variante
  ref?: Ref<HTMLInputElement>
}

/* Campo de texto del ERP (inputs globales, celdas .ed en línea). */
export function TextInput({ invalid, leftIcon, unit, size = 'md', variant = 'box', className = '', id, ...resto }: InputProps) {
  const campo = useCampo()
  const inv = invalid ?? campo?.invalid ?? false
  const input = (
    <input
      id={id ?? campo?.id}
      aria-invalid={inv || undefined}
      aria-describedby={campo?.ayudaId}
      aria-required={campo?.required || undefined}
      className={`${claseCampo({ variant, size, invalid: inv })} ${leftIcon ? '!pl-9' : ''} ${unit ? '!pr-[30px]' : ''} ${leftIcon || unit ? '' : className}`}
      {...resto}
    />
  )
  if (!leftIcon && !unit) return input
  return (
    <div className={`relative ${className}`}>
      {leftIcon && <span className="pointer-events-none absolute top-1/2 left-3 flex -translate-y-1/2 text-label [&>svg]:size-4">{leftIcon}</span>}
      {input}
      {unit && <span className="pointer-events-none absolute top-1/2 right-[11px] -translate-y-1/2 text-[12.5px] text-muted">{unit}</span>}
    </div>
  )
}

type AreaProps = TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean; variant?: Variante; ref?: Ref<HTMLTextAreaElement> }

export function TextArea({ invalid, variant = 'box', className = '', id, ...resto }: AreaProps) {
  const campo = useCampo()
  const inv = invalid ?? campo?.invalid ?? false
  return (
    <textarea
      id={id ?? campo?.id}
      aria-invalid={inv || undefined}
      aria-describedby={campo?.ayudaId}
      className={`${claseCampo({ variant, invalid: inv })} min-h-[70px] resize-y leading-[1.5] ${className}`}
      {...resto}
    />
  )
}

export default TextInput
