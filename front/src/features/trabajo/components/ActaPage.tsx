import { useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Check, Flag, Pencil, SearchX, Trash2 } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import Cargando from '../../../shared/ui/Cargando'
import EmptyState from '../../../shared/ui/EmptyState'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { RichTextEditor, RichTextView, type RichTextEditorHandle } from '../../../shared/ui/rich'
import { useUnsavedGuard } from '../../../shared/lib/useUnsavedGuard'
import { useEquipo } from '../../nav/api'
import { useAccionesActas, useActa } from '../api'
import { fechaExacta, haceActa } from '../logica'
import type { ActaCompleta } from '../schemas'

const META = 'grid grid-cols-[132px_1fr] items-center gap-3 py-1.5 text-[13.5px] max-sm:grid-cols-[100px_1fr]'

/* Un acta: lectura (/actas/:id), edición (/actas/:id/editar) y nueva (/actas/nueva). */
export default function ActaPage({ modo }: { modo: 'ver' | 'editar' | 'nueva' }) {
  const { id: idTexto } = useParams()
  const id = Number(idTexto) || 0
  const consulta = useActa(modo === 'nueva' ? 0 : id)

  if (modo === 'nueva') return <Editor acta={null} />
  if (consulta.isPending) return <Cargando />
  if (consulta.error || !consulta.data) {
    return (
      <div className="mx-auto max-w-[728px] pt-10">
        <EmptyState
          icon={<SearchX />}
          title="Esta acta no existe"
          text="Puede que la hayan borrado (mira en la papelera)."
          actions={
            <Link to="/actas" className="text-[13px] font-semibold text-ink hover:underline">
              Todas las actas
            </Link>
          }
        />
      </div>
    )
  }
  const acta = consulta.data.acta
  if (modo === 'editar' && acta.puede_editar) return <Editor key={acta.id} acta={acta} />
  return <Lectura acta={acta} />
}

function Lectura({ acta }: { acta: ActaCompleta }) {
  const acc = useAccionesActas()
  const { confirm } = useConfirm()
  const navigate = useNavigate()
  const { data: equipo } = useEquipo()

  async function borrar() {
    if (!(await confirm({ title: '¿Borrar esta acta?', message: 'Se puede recuperar desde la papelera.', danger: true, okLabel: 'Borrar' }))) return
    acc.borrar.mutate(acta.id, { onSuccess: () => navigate('/actas') })
  }

  return (
    <article className="mx-auto max-w-[728px]">
      <Link to="/actas" className="mb-4 inline-flex items-center gap-1.5 text-[12.5px] text-muted hover:text-ink">
        <ArrowLeft className="size-3.5" /> Todas las actas
      </Link>
      <h1 className={`mb-4 text-[29px] leading-tight font-semibold tracking-[-.6px] ${acta.titulo ? 'text-ink-strong' : 'text-muted'} max-sm:text-[24px]`}>{acta.titulo || '(Sin título)'}</h1>
      <div className="mb-5 border-y border-line py-3">
        <div className={META}>
          <span className="text-muted">Autor</span>
          <span className="flex items-center gap-2 font-medium text-ink">
            <Avatar nombre={acta.autor?.username ?? 'Equipo'} foto={acta.autor?.foto} size={22} /> {acta.autor?.username ?? 'Equipo'}
          </span>
        </div>
        <div className={META}>
          <span className="text-muted">Última edición</span>
          <span className="text-ink">
            {haceActa(acta.updated_at)} · <span className="text-muted">{fechaExacta(acta.updated_at)}</span>
          </span>
        </div>
        <div className={META}>
          <span className="text-muted">Creada</span>
          <span className="text-ink">{fechaExacta(acta.created_at)}</span>
        </div>
      </div>
      {acta.puede_editar && (
        <div className="mb-6 flex flex-wrap gap-2">
          <Button to={`/actas/${acta.id}/editar`} icon={<Pencil />}>
            Editar
          </Button>
          <Button variant="ghost" icon={<Flag />} onClick={() => acc.fijar.mutate({ id: acta.id, fijada: !acta.fijada })}>
            {acta.fijada ? 'Fijada' : 'Fijar'}
          </Button>
          <Button variant="ghost" icon={<Trash2 />} onClick={() => void borrar()}>
            Borrar
          </Button>
        </div>
      )}
      {acta.contenido.trim() ? (
        <RichTextView value={acta.contenido} people={equipo ?? []} className="text-[15px] leading-[1.75]" />
      ) : (
        <p className="text-[14px] text-muted">Esta acta está vacía.</p>
      )}
    </article>
  )
}

