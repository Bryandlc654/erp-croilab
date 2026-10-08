import { useContext, useId, useMemo, type ReactNode } from 'react'
import { CampoContext, RejillaContext } from './campoContexto'

export type Span = 2 | 3 | 4 | 5 | 6 | 8 | 12

// Clases completas: Tailwind no ve las que se montan con plantillas.
const SPAN: Record<Span, string> = {
  2: 'col-span-2',
  3: 'col-span-3',
  4: 'col-span-4',
  5: 'col-span-5',
  6: 'col-span-6',
  8: 'col-span-8',
  12: 'col-span-12',
}

/* Etiqueta + control + ayuda (.set-f). Dentro de un FormGrid ocupa `span`
   columnas de 12 (6 por defecto) y a una sola columna por debajo de 700px. */
export default function Field({
  label,
  icon,
  hint,
  error,
  required = false,
  span,
  htmlFor,
  className = '',
  children,
}: {
  label: ReactNode
  icon?: ReactNode
  hint?: ReactNode
  /* Mensaje de error: marca el control como inválido y sustituye a la ayuda. */
  error?: ReactNode
  required?: boolean
  span?: Span
  htmlFor?: string
  className?: string
  children: ReactNode
}) {
  const auto = useId()
  const id = htmlFor ?? `campo${auto}`
  const ayudaId = hint || error ? `${id}-ayuda` : undefined
  const rejilla = useContext(RejillaContext)
  const ctx = useMemo(() => ({ id, ayudaId, invalid: !!error, required }), [id, ayudaId, error, required])

  const columnas = rejilla ? `${rejilla.una ? 'col-span-12' : SPAN[span ?? 6]} max-[700px]:col-span-full` : ''

  return (
    <div className={`min-w-0 ${columnas} ${className}`}>
      <label htmlFor={id} className="mb-[7px] flex items-center gap-1.5 text-[12px] font-semibold text-muted [&>svg]:size-3.5 [&>svg]:text-label">
        {icon}
        <span>
          {label}
          {required && (
            <span className="ml-0.5 text-[#ef4444]" aria-hidden="true">
              *
            </span>
          )}
        </span>
      </label>
      <CampoContext.Provider value={ctx}>{children}</CampoContext.Provider>
      {(error || hint) && (
        <p id={ayudaId} className={`mt-[7px] text-[11.5px] leading-[1.5] ${error ? 'font-medium text-[#ef4444] dark:text-danger' : 'text-label'}`}>
          {error || hint}
        </p>
      )}
    </div>
  )
}
