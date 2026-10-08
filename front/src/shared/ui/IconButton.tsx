import type { ButtonHTMLAttributes, ReactNode, Ref } from 'react'

type Props = Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'> & {
  /* Texto para lectores de pantalla y tooltip: el botón solo tiene icono. */
  label: string
  icon: ReactNode
  tone?: 'default' | 'danger'
  size?: 26 | 30 | 34
  ref?: Ref<HTMLButtonElement>
}

const TAM = { 26: 'size-[26px]', 30: 'size-[30px]', 34: 'size-[34px]' }

/* Botón de solo icono (.icon-btn, .ck-x, .cdel). */
export default function IconButton({ label, icon, tone = 'default', size = 30, className = '', title, ...resto }: Props) {
  return (
    <button
      type="button"
      aria-label={label}
      title={title ?? label}
      {...resto}
      className={`inline-flex shrink-0 items-center justify-center rounded-md text-label transition-colors disabled:pointer-events-none disabled:opacity-50 [&>svg]:size-[15px] ${TAM[size]} ${
        tone === 'danger'
          ? 'hover:bg-[#fde8e8] hover:text-[#c0392b] dark:hover:bg-danger-bg dark:hover:text-danger'
          : 'hover:bg-chip hover:text-[#6b7280] dark:hover:text-ink'
      } ${className}`}
    >
      {icon}
    </button>
  )
}
