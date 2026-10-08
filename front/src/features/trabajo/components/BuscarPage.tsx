import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Search, SearchX } from 'lucide-react'
import EmptyState from '../../../shared/ui/EmptyState'
import { rutaDesdeLegado } from '../../../shared/lib/rutas'
import { useBusqueda } from '../api'
import { Icono } from '../buscador'

/* Página completa de búsqueda (buscar.php?q=): caja, total y grupos con hasta
   40 resultados. Solo lo que cada uno puede ver (la API filtra). */
export default function BuscarPage() {
  const [params, setParams] = useSearchParams()
  const q = params.get('q') ?? ''
  // Lo escrito y aún no buscado; si no, lo de la URL (que puede cambiar desde la paleta).
  const [editado, setEditado] = useState<string | null>(null)
  const texto = editado ?? q
  const consulta = useBusqueda(q)
  const r = consulta.data
  const corto = q.trim().length < 2

  return (
    <div className="mx-auto max-w-[900px]">
      <h1 className="mb-5 text-[26px] font-semibold tracking-[-.5px] text-ink-strong">Buscar</h1>
      <form
        role="search"
        onSubmit={(e) => {
          e.preventDefault()
          setParams(texto.trim() ? { q: texto.trim() } : {})
          setEditado(null)
        }}
        className="mb-5 flex items-center gap-3 rounded-[14px] border border-line bg-card px-[18px] py-3.5 focus-within:border-line-strong"
      >
        <Search className="size-[18px] shrink-0 text-muted" aria-hidden="true" />
        <input
          type="search"
          autoFocus
          value={texto}
          onChange={(e) => setEditado(e.target.value)}
          placeholder="Buscar clientes, tareas, contactos, negocios, facturas…"
          aria-label="Buscar"
          className="min-w-0 flex-1 bg-transparent text-[16px] text-ink-strong placeholder:text-label focus:outline-none"
        />
      </form>

      {corto ? (
        <EmptyState
          variant="inline"
          icon={<Search />}
          title="Escribe al menos dos letras"
          text={
            <>
              También puedes abrir esta búsqueda desde cualquier página con <b className="font-semibold text-ink">Ctrl + K</b>.
            </>
          }
        />
      ) : consulta.isPending ? (
        <p className="text-[13px] text-muted">Buscando…</p>
      ) : consulta.error ? (
        <p className="text-[13px] text-[#b91c1c]">No se ha podido buscar ahora mismo.</p>
      ) : r && r.n === 0 ? (
        <EmptyState variant="inline" icon={<SearchX />} title={`Nada coincide con «${q}».`} />
      ) : (
        r && (
          <>
            <p className="mb-4 text-[13px] text-muted">
              {r.n} resultado{r.n === 1 ? '' : 's'} para «{r.q}»
            </p>
            {r.grupos.map((g) => (
              <section key={g.g} className="mb-6" aria-label={g.g}>
                <h2 className="mb-2.5 text-[12px] font-semibold tracking-[.5px] text-muted uppercase">{g.g}</h2>
                <ul className="flex flex-col gap-2">
                  {g.r.map((x, i) => (
                    <li key={i}>
                      <Link to={rutaDesdeLegado(x.u)} className="flex items-start gap-3 rounded-xl border border-line bg-card px-[15px] py-[13px] transition-colors hover:border-[#dcdcde] hover:bg-soft dark:hover:border-line-strong">
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-[9px] bg-soft text-muted [&>svg]:size-4">
                          <Icono clave={x.i} />
                        </span>
                        <span className="min-w-0 flex-1">
                          <b className="block truncate text-[13.5px] font-semibold text-ink-strong">{x.t}</b>
                          {x.s && <span className="mt-0.5 block text-[12px] leading-[1.45] text-muted">{x.s}</span>}
                        </span>
                      </Link>
                    </li>
                  ))}
                </ul>
              </section>
            ))}
          </>
        )
      )}
    </div>
  )
}
