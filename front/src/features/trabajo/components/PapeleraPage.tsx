import { useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { CheckSquare, FileText, List, Lock, PenLine, Ticket, Trash2, TrendingUp, Undo2, Users, BriefcaseBusiness, type LucideIcon } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import EmptyState from '../../../shared/ui/EmptyState'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { aFecha } from '../../../shared/lib/formato'
import { useAccionesPapelera, usePapelera } from '../api'
import type { ElementoPapelera } from '../schemas'

const ICONO: Record<string, LucideIcon> = {
  tarea: CheckSquare,
  cliente: Users,
  contacto: BriefcaseBusiness,
  negocio: TrendingUp,
  factura: FileText,
  ticket: Ticket,
  lista: List,
  acta: PenLine,
  credencial: Lock,
}

const dos = (n: number) => String(n).padStart(2, '0')
function fechaHora(v: string) {
  const d = aFecha(v)
  return d ? `${dos(d.getDate())}/${dos(d.getMonth() + 1)}/${d.getFullYear()} ${dos(d.getHours())}:${dos(d.getMinutes())}` : ''
}

/* Papelera global (papelera.php): lo borrado en cualquier módulo, 30 días. */
export default function PapeleraPage() {
  const consulta = usePapelera()
  const acc = useAccionesPapelera()
  const { confirm } = useConfirm()
  const navigate = useNavigate()
  const { aviso } = useToast()
  const items = useMemo(() => consulta.data?.pages.flatMap((p) => p.items) ?? [], [consulta.data])
  const primera = consulta.data?.pages[0]
  const dias = primera?.dias ?? 30

  async function vaciar() {
    const n = primera?.total ?? items.length
    if (await confirm({ title: '¿Vaciar la papelera?', message: `Se eliminarán definitivamente los ${n} elementos de la papelera. Esto ya no tiene vuelta atrás.`, danger: true, okLabel: 'Vaciar' })) acc.vaciar.mutate()
  }

  async function purgar(e: ElementoPapelera) {
    if (await confirm({ title: '¿Eliminar del todo?', message: 'Se elimina definitivamente. Ya no se podrá recuperar.', danger: true, okLabel: 'Eliminar' })) acc.purgar.mutate(e.id)
  }

  function restaurar(e: ElementoPapelera) {
    acc.restaurar.mutate(e.id, {
      onSuccess: (r) => aviso(r.msg, r.url ? { accion: { label: 'Ver', fn: () => navigate(r.url) } } : undefined),
    })
  }

  return (
    <div className="mx-auto max-w-[1180px]">
      <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-[26px] font-semibold tracking-[-.5px] text-ink-strong">Papelera</h1>
          <p className="mt-1.5 text-[14px] text-muted">Lo que borras en el ERP pasa por aquí y se puede devolver a su sitio. Pasados {dias} días se elimina solo.</p>
        </div>
        {primera?.permisos.purgar && items.length > 0 && (
          <Button variant="danger" icon={<Trash2 />} onClick={() => void vaciar()} loading={acc.vaciar.isPending}>
            Vaciar papelera
          </Button>
        )}
      </div>

      {consulta.error && <p className="mb-4 text-[13px] text-[#b91c1c]">{consulta.error.message}</p>}
      {consulta.isPending && <div className="h-[180px] animate-pulse rounded-2xl border border-line bg-head" aria-hidden="true" />}

      {!consulta.isPending && !consulta.error && items.length === 0 && <EmptyState icon={<Trash2 />} title="La papelera está vacía." text="Nada que recuperar." />}

      {items.length > 0 && (
        <ul className="overflow-hidden rounded-2xl border border-line bg-card">
          {items.map((e) => {
            const I = ICONO[e.tipo] ?? Trash2
            return (
              <li key={e.id} className="flex items-center gap-3.5 border-b border-line px-5 py-[15px] last:border-b-0 max-sm:flex-wrap max-sm:px-4">
                <span className="flex size-[34px] shrink-0 items-center justify-center rounded-[10px] bg-soft text-muted">
                  <I className="size-[17px]" strokeWidth={1.8} />
                </span>
                <span className="min-w-0 flex-1">
                  <b className="block truncate text-[14px] font-semibold text-ink-strong">{e.titulo}</b>
                  <span className="block truncate text-[12.5px] text-muted">
                    Borrado por {e.autor || 'alguien'} · {fechaHora(e.created_at)}
                  </span>
                </span>
                <span className="shrink-0 rounded-full bg-accent-soft px-2.5 py-0.5 text-[11px] font-bold text-ink">{e.tipo_label}</span>
                {primera?.permisos.restaurar && (
                  <button
                    type="button"
                    onClick={() => restaurar(e)}
                    disabled={acc.restaurar.isPending}
                    className="inline-flex shrink-0 items-center gap-1.5 rounded-[9px] border border-line px-[13px] py-[7px] text-[12.5px] font-semibold text-ink transition-colors hover:border-accent hover:bg-accent hover:text-white disabled:opacity-60 dark:hover:text-accent-fg"
                  >
                    <Undo2 className="size-3.5" /> Restaurar
                  </button>
                )}
                {primera?.permisos.purgar && (
                  <button
                    type="button"
                    onClick={() => void purgar(e)}
                    className="flex size-8 shrink-0 items-center justify-center rounded-lg text-label transition-colors hover:bg-[#fdecec] hover:text-[#e5484d]"
                    aria-label={`Eliminar del todo ${e.titulo}`}
                    title="Eliminar del todo"
                  >
                    <Trash2 className="size-4" />
                  </button>
                )}
              </li>
            )
          })}
        </ul>
      )}

      {consulta.hasNextPage && (
        <div className="mt-4 flex justify-center">
          <Button variant="ghost" size="sm" onClick={() => void consulta.fetchNextPage()} loading={consulta.isFetchingNextPage}>
            Cargar más
          </Button>
        </div>
      )}
    </div>
  )
}
