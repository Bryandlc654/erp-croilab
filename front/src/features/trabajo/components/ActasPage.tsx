import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { FileText, Flag, Plus, Search, Trash2 } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import EmptyState from '../../../shared/ui/EmptyState'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useAccionesActas, useActas } from '../api'
import { fechaExacta, haceActa } from '../logica'
import type { Acta } from '../schemas'

const normal = (s: string) =>
  s
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()

/* Actas internas del equipo (actas.php): buscador y chips de autor (en el
   navegador, como el antiguo), fijadas primero, acciones al pasar el ratón. */
export default function ActasPage() {
  const consulta = useActas('', 0)
  const acc = useAccionesActas()
  const { confirm } = useConfirm()
  const [q, setQ] = useState('')
  const [autor, setAutor] = useState<number | null>(null)
  const datos = consulta.data
  const puede = datos?.puede_editar ?? false
  const autores = (datos?.autores ?? []).flatMap((a) => (a.persona ? [a.persona] : []))

  const actas = useMemo(() => {
    const n = normal(q.trim())
    return (datos?.items ?? []).filter((a) => (autor === null || a.autor?.id === autor) && (!n || normal(`${a.titulo} ${a.extracto}`).includes(n)))
  }, [datos, q, autor])

  async function borrar(a: Acta) {
    if (await confirm({ title: '¿Borrar esta acta?', message: 'Se puede recuperar desde la papelera.', danger: true, okLabel: 'Borrar' })) acc.borrar.mutate(a.id)
  }

  const vacia = datos && datos.items.length === 0

  return (
    <div className="mx-auto max-w-[812px]">
      <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-[26px] font-semibold tracking-[-.5px] text-ink-strong">Actas</h1>
          <p className="mt-1.5 text-[14px] text-muted">Notas y actas internas del equipo. No se ven en el portal del cliente.</p>
        </div>
        {puede && (
          <Button to="/actas/nueva" icon={<Plus />}>
            Nueva acta
          </Button>
        )}
      </div>

      {consulta.error && <p className="mb-4 text-[13px] text-[#b91c1c]">{consulta.error.message}</p>}
      {consulta.isPending && <div className="h-[160px] animate-pulse rounded-2xl border border-line bg-head" aria-hidden="true" />}

      {vacia && (
        <EmptyState
          variant="dashed"
          icon={<FileText />}
          title="Aún no hay actas"
          text="Crea la primera y escríbela con el mismo editor que las tareas: títulos, listas, tablas y pegar de la web conservando el formato."
          actions={
            puede ? (
              <Button to="/actas/nueva" icon={<Plus />}>
                Nueva acta
              </Button>
            ) : undefined
          }
        />
      )}

      {datos && !vacia && (
        <>
          <div className="mb-[14px] flex flex-wrap items-center gap-2.5">
            <div className="relative min-w-[220px] flex-1">
              <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-label" aria-hidden="true" />
              <input
                type="search"
                value={q}
                onChange={(e) => setQ(e.target.value)}
                placeholder="Buscar en las actas…"
                aria-label="Buscar en las actas"
                className="h-10 w-full rounded-[10px] border border-line bg-field pr-3 pl-9 text-[13.5px] text-ink placeholder:text-label focus:border-accent focus:outline-none max-sm:text-[16px]"
              />
            </div>
            {autores.length > 1 && (
              <div className="flex flex-wrap gap-1.5" role="group" aria-label="Autores">
                <button type="button" onClick={() => setAutor(null)} className={`rounded-full border px-3 py-1.5 text-[12.5px] font-semibold ${autor === null ? 'border-tab-on bg-tab-on text-white dark:text-zinc-900' : 'border-line text-muted hover:bg-soft'}`}>
                  Todos
                </button>
                {autores.map((a) => (
                  <button
                    key={a.id}
                    type="button"
                    onClick={() => setAutor(a.id)}
                    className={`inline-flex items-center gap-1.5 rounded-full border py-1 pr-3 pl-1.5 text-[12.5px] font-semibold ${autor === a.id ? 'border-tab-on bg-tab-on text-white dark:text-zinc-900' : 'border-line text-muted hover:bg-soft'}`}
                  >
                    <Avatar nombre={a.username} foto={a.foto} size={16} /> {a.username}
                  </button>
                ))}
              </div>
            )}
          </div>

          {actas.length === 0 && <EmptyState variant="inline" icon={<Search />} title="No hay actas que coincidan con la búsqueda." />}

          <ul className="flex flex-col gap-[14px]">
            {actas.map((a) => (
              <li key={a.id} className="group relative">
                <Link
                  to={`/actas/${a.id}`}
                  className={`block overflow-hidden rounded-2xl border border-line bg-card px-[22px] py-5 transition-[border-color,box-shadow] hover:border-[#dcdde1] hover:shadow-[0_8px_26px_rgba(16,19,24,.07)] dark:hover:border-line-strong ${
                    a.fijada ? 'shadow-[inset_3px_0_0_rgba(17,19,24,.55)] dark:shadow-[inset_3px_0_0_rgba(229,229,229,.55)]' : ''
                  }`}
                >
                  {a.fijada && (
                    <span className="mb-1.5 inline-flex items-center gap-1 rounded-full bg-soft px-2 py-0.5 text-[11px] font-bold text-ink">
                      <Flag className="size-3" /> Fijada
                    </span>
                  )}
                  <b className={`block pr-16 text-[17px] font-[650] tracking-[-.2px] ${a.titulo ? 'text-ink-strong' : 'text-muted'}`}>{a.titulo || '(Sin título)'}</b>
                  {a.extracto && <p className="mt-1 line-clamp-2 text-[13.5px] leading-[1.55] text-muted">{a.extracto}</p>}
                  <span className="mt-3 flex items-center gap-2 text-[12.5px] text-muted">
                    <Avatar nombre={a.autor?.username ?? 'Equipo'} foto={a.autor?.foto} size={20} />
                    <b className="font-semibold text-ink">{a.autor?.username ?? 'Equipo'}</b> · <span title={fechaExacta(a.updated_at)}>{haceActa(a.updated_at)}</span>
                  </span>
                </Link>
                {puede && (
                  <span className="absolute top-3 right-3 flex gap-0.5 rounded-[9px] border border-line bg-pop p-0.5 opacity-0 shadow-[0_3px_10px_rgba(0,0,0,.08)] transition-opacity group-focus-within:opacity-100 group-hover:opacity-100 max-sm:opacity-100">
                    <button
                      type="button"
                      onClick={() => acc.fijar.mutate({ id: a.id, fijada: !a.fijada })}
                      className={`flex size-7 items-center justify-center rounded-[7px] hover:bg-soft ${a.fijada ? 'text-ink' : 'text-label hover:text-ink'}`}
                      title={a.fijada ? 'Desfijar' : 'Fijar'}
                      aria-label={a.fijada ? 'Desfijar' : 'Fijar'}
                    >
                      <Flag className="size-4" />
                    </button>
                    <button type="button" onClick={() => void borrar(a)} className="flex size-7 items-center justify-center rounded-[7px] text-label hover:bg-[#fdecec] hover:text-[#e5484d]" title="Borrar" aria-label="Borrar">
                      <Trash2 className="size-4" />
                    </button>
                  </span>
                )}
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  )
}
