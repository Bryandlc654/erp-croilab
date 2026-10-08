import type { ReactNode } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import IconButton from '../../../shared/ui/IconButton'

export type ColumnaEditable<T> = {
  titulo: string
  /* Ancho en la rejilla (CSS grid): '90px', '1fr', 'minmax(0,2fr)'… */
  ancho: string
  render: (fila: T, cambiar: (c: Partial<T>) => void, i: number) => ReactNode
}

/* Mini-tabla de filas repetibles de la ficha del cliente (.ed-lista): campos
   sin borde hasta pasar el ratón o enfocar, papelera al pasar por la fila y
   «+ Añadir…» como última fila. En el móvil cada fila se apila. */
export default function ListaEditable<T extends { key: string }>({
  titulo,
  filas,
  columnas,
  onChange,
  nueva,
  textoAnadir,
  disabled = false,
}: {
  titulo?: ReactNode
  filas: T[]
  columnas: ColumnaEditable<T>[]
  onChange: (filas: T[]) => void
  nueva: () => T
  textoAnadir: string
  disabled?: boolean
}) {
  const plantilla = `${columnas.map((c) => c.ancho).join(' ')} 34px`
  const cambiarFila = (i: number) => (c: Partial<T>) => onChange(filas.map((f, j) => (j === i ? { ...f, ...c } : f)))

  return (
    <div className="mt-2">
      {titulo && <div className="mb-2 text-[12px] font-semibold text-muted">{titulo}</div>}
      <div className="overflow-hidden rounded-xl border border-line">
        <div className="grid gap-2 border-b border-line bg-head px-3.5 pt-2.5 pb-[9px] text-[11px] font-[650] tracking-[.4px] text-muted uppercase max-sm:hidden" style={{ gridTemplateColumns: plantilla }}>
          {columnas.map((c) => (
            <span key={c.titulo}>{c.titulo}</span>
          ))}
          <span />
        </div>
        {filas.map((f, i) => (
          <div
            key={f.key}
            className="group/fila grid items-start gap-2 border-b border-line2 px-3 py-2.5 transition-colors hover:bg-head max-sm:!grid-cols-1 max-sm:gap-1.5"
            style={{ gridTemplateColumns: plantilla }}
          >
            {columnas.map((c) => (
              <div key={c.titulo} className="min-w-0">
                <span className="mb-0.5 hidden px-2 text-[10.5px] font-semibold text-muted uppercase max-sm:block">{c.titulo}</span>
                {c.render(f, cambiarFila(i), i)}
              </div>
            ))}
            {!disabled && (
              <IconButton
                label="Quitar fila"
                tone="danger"
                icon={<Trash2 />}
                onClick={() => onChange(filas.filter((_, j) => j !== i))}
                className="mt-0.5 opacity-0 group-focus-within/fila:opacity-100 group-hover/fila:opacity-100 max-[760px]:opacity-100 max-sm:justify-self-end"
              />
            )}
          </div>
        ))}
        {!disabled && (
          <button
            type="button"
            onClick={() => onChange([...filas, nueva()])}
            className="group/add flex w-full items-center gap-2 px-[18px] py-[11px] text-left text-[13.5px] text-label transition-colors hover:bg-hover-row hover:text-ink"
          >
            <Plus className="size-4 transition-transform duration-[180ms] group-hover/add:rotate-90" />
            {textoAnadir}
          </button>
        )}
      </div>
    </div>
  )
}
