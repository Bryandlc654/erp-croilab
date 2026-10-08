/* Clases compartidas de botones, campos y segmentados: aparte de los
   componentes para que también las usen piezas que no son el componente
   (un enlace con aspecto de botón, un menú dentro de un segmentado). */

export type ButtonVariant = 'primary' | 'ghost' | 'danger' | 'subtle' | 'link'
export type ButtonSize = 'md' | 'sm'

const BASE =
  'inline-flex shrink-0 items-center justify-center whitespace-nowrap font-semibold transition-[background-color,border-color,color,box-shadow,transform] duration-150 ease-erp disabled:pointer-events-none disabled:opacity-60 [&>svg]:shrink-0'

const TAM: Record<ButtonSize, string> = {
  md: 'gap-[7px] rounded-[10px] px-4 py-2.5 text-[13.5px] max-sm:min-h-[42px] [&>svg]:size-4',
  sm: 'gap-1.5 rounded-[9px] px-3 py-[7px] text-[12.5px] max-sm:min-h-[38px] [&>svg]:size-[15px]',
}

const VAR: Record<ButtonVariant, string> = {
  primary: 'border border-transparent bg-accent text-white hover:-translate-y-px hover:shadow-btn active:translate-y-0 active:scale-[.98] active:shadow-none dark:text-accent-fg',
  ghost:
    'border border-line bg-card text-ink hover:-translate-y-px hover:border-line-strong hover:bg-soft hover:shadow-btn-ghost active:translate-y-px active:scale-[.985] active:shadow-none',
  danger:
    'border border-[#ecd4d1] bg-card text-[#b23b30] hover:-translate-y-px hover:border-[#e2bfbb] hover:bg-[#fbf3f2] active:translate-y-px active:scale-[.985] dark:border-danger-line dark:text-danger dark:hover:border-danger-line dark:hover:bg-danger-bg',
  subtle: 'border border-transparent bg-soft text-[#5a5f68] hover:bg-[#eeeef0] active:scale-[.985] dark:text-ink dark:hover:bg-line-strong',
  link: 'border border-transparent !px-0 text-muted underline-offset-[3px] hover:text-ink-strong hover:underline',
}

export function claseBoton({ variant = 'primary', size = 'md', className = '' }: { variant?: ButtonVariant; size?: ButtonSize; className?: string } = {}) {
  return `${BASE} ${TAM[size]} ${VAR[variant]} ${className}`
}

export type VarianteCampo = 'box' | 'inline'
export type TamCampo = 'md' | 'sm'

const CAJA =
  'w-full rounded-[10px] border bg-field text-ink placeholder:text-label/80 transition-[border-color,background-color] duration-150 focus:outline-none disabled:cursor-not-allowed disabled:bg-soft disabled:opacity-70 max-sm:text-[16px]'
const CAJA_TAM: Record<TamCampo, string> = { md: 'px-[13px] py-2.5 text-[14px]', sm: 'px-[11px] py-[7px] text-[12.5px] rounded-[9px]' }
const EN_LINEA =
  'w-full rounded-[7px] border border-transparent bg-transparent px-2 py-1.5 text-[13px] text-ink transition-[background-color,box-shadow] hover:bg-soft focus:bg-card focus:shadow-[0_0_0_2px_rgba(17,19,24,.08)] focus:outline-none dark:focus:shadow-[0_0_0_2px_rgba(255,255,255,.12)]'

export function claseCampo({ variant = 'box', size = 'md', invalid = false }: { variant?: VarianteCampo; size?: TamCampo; invalid?: boolean } = {}) {
  if (variant === 'inline') return `${EN_LINEA} ${invalid ? '!border-[#ef4444]' : ''}`
  return `${CAJA} ${CAJA_TAM[size]} ${invalid ? 'border-[#ef4444] focus:border-[#ef4444]' : 'border-line hover:border-line-strong focus:border-ring'}`
}

export type VarianteSegmentado = 'dark' | 'pill' | 'underline'
export const CAJA_SEGMENTADO: Record<VarianteSegmentado, string> = {
  dark: 'inline-flex max-w-full flex-wrap gap-1 rounded-[11px] border border-line bg-card p-1',
  pill: 'inline-flex max-w-full gap-1 rounded-[11px] bg-soft p-[3px] dark:bg-soft',
  underline: 'flex max-w-full gap-0.5 overflow-x-auto border-b border-line',
}

const SEG: Record<VarianteSegmentado, { base: string; on: string; off: string }> = {
  dark: {
    base: 'inline-flex items-center gap-[7px] whitespace-nowrap rounded-lg px-3.5 py-[7px] text-[13.5px] font-semibold transition-colors duration-[140ms] max-sm:min-h-[38px] [&_svg]:size-[15px]',
    on: 'bg-tab-on text-white dark:bg-rev dark:text-rev-fg',
    off: 'text-[#6b7280] hover:bg-soft hover:text-ink dark:text-muted',
  },
  pill: {
    base: 'inline-flex flex-1 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-2 text-[12.5px] font-semibold transition-[background-color,color,box-shadow] duration-[140ms] [&_svg]:size-[14px]',
    on: 'bg-card text-ink-strong shadow-[0_1px_3px_rgba(0,0,0,.08)]',
    off: 'text-[#6b7280] hover:text-ink dark:text-muted',
  },
  underline: {
    base: '-mb-px inline-flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-[13px] font-semibold transition-colors [&_svg]:size-[15px]',
    on: 'border-accent text-ink-strong',
    off: 'border-transparent text-muted hover:text-ink',
  },
}

/* Clases de un segmento suelto, para piezas que van dentro del grupo pero no
   son un segmento normal (p. ej. un menú «Tareas de…»). */
export function claseSegmento(on: boolean, variant: VarianteSegmentado = 'dark') {
  const s = SEG[variant]
  return `${s.base} ${on ? s.on : s.off}`
}

