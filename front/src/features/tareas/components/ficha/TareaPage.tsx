import { useEffect, useMemo, useRef, useState, type TouchEvent } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Check, MoreHorizontal, SearchX, Trash2, LayoutList } from 'lucide-react'
import { useAuth } from '../../../auth/useAuth'
import { useEquipo } from '../../../nav/api'
import Menu, { MenuItem, MenuSeparator } from '../../../../shared/ui/Menu'
import EmptyState from '../../../../shared/ui/EmptyState'
import Cargando from '../../../../shared/ui/Cargando'
import { useConfirm } from '../../../../shared/ui/useConfirm'
import { useToast } from '../../../../shared/ui/useToast'
import { Checklist, FileDropzone, AttachmentList, RichTextEditor, RichTextView, WindowDropOverlay, BARRA_DOC } from '../../../../shared/ui/rich'
import { ApiError } from '../../../../shared/api/client'
import { useRecienGuardado } from '../../../../shared/lib/useUnsavedGuard'
import { useAdjuntos, useBorrarTarea, useChecklist, useGuardarFicha, subirParaDescripcion, useTarea } from '../../api'
import { comentarioDelHash } from '../../ficha'
import { urlBackend, urlFichero } from '../../enviar'
import Propiedades, { VisibilidadCliente } from './Propiedades'
import PanelActividad from './PanelActividad'
import type { CambiosTarea, TareaDetalle } from '../../schemas'

const SECCION = 'mt-[30px] mb-[13px] flex items-center gap-2 text-[11px] font-[650] tracking-[.6px] text-muted uppercase'

/* Ficha completa de la tarea (task.php, estilo ClickUp): columna de detalles y
   panel de actividad fijo a la derecha. En pantallas medianas el panel va
   debajo; en el móvil, pestañas «Detalles / Actividad» con deslizamiento. */
export default function TareaPage() {
  const { id: idTexto } = useParams()
  const id = Number(idTexto) || 0
  const { data, error, isPending } = useTarea(id)

  if (isPending) return <Cargando />
  if (error || !data) {
    const noHay = error instanceof ApiError && (error.status === 404 || error.status === 403)
    return (
      <div className="mx-auto max-w-[640px] pt-10">
        <EmptyState
          icon={<SearchX />}
          title={noHay ? 'Esta tarea no existe' : 'No se ha podido abrir la tarea'}
          text={noHay ? 'Puede que la hayan borrado o que no esté entre tus tareas.' : error?.message}
          actions={
            <Link to="/tareas?view=mine" className="text-[13px] font-semibold text-ink underline-offset-2 hover:underline">
              Volver a mis tareas
            </Link>
          }
        />
      </div>
    )
  }
  // La key reinicia el editor y los borradores al pasar de una tarea a otra.
  return <Ficha key={data.tarea.id} t={data.tarea} />
}

