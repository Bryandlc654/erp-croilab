import { useEffect, useMemo, useRef, useState, type Dispatch, type ReactNode, type SetStateAction } from 'react'
import { Plus, X } from 'lucide-react'
import Avatar from '../Avatar'
import AvatarStack, { type PersonaAvatar } from '../AvatarStack'
import PersonPicker from '../PersonPicker'
import SortableList, { RowGrip } from '../SortableList'
import { ProgressPill } from '../Progress'
import { useConfirm } from '../useConfirm'
import { ordenarChecklist, ordenTrasArrastre, progreso, RETRASO_REORDEN_MS, type IdChecklist, type ItemChecklist } from '../../lib/checklist'
import './rich.css'

type Persona = PersonaAvatar & { id: number }

export type ChecklistProps = {
  items: ItemChecklist[]
  /* Marca/desmarca (el padre actualiza `items`, mejor de forma optimista). */
  onToggle: (id: IdChecklist, hecho: boolean) => void
  onAdd?: (texto: string, asignados: number[]) => Promise<unknown> | void
  /* Borrar (se confirma con «¿Borrar elemento?» salvo `confirmDelete={false}`). */
  onDelete?: (id: IdChecklist) => Promise<unknown> | void
  /* Personas asignadas a un elemento (PersonPicker múltiple). */
  onAssign?: (id: IdChecklist, asignados: number[]) => void
  /* Nuevo orden de ids tras arrastrar (pendientes y hechos no se mezclan). */
  onReorder?: (ids: IdChecklist[]) => void
  people?: Persona[]
  /* Cabecera «Lista de control» con la píldora 3/5; null la quita. */
  title?: ReactNode
  readOnly?: boolean
  confirmDelete?: boolean
  addPlaceholder?: string
  className?: string
}

/* Lista de control de la tarea (.chk-* de task.php): al marcar, tachado que
   crece, destello verde y a los 280 ms baja con los hechos. */
export default function Checklist({
  items,
  onToggle,
  onAdd,
  onDelete,
  onAssign,
  onReorder,
  people = [],
  title = 'Lista de control',
  readOnly = false,
  confirmDelete = true,
  addPlaceholder = 'Añadir elemento…',
  className = '',
}: ChecklistProps) {
  const { confirm } = useConfirm()
  // Recién marcados: se colocan con su estado anterior mientras dura la animación.
  const [retenidos, setRetenidos] = useState<Map<IdChecklist, boolean>>(new Map())
  const [destellos, setDestellos] = useState<Set<IdChecklist>>(new Set())
  const [movidos, setMovidos] = useState<Set<IdChecklist>>(new Set())
  const timers = useRef(new Map<IdChecklist, ReturnType<typeof setTimeout>>())

  useEffect(() => {
    const t = timers.current
    return () => t.forEach(clearTimeout)
  }, [])

  const visibles = useMemo(() => ordenarChecklist(items, retenidos), [items, retenidos])
  const { hechos, total } = progreso(items)

  function alternar(it: ItemChecklist, hecho: boolean) {
    onToggle(it.id, hecho)
    setRetenidos((m) => new Map(m).set(it.id, m.get(it.id) ?? it.hecho))
    if (hecho) setDestellos((s) => new Set(s).add(it.id))
    clearTimeout(timers.current.get(it.id))
    timers.current.set(
      it.id,
      setTimeout(() => {
        setRetenidos((m) => {
          const n = new Map(m)
          n.delete(it.id)
          return n
        })
        setMovidos((s) => new Set(s).add(it.id))
      }, RETRASO_REORDEN_MS),
    )
  }

  async function borrar(id: IdChecklist) {
    if (!onDelete) return
    if (confirmDelete && !(await confirm({ title: '¿Borrar elemento?', danger: true, okLabel: 'Borrar' }))) return
    await onDelete(id)
  }

  const quitar = (set: Dispatch<SetStateAction<Set<IdChecklist>>>, id: IdChecklist) =>
    set((s) => {
      if (!s.has(id)) return s
      const n = new Set(s)
      n.delete(id)
      return n
    })

  const fila = (it: ItemChecklist, handle?: ReactNode) => {
    const asignados = people.filter((p) => it.asignados?.includes(p.id))
    return (
      <div
        className={`group/chk flex items-center gap-3 border-b border-line2 px-1 py-3 transition-[background-color] duration-[250ms] hover:bg-hover-row max-sm:gap-2.5 ${destellos.has(it.id) ? 'motion-safe:animate-[chkFlash_.6s_ease]' : ''} ${movidos.has(it.id) ? 'motion-safe:animate-erp-in' : ''}`}
        onAnimationEnd={(e) => {
          if (e.target !== e.currentTarget) return
          quitar(setDestellos, it.id)
          quitar(setMovidos, it.id)
        }}
      >
        {handle}
        <input
          type="checkbox"
          checked={it.hecho}
          disabled={readOnly}
          onChange={(e) => alternar(it, e.target.checked)}
          aria-label={it.hecho ? `Desmarcar «${it.texto}»` : `Marcar «${it.texto}» como hecho`}
          className="size-[17px] shrink-0 cursor-pointer disabled:cursor-default max-sm:size-5"
        />
        <span className={`min-w-0 flex-1 text-[13.5px] transition-colors duration-[250ms] ${it.hecho ? 'text-label' : 'text-ink'}`}>
          <span
            className={`relative inline after:pointer-events-none after:absolute after:inset-x-0 after:top-[54%] after:h-[1.5px] after:origin-left after:bg-[#a9aeb6] after:transition-transform after:duration-[280ms] after:ease-out dark:after:bg-line-strong ${
              it.hecho ? 'after:scale-x-100' : 'after:scale-x-0'
            }`}
          >
            {it.texto}
          </span>
        </span>
        {onAssign && !readOnly ? (
          <PersonPicker
            multiple
            people={people}
            value={it.asignados ?? []}
            onChange={(ids) => onAssign(it.id, ids)}
            label={`Responsables de «${it.texto}»`}
            className="!rounded-full !p-0.5"
            trigger={(elegidos) => (elegidos.length ? <AvatarStack people={elegidos} size={22} /> : <Fantasmas people={people} />)}
          />
        ) : asignados.length ? (
          <AvatarStack people={asignados} size={22} />
        ) : null}
        {onDelete && !readOnly && (
          <button
            type="button"
            onClick={() => void borrar(it.id)}
            aria-label={`Borrar «${it.texto}»`}
            title="Borrar"
            className="shrink-0 rounded-md p-1 text-[#c9ccd1] transition-colors hover:bg-[#fde8e8] hover:text-[#c0392b] dark:text-line-strong dark:hover:bg-danger-bg dark:hover:text-danger"
          >
            <X className="size-3.5" strokeWidth={2.4} />
          </button>
        )}
      </div>
    )
  }

  return (
    <div className={className}>
      {title !== null && (
        <div className="mt-[30px] mb-[13px] flex items-center gap-2.5 text-[11px] font-[650] tracking-[.6px] text-muted uppercase">
          {title}
          <span className="flex-1" />
          {total > 0 && <ProgressPill done={hechos} total={total} />}
        </div>
      )}
      {onReorder && !readOnly ? (
        <SortableList
          items={visibles}
          getId={(it) => it.id}
          onReorder={(ids) => onReorder(ordenTrasArrastre(items, ids))}
          as="div"
          renderItem={(it, { handleProps }) => fila(it, <RowGrip {...handleProps} className="-ml-1 -mr-1.5" />)}
          aria-label="Lista de control"
        />
      ) : (
        <div>{visibles.map((it) => <div key={it.id}>{fila(it)}</div>)}</div>
      )}
      {onAdd && !readOnly && <NuevoElemento onAdd={onAdd} people={people} placeholder={addPlaceholder} conAsignar={!!onAssign} />}
    </div>
  )
}

