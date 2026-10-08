import type { ElementType, ReactNode } from 'react'

const PAD = {
  lg: 'px-[26px] py-6 max-sm:px-4 max-sm:py-[18px]',
  md: 'px-6 py-[22px] max-sm:px-4 max-sm:py-[18px]',
  sm: 'px-[18px] py-4',
  none: '',
}

/* Tarjeta blanca con borde y radio 16 (.card, .panel, .set-card). Sin sombra
   en reposo, como en el ERP. */
export default function Card({
  padding = 'lg',
  as: Tag = 'section',
  className = '',
  children,
}: {
  padding?: keyof typeof PAD
  as?: ElementType
  className?: string
  children: ReactNode
}) {
  return <Tag className={`rounded-2xl border border-line bg-card ${PAD[padding]} ${className}`}>{children}</Tag>
}

/* Cabecera de tarjeta: título 16/600 con icono, o «ceja» (12px en mayúsculas)
   con `eyebrow`; acción a la derecha («Ver todo», «Abrir →»). */
export function CardHeader({
  title,
  icon,
  action,
  eyebrow = false,
  subtitle,
  className = '',
}: {
  title: ReactNode
  icon?: ReactNode
  action?: ReactNode
  eyebrow?: boolean
  subtitle?: ReactNode
  className?: string
}) {
  return (
    <div className={`mb-4 flex items-start justify-between gap-3 ${className}`}>
      <div className="min-w-0">
        <h3
          className={
            eyebrow
              ? 'flex items-center gap-2 text-[12px] font-[650] tracking-[.5px] text-muted uppercase [&>svg]:size-3.5'
              : 'flex items-center gap-2 text-[16px] font-semibold text-ink-strong [&>svg]:size-[17px] [&>svg]:text-muted'
          }
        >
          {icon}
          {title}
        </h3>
        {subtitle && <p className="mt-1 text-[12.5px] text-muted">{subtitle}</p>}
      </div>
      {action && <div className="flex shrink-0 items-center gap-2 text-[12.5px] font-semibold text-muted">{action}</div>}
    </div>
  )
}

/* Panel = tarjeta con título (.panel). */
export function Panel({ title, icon, action, className = '', children }: { title?: ReactNode; icon?: ReactNode; action?: ReactNode; className?: string; children: ReactNode }) {
  return (
    <Card padding="md" className={className}>
      {title && <CardHeader title={title} icon={icon} action={action} />}
      {children}
    </Card>
  )
}