function Ficha({ t }: { t: TareaDetalle }) {
  const { me } = useAuth()
  const meId = me?.id ?? 0
  const navigate = useNavigate()
  const { hash } = useLocation()
  const { data: equipoData } = useEquipo()
  const equipo = useMemo(() => equipoData ?? [], [equipoData])
  const [guardado, setGuardado] = useState(0)
  const guardar = useGuardarFicha(t.id, () => setGuardado(Date.now()))
  const checklist = useChecklist(t.id)
  const adjuntos = useAdjuntos(t.id)
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const editar = t.permisos.editar
  const resaltar = comentarioDelHash(hash)
  const [pestana, setPestana] = useState<'detalles' | 'actividad'>(resaltar ? 'actividad' : 'detalles')
  const toque = useRef<{ x: number; y: number } | null>(null)
  const volverA = `/tareas?view=cliente&cli=${t.client_id}&list=${t.list_id}`
  const borrar = useBorrarTarea(() => navigate(volverA, { replace: true }))

  // «Guardado ✓» se ve 1,3 s tras cada guardado correcto.
  const verGuardado = useRecienGuardado(guardado || null)

  // #chk: la lista de control a la vista.
  useEffect(() => {
    if (hash !== '#chk') return
    const x = setTimeout(() => document.getElementById('chk')?.scrollIntoView({ block: 'center', behavior: 'smooth' }), 120)
    return () => clearTimeout(x)
  }, [hash])

  const cambiar = (c: CambiosTarea) => guardar.mutate(c)

  // Título: se guarda al salir o con Intro; vacío no se guarda.
  const [titulo, setTitulo] = useState<string | null>(null)
  function guardarTitulo() {
    const v = (titulo ?? '').trim()
    if (titulo !== null && v !== '' && v !== t.titulo) cambiar({ titulo: v })
    setTitulo(null)
  }

  // La descripción vive en el editor desde que se abre: el servidor ya no la pisa.
  const [descInicial] = useState(t.descripcion)

  async function onBorrar() {
    const ok = await confirm({ title: '¿Borrar la tarea?', message: 'Va a la papelera con sus comentarios, lista de control y adjuntos. Las horas apuntadas no se borran.', danger: true, okLabel: 'Borrar' })
    if (ok) borrar.mutate({ id: t.id })
  }

  function deslizar(e: TouchEvent) {
    const ini = toque.current
    toque.current = null
    if (!ini) return
    const dx = e.changedTouches[0].clientX - ini.x
    const dy = e.changedTouches[0].clientY - ini.y
    if (Math.abs(dx) >= 70 && Math.abs(dx) > 1.5 * Math.abs(dy)) setPestana(dx < 0 ? 'actividad' : 'detalles')
  }

  const items = t.checklist.map((c) => ({ id: c.id, texto: c.texto, hecho: c.done, asignados: c.asignados }))
  const adj = t.adjuntos.map((a) => ({ id: a.id, nombre: a.nombre, url: urlBackend(a.url), mime: a.mime || null }))

  const detalles = (
    <div>
      {/* Migas */}
      <nav aria-label="Migas" className="mb-4 flex flex-wrap items-center gap-2 text-[12.5px] text-muted">
        <button type="button" onClick={() => (window.history.length > 1 ? navigate(-1) : navigate(volverA))} className="inline-flex items-center gap-1.5 hover:text-ink">
          <ArrowLeft className="size-3.5" /> Volver
        </button>
        <span className="text-[#d4d7dd]">/</span>
        <Link to="/clientes" className="hover:text-ink">
          Clientes
        </Link>
        <span className="text-[#d4d7dd]">/</span>
        <Link to={`/clientes/${t.client_id}`} className="max-w-[200px] truncate hover:text-ink">
          {t.client_name ?? 'Cliente'}
        </Link>
        <span className="text-[#d4d7dd]">/</span>
        <Link to={volverA} className="max-w-[200px] truncate font-semibold text-ink">
          {t.list_name ?? 'Lista'}
        </Link>
        <span className="ml-auto flex items-center gap-2">
          <span className={`inline-flex items-center gap-1 text-[12px] font-semibold text-ok transition-opacity duration-200 ${verGuardado ? 'opacity-100' : 'opacity-0'}`} aria-live="polite">
            {verGuardado && (
              <>
                Guardado <Check className="size-3.5" />
              </>
            )}
          </span>
          <Menu
            label="Más acciones"
            align="right"
            trigger={() => (
              <span className="flex size-8 items-center justify-center rounded-lg text-label hover:bg-soft hover:text-ink">
                <MoreHorizontal className="size-[18px]" />
              </span>
            )}
          >
            {(cerrar) => (
              <>
                <MenuItem
                  icon={<LayoutList />}
                  onClick={() => {
                    cerrar()
                    navigate(volverA)
                  }}
                >
                  Abrir su lista
                </MenuItem>
                {t.permisos.borrar && (
                  <>
                    <MenuSeparator />
                    <MenuItem
                      icon={<Trash2 />}
                      danger
                      onClick={() => {
                        cerrar()
                        void onBorrar()
                      }}
                    >
                      Borrar tarea
                    </MenuItem>
                  </>
                )}
              </>
            )}
          </Menu>
        </span>
      </nav>

      <input
        value={titulo ?? t.titulo}
        onChange={(e) => setTitulo(e.target.value)}
        onBlur={guardarTitulo}
        onKeyDown={(e) => {
          if (e.key === 'Enter') (e.target as HTMLInputElement).blur()
          if (e.key === 'Escape') setTitulo(null)
        }}
        readOnly={!editar}
        maxLength={255}
        placeholder="Nombre de la tarea"
        aria-label="Nombre de la tarea"
        className="mb-3 w-full bg-transparent pb-3 text-[27px] leading-tight font-semibold tracking-[-.5px] text-ink-strong placeholder:text-label focus:outline-none max-sm:text-[23px]"
      />

      <Propiedades t={t} equipo={equipo} meId={meId} onCambio={cambiar} />

      <h3 className={`${SECCION} !mt-0`}>Descripción</h3>
      {editar ? (
        <RichTextEditor
          value={descInicial}
          onChange={(v) => cambiar({ descripcion: v })}
          placeholder="Añade una descripción… texto, imágenes, archivos y listas de control."
          mentions={equipo}
          toolbar={BARRA_DOC}
          resolveFileUrl={urlFichero}
          onUploadFiles={(files) => subirParaDescripcion(t.id, files).catch((e: unknown) => {
            aviso(e instanceof Error ? e.message : 'No se ha podido subir.', { tipo: 'error' })
            return []
          })}
          ariaLabel="Descripción"
        />
      ) : t.descripcion.trim() ? (
        <RichTextView value={t.descripcion} people={equipo} resolveFileUrl={urlFichero} className="text-[14px]" />
      ) : (
        <p className="text-[13.5px] text-label">Sin descripción.</p>
      )}

      <div id="chk" className="scroll-mt-24">
        <Checklist
          className="mt-[30px]"
          items={items}
          people={equipo}
          readOnly={!editar}
          onToggle={(chk, hecho) => checklist.cambiar.mutate({ chk: Number(chk), done: hecho })}
          onAdd={(texto, asignados) => checklist.crear.mutateAsync({ texto, asignados })}
          onDelete={(chk) => checklist.borrar.mutateAsync(Number(chk))}
          onAssign={(chk, asignados) => checklist.cambiar.mutate({ chk: Number(chk), asignados })}
          onReorder={(ids) => checklist.ordenar.mutate(ids.map(Number))}
        />
      </div>

      <h3 className={SECCION}>
        Adjuntos {t.adjuntos.length > 0 && <span className="rounded-full bg-soft px-[9px] py-px text-[11px] font-bold text-ink">{t.adjuntos.length}</span>}
      </h3>
      {adj.length > 0 && (
        <AttachmentList
          items={adj}
          className="mb-3"
          onRemove={
            editar
              ? async (a) => {
                  if (await confirm({ title: '¿Quitar adjunto?', message: `«${a.nombre}» deja de estar en la tarea.`, danger: true, okLabel: 'Quitar' })) adjuntos.quitar.mutate(Number(a.id))
                }
              : undefined
          }
        />
      )}
      {editar ? (
        <FileDropzone
          onFiles={(fs) => adjuntos.subir.mutate(fs)}
          label={adjuntos.subir.isPending ? 'Subiendo…' : 'Sube imágenes, vídeos o archivos'}
          hint={
            <>
              Haz clic aquí o <b className="font-semibold text-ink">arrastra archivos a cualquier parte</b> de la pantalla
            </>
          }
          onReject={(r) => aviso(r.map((x) => x.motivo).join(' '), { tipo: 'error' })}
        />
      ) : (
        adj.length === 0 && <p className="text-[13px] text-label">Sin adjuntos.</p>
      )}

      {editar && <VisibilidadCliente t={t} onCambio={cambiar} />}
    </div>
  )

  return (
    <div className="mx-auto max-w-[1400px] xl:mr-[540px] xl:max-w-none">
      {/* Móvil: pestañas */}
      <div role="tablist" aria-label="Ficha" className="sticky top-[53px] z-[5] -mx-4 mb-4 flex border-b border-line bg-page/95 px-4 backdrop-blur md:hidden">
        {(['detalles', 'actividad'] as const).map((p) => (
          <button
            key={p}
            role="tab"
            type="button"
            aria-selected={pestana === p}
            onClick={() => setPestana(p)}
            className={`flex-1 border-b-2 py-3 text-[13.5px] font-semibold ${pestana === p ? 'border-ink-strong text-ink-strong' : 'border-transparent text-muted'}`}
          >
            {p === 'detalles' ? 'Detalles' : 'Actividad'}
          </button>
        ))}
      </div>

      <div onTouchStart={(e) => (toque.current = { x: e.touches[0].clientX, y: e.touches[0].clientY })} onTouchEnd={deslizar}>
        <div className={pestana === 'detalles' ? '' : 'max-md:hidden'}>{detalles}</div>

        {/* Panel de actividad: fijo a la derecha en pantallas grandes, debajo en medianas. */}
        <aside
          aria-label="Actividad"
          data-panel-actividad
          className={`border-line bg-[#f6f7f8] dark:bg-soft max-xl:mt-10 max-xl:rounded-2xl max-xl:border max-xl:p-5 max-md:mt-0 max-md:border-0 max-md:bg-transparent max-md:p-0 dark:max-md:bg-transparent xl:fixed xl:top-[53px] xl:right-0 xl:bottom-0 xl:z-[4] xl:w-[540px] xl:border-l xl:px-7 xl:py-[22px] ${
            pestana === 'actividad' ? '' : 'max-md:hidden'
          } max-xl:h-[720px] max-md:h-[calc(100dvh-130px)]`}
        >
          <PanelActividad t={t} meId={meId} equipo={equipo} resaltar={resaltar} />
        </aside>
      </div>

      {editar && (
        <WindowDropOverlay
          title="Suelta para adjuntar"
          hint="En el cuadro de comentario → al comentario · en el resto → a la tarea"
          onFiles={(fs, destino) => {
            // Lo que cae en el panel de actividad lo recoge el compositor.
            if (destino?.closest('[data-panel-actividad]')) return
            adjuntos.subir.mutate(fs)
          }}
        />
      )}
    </div>
  )
}
