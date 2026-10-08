import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Check, Eye, Inbox, Plus, Search, X } from 'lucide-react'
import AvatarStack from '../../../shared/ui/AvatarStack'
import EmptyState from '../../../shared/ui/EmptyState'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { fechaCorta } from '../../../shared/lib/formato'
import { mesActual } from '../informe'
import { useAccionesListas, useBorrarTarea, useInforme, useMeses, useTareas } from '../api'
import type { ListaTareas } from '../schemas'

const COLS = 'md:grid md:grid-cols-[minmax(0,2.4fr)_1fr_1fr_70px] md:gap-2.5'

/* Vista de una lista de informes (workspace.php): chips de meses, el
   «Informe de {mes}» que el cliente lee en su Portal › Informes y las tareas
   del mes que también ve. */
export default function InformeLista({ cli, lista, mes, onMes, onNueva, permisos }: { cli: number; lista: ListaTareas; mes: string; onMes: (m: string) => void; onNueva: (mes: string) => void; permisos: { editar: boolean; crear: boolean; borrar: boolean } }) {
  const meses = useMeses(cli, lista.id, true)
  const lista_meses = meses.data?.items ?? []
  const actual = mes || lista_meses[0]?.mes || mesActual()
  const informe = useInforme(lista.id, actual, true)
  const tareas = useTareas({ view: 'cliente', emp: 0, cli, list: lista.id, fe: '', fr: '', mes: actual })
  const entradas = (tareas.data?.pages.flatMap((p) => p.items) ?? []).filter((t) => t.titulo !== 'Informe del mes')
  const acciones = useAccionesListas()
  const borrar = useBorrarTarea()
  const { confirm } = useConfirm()
  const [texto, setTexto] = useState<string | null>(null)
  const guardado = informe.data?.informe.texto ?? ''
  const valor = texto ?? guardado
  const publicado = informe.data?.informe.publicado ?? false

  function guardar() {
    acciones.guardarInforme.mutate({ list: lista.id, mes: actual, texto: valor }, { onSuccess: () => setTexto(null) })
  }

  return (
    <div>
      <div className="mb-3.5 flex flex-wrap items-center gap-2">
        {lista_meses.map((m) => (
          <button
            key={m.mes}
            type="button"
            onClick={() => {
              setTexto(null)
              onMes(m.mes)
            }}
            className={`rounded-full border px-[15px] py-[7px] text-[13px] font-semibold transition-colors ${
              m.mes === actual ? 'border-tab-on bg-tab-on text-white dark:text-zinc-900' : 'border-line bg-page text-muted hover:bg-soft hover:text-ink'
            }`}
          >
            {m.mes}
          </button>
        ))}
        {!lista_meses.some((m) => m.mes === actual) && <span className="rounded-full border border-tab-on bg-tab-on px-[15px] py-[7px] text-[13px] font-semibold text-white dark:text-zinc-900">{actual}</span>}
      </div>

      {permisos.editar && (
        <div className="mb-[22px] rounded-[18px] border border-line bg-gradient-to-b from-white to-[#fbfbfd] px-[22px] py-5 shadow-[0_1px_2px_rgba(16,19,24,.04),0_16px_34px_-22px_rgba(16,19,24,.22)] dark:bg-card dark:from-card dark:to-card max-sm:px-4">
          <div className="mb-[15px] flex items-center justify-between gap-3">
            <div className="flex min-w-0 items-center gap-3">
              <span className="flex size-[42px] shrink-0 items-center justify-center rounded-xl bg-soft text-[20px]" aria-hidden="true">
                ✍️
              </span>
              <div className="min-w-0">
                <b className="block text-[17px] font-semibold tracking-[-.2px] text-ink-strong">Informe de {actual}</b>
                <span className="text-[12.5px] text-muted">Lo que el cliente lee en su Portal › Informes</span>
              </div>
            </div>
            <span className={`shrink-0 rounded-full px-3 py-[5px] text-[10.5px] font-bold tracking-[.4px] uppercase ${publicado ? 'bg-[#e4f6ec] text-[#12854a] dark:bg-ok-bg dark:text-ok' : 'bg-soft text-muted'}`}>
              {publicado ? 'Publicado' : 'Sin publicar'}
            </span>
          </div>
          <textarea
            value={valor}
            onChange={(e) => setTexto(e.target.value)}
            maxLength={60000}
            placeholder="Cuéntale al cliente cómo ha ido el mes: qué hemos hecho, los resultados que ha traído y qué viene el mes que viene…"
            aria-label={`Informe de ${actual}`}
            className="min-h-[250px] w-full resize-y rounded-[14px] border border-line bg-field px-[18px] py-4 text-[15px] leading-[1.75] text-ink-strong placeholder:text-label focus:border-ink-strong focus:shadow-[0_0_0_4px_var(--c-accent-soft)] focus:outline-none max-sm:text-[16px]"
          />
          <div className="mt-3.5 flex flex-wrap items-center justify-between gap-3">
            <span className="inline-flex items-center gap-1.5 text-[12.5px] text-muted">
              <Eye className="size-3.5" /> Se publica al guardar · el cliente lo ve al instante
            </span>
            <button
              type="button"
              onClick={guardar}
              disabled={acciones.guardarInforme.isPending || texto === null}
              className="inline-flex items-center gap-1.5 rounded-[10px] bg-accent px-4 py-2 text-[13px] font-semibold text-white transition-[transform,opacity] hover:-translate-y-px disabled:opacity-50 dark:text-accent-fg"
            >
              <Check className="size-[15px]" /> {acciones.guardarInforme.isPending ? 'Publicando…' : 'Guardar y publicar'}
            </button>
          </div>
        </div>
      )}
      {!permisos.editar && guardado && <p className="mb-6 rounded-2xl border border-line bg-card px-5 py-4 text-[14px] leading-[1.7] whitespace-pre-wrap text-ink">{guardado}</p>}

      <div className="mx-0.5 mb-3 flex items-center gap-2 text-[12px] font-[650] tracking-[.5px] text-muted uppercase">
        <Check className="size-3.5" /> Tareas de {actual} que el cliente también ve <span className="rounded-full bg-soft px-[9px] py-px text-[11.5px] text-ink normal-case">{entradas.length}</span>
      </div>
      <div className="rounded-xl border border-line bg-page" role="table">
        <div role="row" className={`${COLS} border-b border-line bg-head px-4 py-3 text-[10.5px] font-[650] tracking-[.5px] text-muted uppercase max-md:hidden`}>
          <span role="columnheader">Tarea</span>
          <span role="columnheader">Responsable</span>
          <span role="columnheader">Fecha</span>
          <span role="columnheader" aria-label="Acciones" />
        </div>
        {entradas.map((t) => (
          <div key={t.id} role="row" className={`${COLS} flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-line px-4 py-3 last:border-b-0 hover:bg-hover-row`}>
            <Link to={`/tareas/${t.id}`} className="flex min-w-0 basis-full items-center gap-2.5 md:basis-auto">
              <Inbox className="size-[15px] shrink-0 text-label" />
              <b className="truncate text-[13.5px] font-semibold text-ink-strong">{t.titulo}</b>
            </Link>
            <span>{t.asignados.length ? <AvatarStack people={t.asignados} size={24} /> : <span className="text-[12.5px] text-label">—</span>}</span>
            <span className={`text-[12.5px] ${t.due_date ? 'text-ink' : 'text-label'}`}>{fechaCorta(t.due_date, '—')}</span>
            <span className="ml-auto flex justify-end gap-1">
              <Link to={`/tareas/${t.id}`} className="rounded-md p-1.5 text-label hover:bg-soft hover:text-ink" aria-label={`Abrir ${t.titulo}`} title="Abrir">
                <Search className="size-[14px]" />
              </Link>
              {permisos.borrar && (
                <button
                  type="button"
                  onClick={async () => {
                    if (await confirm({ title: '¿Borrar la entrada?', message: 'La entrada va a la papelera; podrás deshacerlo.', danger: true, okLabel: 'Borrar' })) borrar.mutate(t)
                  }}
                  className="rounded-md p-1.5 text-label hover:bg-[#fde8e8] hover:text-[#c0392b]"
                  aria-label={`Borrar ${t.titulo}`}
                >
                  <X className="size-[14px]" />
                </button>
              )}
            </span>
          </div>
        ))}
        {!tareas.isPending && entradas.length === 0 && (
          <EmptyState variant="inline" title="Sin tareas en este mes" text="El informe de arriba es lo principal; añade tareas solo si quieres que el cliente vea acciones concretas." />
        )}
        {permisos.crear && (
          <button type="button" onClick={() => onNueva(actual)} className="flex w-full items-center gap-2.5 border-t border-line px-[18px] py-[11px] text-left text-[13.5px] text-label transition-colors hover:bg-hover-row hover:text-ink">
            <Plus className="size-4" /> Añadir tarea del mes
          </button>
        )}
      </div>
    </div>
  )
}
