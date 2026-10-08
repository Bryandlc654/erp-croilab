import { ArcElement, BarController, BarElement, CategoryScale, Chart as ChartJS, DoughnutController, Filler, Legend, LinearScale, LineController, LineElement, PointElement, Tooltip } from 'chart.js'
import { Chart } from 'react-chartjs-2'
import type { ChartOptions } from 'chart.js'
import { configGrafica, type DatosGrafica, type TipoGrafica } from './charts'

/* La gráfica de verdad (Chart.js + react-chartjs-2): va en su propio trozo y
   solo se descarga con la primera ChartCard. No la importes directamente. */

// Solo lo que se usa: barras, líneas y donut.
ChartJS.register(BarController, LineController, DoughnutController, BarElement, LineElement, PointElement, ArcElement, CategoryScale, LinearScale, Tooltip, Legend, Filler)
ChartJS.defaults.font.family = '"Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif'
ChartJS.defaults.font.size = 11.5

export default function GraficaChartjs({
  type,
  data,
  oscuro,
  formatValue,
  stacked,
  options,
  label,
}: {
  type: TipoGrafica
  data: DatosGrafica
  oscuro: boolean
  formatValue?: (n: number) => string
  stacked?: boolean
  options?: ChartOptions<'bar' | 'line' | 'doughnut'>
  label: string
}) {
  const cfg = configGrafica(type, data, { oscuro, formato: formatValue, apilado: stacked })
  // Las opciones de fuera se mezclan por encima (los plugins, uno a uno).
  const opciones = (options ? { ...cfg.options, ...options, plugins: { ...cfg.options?.plugins, ...options.plugins } } : cfg.options) as typeof cfg.options
  return <Chart type={cfg.type} data={cfg.data} options={opciones} aria-label={label} role="img" />
}
