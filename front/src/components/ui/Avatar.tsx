import { cn } from '../../utils/cn'

export default function Avatar({
  nombre,
  className,
}: {
  nombre: string | null | undefined
  className?: string
}) {
  const inicial =
    (nombre || '')
      .trim()
      .split(/\s+/)
      .map((p) => p[0])
      .join('')
      .slice(0, 2)
      .toUpperCase() || '?'

  return (
    <div
      className={cn(
        'inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-600 text-[10px] font-semibold text-white',
        className,
      )}
    >
      {inicial}
    </div>
  )
}