/* Dos avatares «fantasma» (.pile sin asignar): invitan a asignar sin gritar. */
function Fantasmas({ people }: { people: Persona[] }) {
  return (
    <span className="inline-flex items-center opacity-[.32] transition-opacity group-hover/chk:opacity-60">
      {people.slice(0, 2).map((p, i) => (
        <Avatar key={p.id} nombre={p.username} foto={p.foto} size={22} style={{ marginLeft: i ? -7 : 0, boxShadow: '0 0 0 2px var(--c-card)' }} />
      ))}
      <span className="-ml-[7px] flex size-[22px] items-center justify-center rounded-full border-[1.5px] border-dashed border-[#c4c8ce] bg-card text-[12px] text-label dark:border-line-strong">＋</span>
    </span>
  )
}

function NuevoElemento({ onAdd, people, placeholder, conAsignar }: { onAdd: NonNullable<ChecklistProps['onAdd']>; people: Persona[]; placeholder: string; conAsignar: boolean }) {
  const [texto, setTexto] = useState('')
  const [asignados, setAsignados] = useState<number[]>([])
  const [enviando, setEnviando] = useState(false)
  const eligiendo = useRef(false)
  const blur = useRef<ReturnType<typeof setTimeout> | null>(null)
  useEffect(() => () => clearTimeout(blur.current ?? undefined), [])

  async function crear() {
    const t = texto.trim()
    if (!t || enviando) return
    setEnviando(true)
    try {
      await onAdd(t, asignados)
      setTexto('')
      setAsignados([])
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div className="group/add flex items-center gap-[9px] px-1 py-2.5">
      <Plus className="size-[15px] shrink-0 text-label transition-transform duration-[180ms] group-focus-within/add:rotate-90" aria-hidden="true" />
      <input
        value={texto}
        disabled={enviando}
        onChange={(e) => setTexto(e.target.value)}
        onFocus={() => (eligiendo.current = false)}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            e.preventDefault()
            void crear()
          } else if (e.key === 'Escape') setTexto('')
        }}
        // Como el antiguo: al salir también se crea (salvo si se va a elegir responsable).
        onBlur={() => {
          blur.current = setTimeout(() => !eligiendo.current && void crear(), 120)
        }}
        placeholder={placeholder}
        aria-label={placeholder}
        className="min-w-0 flex-1 bg-transparent text-[13.5px] text-ink placeholder:text-label focus:outline-none max-sm:text-[16px]"
      />
      {conAsignar && (
        <span onMouseDown={() => (eligiendo.current = true)}>
          <PersonPicker
            multiple
            people={people}
            value={asignados}
            onChange={setAsignados}
            label="Responsables del nuevo elemento"
            className="!rounded-full !p-0.5"
            trigger={(elegidos) =>
              elegidos.length ? (
                <AvatarStack people={elegidos} size={22} />
              ) : (
                <span className="flex size-[22px] items-center justify-center rounded-full border-[1.5px] border-dashed border-[#c4c8ce] text-[12px] text-label dark:border-line-strong">＋</span>
              )
            }
          />
        </span>
      )}
    </div>
  )
}