function Editor({ acta }: { acta: ActaCompleta | null }) {
  const acc = useAccionesActas()
  const { aviso } = useToast()
  const navigate = useNavigate()
  const { data: equipo } = useEquipo()
  const editor = useRef<RichTextEditorHandle>(null)
  const [titulo, setTitulo] = useState(acta?.titulo ?? '')
  const [contenido, setContenido] = useState(acta?.contenido ?? '')
  const sucio = titulo !== (acta?.titulo ?? '') || contenido !== (acta?.contenido ?? '')
  useUnsavedGuard(sucio)
  const guardando = acc.crear.isPending || acc.guardar.isPending
  const volver = acta ? `/actas/${acta.id}` : '/actas'

  function guardar() {
    const c = editor.current?.getValue() ?? contenido
    if (!titulo.trim() && !c.replace(/\[\[chk:[01]\]\]/g, '').trim()) {
      aviso('Escribe un título o algo de contenido', { tipo: 'error' })
      return
    }
    const fin = (a: ActaCompleta) => {
      aviso('Acta guardada')
      navigate(`/actas/${a.id}`)
    }
    if (acta) acc.guardar.mutate({ id: acta.id, titulo: titulo.trim(), contenido: c, version: acta.updated_at }, { onSuccess: (r) => fin(r.acta) })
    else acc.crear.mutate({ titulo: titulo.trim(), contenido: c }, { onSuccess: (r) => fin(r.acta) })
  }

  return (
    <div className="mx-auto max-w-[728px]">
      <nav aria-label="Migas" className="mb-4 flex items-center gap-2 text-[12.5px] text-muted">
        <Link to="/actas" className="inline-flex items-center gap-1.5 hover:text-ink">
          <ArrowLeft className="size-3.5" /> Todas las actas
        </Link>
        {acta && (
          <>
            <span className="text-[#d4d7dd]">/</span>
            <Link to={volver} className="font-semibold text-ink">
              Volver al acta
            </Link>
          </>
        )}
      </nav>
      <input
        autoFocus={!acta}
        value={titulo}
        maxLength={220}
        onChange={(e) => setTitulo(e.target.value)}
        placeholder="Título del acta…"
        aria-label="Título del acta"
        className="mb-3 w-full bg-transparent pb-2 text-[29px] leading-tight font-semibold tracking-[-.6px] text-ink-strong placeholder:text-label focus:outline-none max-sm:text-[24px]"
      />
      <RichTextEditor
        ref={editor}
        value={contenido}
        onChange={setContenido}
        debounceMs={300}
        placeholder="Escribe aquí… títulos, listas, lista de control, cita, código, tablas y pega de la web conservando el formato."
        mentions={equipo ?? []}
        minHeight={320}
        ariaLabel="Contenido del acta"
      />
      <div className="sticky bottom-0 z-20 -mx-4 mt-6 flex flex-wrap items-center gap-2.5 border-t border-line bg-page/90 px-4 py-3.5 backdrop-blur-[6px] md:-mx-6 md:px-6">
        <Button icon={<Check />} onClick={guardar} loading={guardando} loadingText="Guardando…">
          Guardar acta
        </Button>
        <Button variant="ghost" to={volver}>
          {acta ? 'Cancelar' : 'Volver'}
        </Button>
        {sucio && (
          <span className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-[#b7791f] dark:text-warn">
            <span className="size-[7px] rounded-full bg-[#e8a33d]" aria-hidden="true" /> Sin guardar
          </span>
        )}
        {acta && <span className="ml-auto text-[12.5px] text-muted">Última edición {haceActa(acta.updated_at)}</span>}
      </div>
    </div>
  )
}
