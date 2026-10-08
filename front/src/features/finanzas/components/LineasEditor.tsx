import { Plus, X } from 'lucide-react'
import { eurC, leer, linea } from '../lib/importes'
import { nuevaLinea, type LineaForm } from '../lib/cuerpos'

const CAMPO =
  'w-full min-w-0 rounded-[9px] border border-line bg-field px-2.5 py-[9px] text-[13px] text-ink outline-none transition-colors placeholder:text-label/80 hover:border-line-strong focus:border-ring max-sm:px-1.5 max-sm:text-[16px]'

/* Líneas de la factura (.fe-lines): Detalle · Cant. · Precio · Total · ✕, con
   el total de cada línea calculado con la regla del servidor. Cantidad y precio
   admiten «1.234,56». */
export default function LineasEditor({
  lineas,
  onChange,
  conTotal = true,
  placeholder = 'Concepto…',
  sugerencias,
  disabled = false,
}: {
  lineas: LineaForm[]
  onChange: (ls: LineaForm[]) => void
  conTotal?: boolean
  placeholder?: string
  /* Conceptos sugeridos (datalist), p. ej. el catálogo de servicios. */
  sugerencias?: string[]
  disabled?: boolean
}) {
  const cols = conTotal ? 'grid-cols-[1fr_72px_100px_100px_30px] max-sm:grid-cols-[1fr_46px_66px_66px_24px]' : 'grid-cols-[1fr_72px_100px_30px] max-sm:grid-cols-[1fr_52px_76px_24px]'
  const listaId = sugerencias ? 'fin-conceptos' : undefined
  const cambiar = (key: string, campo: keyof Omit<LineaForm, 'key'>, v: string) => onChange(lineas.map((l) => (l.key === key ? { ...l, [campo]: v } : l)))
  return (
    <div>
      <div className={`grid ${cols} mb-1.5 gap-2 px-0.5 text-[10.5px] font-bold tracking-[.5px] text-muted uppercase max-sm:gap-1`}>
        <span>Detalle</span>
        <span className="text-right">Cant.</span>
        <span className="text-right">Precio</span>
        {conTotal && <span className="text-right">Total</span>}
        <span />
      </div>
      <div className="space-y-2">
        {lineas.map((l) => {
          const imp = linea(leer(l.cantidad) ?? '0', leer(l.precio) ?? '0')
          return (
            <div key={l.key} className={`grid ${cols} items-center gap-2 max-sm:gap-1`}>
              <input className={CAMPO} value={l.concepto} placeholder={placeholder} aria-label="Concepto" list={listaId} disabled={disabled} onChange={(e) => cambiar(l.key, 'concepto', e.target.value)} />
              <input className={`${CAMPO} text-right`} value={l.cantidad} inputMode="decimal" aria-label="Cantidad" disabled={disabled} onChange={(e) => cambiar(l.key, 'cantidad', e.target.value)} />
              <input className={`${CAMPO} text-right`} value={l.precio} inputMode="decimal" placeholder="0" aria-label="Precio" disabled={disabled} onChange={(e) => cambiar(l.key, 'precio', e.target.value)} />
              {conTotal && <span className="truncate rounded-[9px] bg-soft px-2 py-[9px] text-right text-[13px] font-semibold text-ink-strong tabular-nums max-sm:px-1 max-sm:text-[11.5px]">{eurC(imp)}</span>}
              <button
                type="button"
                aria-label="Quitar línea"
                disabled={disabled}
                onClick={() => onChange(lineas.length > 1 ? lineas.filter((x) => x.key !== l.key) : [nuevaLinea()])}
                className="flex size-[30px] items-center justify-center rounded-md text-label transition-colors hover:bg-[#fde8e8] hover:text-[#c0392b] max-sm:size-6 dark:hover:bg-danger-bg dark:hover:text-danger"
              >
                <X className="size-4" />
              </button>
            </div>
          )
        })}
      </div>
      {sugerencias && (
        <datalist id={listaId}>
          {sugerencias.map((s) => (
            <option key={s} value={s} />
          ))}
        </datalist>
      )}
      <button
        type="button"
        disabled={disabled}
        onClick={() => onChange([...lineas, nuevaLinea()])}
        className="mt-2.5 flex w-full items-center justify-center gap-1.5 rounded-[10px] border border-dashed border-[#d4d8de] bg-card p-2.5 text-[13.5px] font-semibold text-accent transition-colors hover:border-label hover:bg-soft dark:border-line-strong"
      >
        <Plus className="size-4" /> Añadir línea
      </button>
    </div>
  )
}
