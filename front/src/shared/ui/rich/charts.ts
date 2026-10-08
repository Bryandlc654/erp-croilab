import type { ChartConfiguration, ChartOptions, ScriptableContext, TooltipItem } from 'chart.js'
import { PALETA_GRAFICAS } from '../../lib/paletas'

/* Configuración de las gráficas del ERP (crm_dashboard / fin-resumen, Chart.js
   4.4 en el antiguo): defaults, paleta y modo oscuro. Solo TIPOS de chart.js:
   la librería se carga aparte, con la gráfica. */

export type TipoGrafica = 'bar' | 'barH' | 'doughnut' | 'line' | 'lineaFinanzas'

export type SerieGrafica = {
  label: string
  data: number[]
  /* Un color, o uno por barra/sector. Por defecto, la paleta en orden. */
  color?: string | string[]
}

export type DatosGrafica = { labels: string[]; series: SerieGrafica[] }

export const PALETA = PALETA_GRAFICAS

export type ColoresGrafica = {
  texto: string
  rejilla: string
  borde: string
  tooltipFondo: string
  tooltipTexto: string
  linea: string
  degradado: [string, string]
}

export function coloresGrafica(oscuro: boolean): ColoresGrafica {
  return oscuro
    ? { texto: '#8f8f8f', rejilla: '#262626', borde: '#161616', tooltipFondo: '#e5e5e5', tooltipTexto: '#171717', linea: '#e5e5e5', degradado: ['rgba(229,229,229,.14)', 'rgba(229,229,229,0)'] }
    : { texto: '#9aa0a8', rejilla: '#f0f0f2', borde: '#ffffff', tooltipFondo: '#111318', tooltipTexto: '#ffffff', linea: '#111318', degradado: ['rgba(17,19,24,.10)', 'rgba(17,19,24,0)'] }
}

/* ¿Hay algo que pintar? Sin etiquetas o sin valores → «Sin datos todavía». */
export function hayDatos(d: DatosGrafica) {
  return d.labels.length > 0 && d.series.some((s) => s.data.length > 0)
}

/* '#5b8def' + alfa → '#5b8def1a' (relleno de líneas al 10 %). */
export function conAlfa(hex: string, alfa: number) {
  if (!/^#[0-9a-f]{6}$/i.test(hex)) return hex
  return hex + Math.round(Math.max(0, Math.min(1, alfa)) * 255).toString(16).padStart(2, '0')
}

const colorSerie = (s: SerieGrafica, i: number) => s.color ?? PALETA[i % PALETA.length]

type Opciones = { oscuro: boolean; formato?: (n: number) => string; apilado?: boolean }
type Config = ChartConfiguration<'bar' | 'line' | 'doughnut'>

export function configGrafica(tipo: TipoGrafica, datos: DatosGrafica, { oscuro, formato, apilado = false }: Opciones): Config {
  const c = coloresGrafica(oscuro)
  const fmt = formato ?? ((n: number) => n.toLocaleString('es-ES'))
  const leyenda = { display: datos.series.length > 1 || tipo === 'doughnut', position: 'top' as const, labels: { boxWidth: 12, padding: 12, color: c.texto } }
  const tooltip = {
    backgroundColor: c.tooltipFondo,
    titleColor: c.tooltipTexto,
    bodyColor: c.tooltipTexto,
    padding: 10,
    cornerRadius: 8,
    displayColors: datos.series.length > 1 || tipo === 'doughnut',
    callbacks: {
      label: (it: TooltipItem<'bar' | 'line' | 'doughnut'>) => {
        const v = typeof it.raw === 'number' ? it.raw : Number(it.parsed && typeof it.parsed === 'object' ? (tipo === 'barH' ? (it.parsed as { x: number }).x : (it.parsed as { y: number }).y) : it.parsed)
        const nombre = tipo === 'doughnut' ? it.label : it.dataset.label
        return `${nombre ? nombre + ': ' : ''}${fmt(v)}`
      },
    },
  }
  // Si todo son enteros (contactos, negocios…), el eje no enseña «0,5».
  const enteros = datos.series.every((s) => s.data.every((v) => Number.isInteger(v)))
  const ejeValor = {
    beginAtZero: true,
    stacked: apilado,
    grid: { color: c.rejilla },
    border: { display: false },
    ticks: { color: c.texto, precision: enteros ? 0 : undefined, callback: (v: string | number) => fmt(Number(v)) },
  }
  const ejeCategoria = { stacked: apilado, grid: { display: false }, border: { display: false }, ticks: { color: c.texto } }
  const base: ChartOptions<'bar' | 'line' | 'doughnut'> = {
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 450 },
    plugins: { legend: leyenda, tooltip },
  }

  if (tipo === 'doughnut') {
    const s = datos.series[0] ?? { label: '', data: [] }
    const colores = Array.isArray(s.color) ? s.color : datos.labels.map((_, i) => (typeof s.color === 'string' && i === 0 ? s.color : PALETA[i % PALETA.length]))
    return {
      type: 'doughnut',
      data: { labels: datos.labels, datasets: [{ label: s.label, data: s.data, backgroundColor: colores, borderColor: c.borde, borderWidth: 2, hoverOffset: 4 }] },
      options: { ...base, cutout: '62%', plugins: { ...base.plugins, legend: { ...leyenda, position: 'right' } } } as Config['options'],
    }
  }

  if (tipo === 'line' || tipo === 'lineaFinanzas') {
    const finanzas = tipo === 'lineaFinanzas'
    return {
      type: 'line',
      data: {
        labels: datos.labels,
        datasets: datos.series.map((s, i) => {
          const color = finanzas && !s.color ? c.linea : (colorSerie(s, i) as string)
          return {
            label: s.label,
            data: s.data,
            borderColor: color,
            borderWidth: 2,
            tension: 0.35,
            fill: true,
            pointRadius: finanzas ? 0 : 3,
            pointHoverRadius: 5,
            pointBackgroundColor: color,
            // Resumen financiero: degradado de arriba (10 %) a transparente.
            backgroundColor: finanzas
              ? (ctx: ScriptableContext<'line'>) => {
                  const { ctx: g, chartArea } = ctx.chart
                  if (!chartArea) return 'transparent'
                  const grad = g.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                  grad.addColorStop(0, c.degradado[0])
                  grad.addColorStop(1, c.degradado[1])
                  return grad
                }
              : conAlfa(color, 0.1),
          }
        }),
      },
      options: {
        ...base,
        interaction: { mode: 'index', intersect: false },
        scales: { x: ejeCategoria, y: { ...ejeValor, grid: { color: finanzas ? (oscuro ? '#262626' : '#f0f1f3') : c.rejilla } } },
      } as Config['options'],
    }
  }

  const horizontal = tipo === 'barH'
  return {
    type: 'bar',
    data: {
      labels: datos.labels,
      datasets: datos.series.map((s, i) => ({ label: s.label, data: s.data, backgroundColor: colorSerie(s, i), borderRadius: 6, maxBarThickness: 46, borderSkipped: false })),
    },
    options: {
      ...base,
      indexAxis: horizontal ? 'y' : 'x',
      scales: horizontal ? { x: ejeValor, y: ejeCategoria } : { x: ejeCategoria, y: ejeValor },
    } as Config['options'],
  }
}
