import { useId, type ReactNode } from 'react'

type Props = {
  checked: boolean
  onChange: (v: boolean) => void
  /* ok: verde cuando está encendido (solo para «está funcionando»). */
  tone?: 'default' | 'ok'
  /* Mientras se guarda: medio transparente y sin responder. */
  saving?: boolean
  disabled?: boolean
  /* Con etiqueta se pinta como fila (.erpag-sw / .ed-sw). */
  label?: ReactNode
  description?: ReactNode
  logo?: ReactNode
  /* box: fila con borde (.ed-sw); plain: fila sin caja (.erpag-sw). */
  rowVariant?: 'plain' | 'box'
  'aria-label'?: string
  className?: string
}

/* Interruptor del ERP (.sw): 40×23, bolita de 17px que se estira al pulsar. */
export default function Switch({
  checked,
  onChange,
  tone = 'default',
  saving = false,
  disabled = false,
  label,
  description,
  logo,
  rowVariant = 'plain',
  'aria-label': ariaLabel,
  className = '',
}: Props) {
  const id = useId()
  const bloqueado = disabled || saving
  const pista = (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label ? undefined : ariaLabel}
      aria-labelledby={label ? `${id}-t` : undefined}
      aria-describedby={description ? `${id}-d` : undefined}
      aria-busy={saving || undefined}
      disabled={disabled}
      onClick={(e) => {
        e.stopPropagation()
        if (!bloqueado) onChange(!checked)
      }}
      className={`group/sw relative inline-flex h-[23px] w-10 shrink-0 rounded-full transition-colors duration-[180ms] ease-erp focus-visible:shadow-[0_0_0_3px_var(--c-accent-soft)] focus-visible:outline-none disabled:opacity-50 ${
        checked ? (tone === 'ok' ? 'bg-[#12a150]' : 'bg-accent') : 'bg-[#d9dbe0] dark:bg-line-strong'
      } ${saving ? 'pointer-events-none opacity-50' : ''}`}
    >
      <span
        aria-hidden="true"
        className={`absolute top-[3px] h-[17px] w-[17px] rounded-full bg-white shadow-[0_1px_3px_rgba(0,0,0,.22)] transition-[transform,width,left] duration-[180ms] ease-erp group-active/sw:w-5 ${
          // En oscuro la pista encendida es clara: la bolita pasa a oscura para verse.
          checked && tone === 'default' ? 'dark:bg-accent-fg' : 'dark:bg-[#e9eaec]'
        } ${
          checked ? 'left-[20px] group-active/sw:left-[17px]' : 'left-[3px]'
        }`}
      />
    </button>
  )
  if (!label) return <span className={className}>{pista}</span>

  const fila =
    rowVariant === 'box'
      ? `rounded-[13px] border px-[17px] py-[15px] ${checked ? 'border-[#d6d7db] bg-accent-soft dark:border-line-strong' : 'border-line bg-page hover:border-line-strong hover:bg-soft'}`
      : 'rounded-xl px-2.5 py-[11px] hover:bg-soft'

  return (
    <div
      className={`flex cursor-pointer items-center gap-[13px] transition-colors ${fila} ${bloqueado ? 'cursor-default' : ''} ${className}`}
      onClick={() => !bloqueado && onChange(!checked)}
    >
      {logo && <span className="flex size-[26px] shrink-0 items-center justify-center">{logo}</span>}
      <span className="min-w-0 flex-1">
        <span id={`${id}-t`} className="block text-[13.5px] font-semibold text-ink-strong">
          {label}
        </span>
        {description && (
          <span id={`${id}-d`} className="mt-0.5 block text-[12px] leading-[1.45] text-muted">
            {description}
          </span>
        )}
      </span>
      {pista}
    </div>
  )
}
