export type BadgeTone = 'red' | 'neutral' | 'green' | 'dark' | 'amber' | 'plain' | 'rail'

const TONO: Record<BadgeTone, string> = {
  red: 'rounded-full bg-badge-bg px-2 text-badge-fg',
  neutral: 'rounded-full bg-soft px-2 text-label',
  green: 'rounded-full bg-[#12a150] px-[7px] text-white',
  dark: 'rounded-full bg-accent px-[7px] text-white dark:text-accent-fg',
  amber: 'rounded-full bg-[#fff4e5] px-2 text-[#b7791f] dark:bg-[#2e2412] dark:text-warn',
  plain: 'text-label',
  // Globo de la campana del raíl: rojo sólido recortado sobre el raíl negro.
  rail: 'flex h-[18px] min-w-[18px] items-center justify-center rounded-full border-2 border-[#0f1012] bg-[#ef4444] px-[3px] !text-[8.5px] text-white',
}

/* Contadores (.cnt, .rbadge): «9+» o «99+» por encima del máximo; 0 no se pinta. */
export default function CountBadge({
  n,
  tone = 'red',
  max = 99,
  showZero = false,
  className = '',
  label,
}: {
  n: number
  tone?: BadgeTone
  max?: number
  showZero?: boolean
  className?: string
  /* Texto para lectores de pantalla («3 sin leer»). */
  label?: string
}) {
  if (n <= 0 && !showZero) return null
  return (
    <span className={`inline-block shrink-0 text-[11px] leading-[1.6] font-bold tabular-nums ${TONO[tone]} ${className}`} aria-label={label}>
      {n > max ? `${max}+` : n}
    </span>
  )
}
