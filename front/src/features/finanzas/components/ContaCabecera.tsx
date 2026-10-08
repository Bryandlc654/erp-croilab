import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { ChevronDown, Download } from 'lucide-react'
import Menu, { MenuItem } from '../../../shared/ui/Menu'
import Segmented from '../../../shared/ui/Segmented'
import Select from '../../../shared/ui/Select'

/* Cabecera común de Contabilidad: título, explicación del criterio de caja,
   páginas Movimientos | Análisis, ámbito (Hub | emisores), año y exportar. */
export default function ContaCabecera({
  pagina,
  ambito,
  anio,
  ambitos,
  anios,
  onAmbito,
  onAnio,
  onExport,
}: {
  pagina: 'movimientos' | 'analisis'
  ambito: string
  anio: number
  ambitos: { clave: string; nombre: string }[]
  anios: number[]
  onAmbito: (a: string) => void
  onAnio: (a: number) => void
  onExport?: (formato: 'xls' | 'csv') => void
}) {
  const q = `?ambito=${encodeURIComponent(ambito)}&anio=${anio}`
  return (
    <>
      <header className="mb-5 flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
        <h1 className="text-[26px] leading-[1.2] font-semibold tracking-[-.5px] text-ink-strong max-sm:text-[23px]">Contabilidad</h1>
        <div className="flex flex-wrap items-start gap-4 max-lg:w-full">
          {pagina === 'movimientos' && (
            <p className="max-w-[560px] text-[12.5px] leading-[1.55] text-muted">
              Criterio de <b className="text-ink-strong">caja</b>: aquí solo cuenta lo que se ha <b className="text-ink-strong">cobrado</b> de verdad. El total <b className="text-ink-strong">facturado</b>{' '}
              (emitido, esté cobrado o no) está en{' '}
              <Link to="/finanzas" className="font-semibold text-ink-strong hover:underline">
                Resumen mensual
              </Link>{' '}
              — por eso las dos cifras no tienen por qué coincidir.
            </p>
          )}
          <Segmented
            value={pagina}
            items={[
              { value: 'movimientos', label: 'Movimientos', href: `/finanzas/contabilidad${q}` },
              { value: 'analisis', label: 'Análisis', href: `/finanzas/contabilidad/analisis${q}` },
            ]}
            aria-label="Página"
          />
        </div>
      </header>
      <div className="mb-5 flex flex-wrap items-center gap-2.5">
        <Segmented value={ambito} onChange={onAmbito} items={ambitos.map((a) => ({ value: a.clave, label: a.nombre }))} aria-label="Ámbito" />
        <div className="w-[110px]">
          <Select value={anio} onChange={(v) => onAnio(Number(v))} options={anios.map((a) => ({ value: a, label: String(a) }))} aria-label="Año" />
        </div>
        {onExport && (
          <Menu
            label="Exportar"
            trigger={(abierto) => (
              <span className="inline-flex items-center gap-1.5 rounded-[9px] border border-line bg-card px-3 py-[9px] text-[12.5px] font-semibold text-ink hover:bg-soft">
                <Download className="size-[15px]" /> Exportar <ChevronDown className={`size-3.5 transition-transform ${abierto ? 'rotate-180' : ''}`} />
              </span>
            )}
          >
            <MenuItem onClick={() => onExport('xls')}>Excel (.xls)</MenuItem>
            <MenuItem onClick={() => onExport('csv')}>CSV (.csv)</MenuItem>
          </Menu>
        )}
      </div>
    </>
  )
}

/* Tarjeta de KPI de contabilidad: etiqueta, cifra y una línea. */
export function KpiConta({ label, value, sub, tono }: { label: string; value: string; sub?: ReactNode; tono?: 'ok' | 'mal' }) {
  return (
    <div className="rounded-2xl border border-line bg-card px-6 py-[22px] max-sm:px-4">
      <span className="text-[13px] text-ink">{label}</span>
      <b className={`mt-3 block text-[29px] leading-none font-[700] tracking-[-.7px] tabular-nums max-sm:text-[24px] ${tono === 'ok' ? 'text-[#12854a] dark:text-ok' : tono === 'mal' ? 'text-[#e5484d] dark:text-danger' : 'text-ink-strong'}`}>
        {value}
      </b>
      {sub && <p className="mt-3 text-[12.5px] text-muted">{sub}</p>}
    </div>
  )
}
