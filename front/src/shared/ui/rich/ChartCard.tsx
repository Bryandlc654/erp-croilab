import { lazy, Suspense, useEffect, useRef, useState, type CSSProperties, type ReactNode } from 'react'
import type { ChartOptions } from 'chart.js'
import { hayDatos, type DatosGrafica, type TipoGrafica } from './charts'

// Chart.js va en su propio trozo: solo se descarga cuando hay una gráfica en pantalla.
const GraficaChartjs = lazy(() => import('./GraficaChartjs'))

/* ¿Está dentro de modo oscuro? Mira la clase .dark del ancestro más cercano
   (la de <html> o la de la galería) y sigue los cambios de tema. */
function useOscuro() {
  const ref = useRef<HTMLDivElement>(null)
  const [oscuro, setOscuro] = useState(false)
  useEffect(() => {
    const leer = () => setOscuro(!!ref.current?.closest('.dark'))
    leer()
    const mo = new MutationObserver(leer)
    mo.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })
    return () => mo.disconnect()
  }, [])
  return { ref, oscuro }
}

export type ChartCardProps = {
  title: ReactNode
  type: TipoGrafica
  data: DatosGrafica
  /* A todo el ancho de la rejilla (.db-card.wide). */
  wide?: boolean
  /* Alto de la caja de la gráfica (240; 220 en móvil). */
  height?: number
  /* Formato de ejes y tooltips (p. ej. eurk de formato.ts). */
  formatValue?: (n: number) => string
  /* Barras apiladas. */
  stacked?: boolean
  /* Opciones de Chart.js que se mezclan por encima (escape). */
  options?: ChartOptions<'bar' | 'line' | 'doughnut'>
  /* Fuerza el estado vacío (si no, se deduce de los datos). */
  empty?: boolean
  emptyText?: ReactNode
  /* A la derecha del título (filtro, enlace «Ver todo»). */
  action?: ReactNode
  /* Debajo de la gráfica (leyenda propia, totales). */
  children?: ReactNode
  className?: string
}

/* Tarjeta con gráfica (.db-card del dashboard CRM y del resumen financiero). */
export default function ChartCard({ title, type, data, wide = false, height = 240, formatValue, stacked, options, empty, emptyText = 'Sin datos todavía', action, children, className = '' }: ChartCardProps) {
  const { ref, oscuro } = useOscuro()
  const vacio = empty ?? !hayDatos(data)
  const etiqueta = typeof title === 'string' ? title : 'Gráfica'
  return (
    <section ref={ref} className={`min-w-0 rounded-2xl border border-line bg-card px-[22px] py-5 max-sm:px-4 max-sm:py-[18px] ${wide ? 'col-span-full' : ''} ${className}`}>
      <header className="mb-3.5 flex items-start justify-between gap-3">
        <h3 className="text-[15px] leading-snug font-[650] text-ink-strong">{title}</h3>
        {action}
      </header>
      {vacio ? (
        <div className="flex items-center justify-center py-[60px] text-center text-[13px] text-muted" style={{ minHeight: height - 40 }}>
          {emptyText}
        </div>
      ) : (
        <div className="relative h-[var(--alto)] max-sm:h-[calc(var(--alto)-20px)]" style={{ '--alto': `${height}px` } as CSSProperties}>
          <Suspense fallback={<div className="size-full" aria-busy="true" />}>
            <GraficaChartjs type={type} data={data} oscuro={oscuro} formatValue={formatValue} stacked={stacked} options={options} label={etiqueta} />
          </Suspense>
          {/* Los datos en texto, para lectores de pantalla. */}
          <ul className="sr-only">
            {data.labels.map((l, i) => (
              <li key={i}>
                {l}: {data.series.map((s) => `${s.label ? s.label + ' ' : ''}${formatValue ? formatValue(s.data[i] ?? 0) : (s.data[i] ?? 0)}`).join(', ')}
              </li>
            ))}
          </ul>
        </div>
      )}
      {children}
    </section>
  )
}

/* Rejilla de gráficas: auto-fit minmax(260px, 1fr), gap 16. */
export function ChartGrid({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`grid grid-cols-[repeat(auto-fit,minmax(260px,1fr))] gap-4 ${className}`}>{children}</div>
}
