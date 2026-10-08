import { useMemo, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { Eye, Pencil, Save, X } from 'lucide-react'
import { ApiError } from '../../../../shared/api/client'
import { useUnsavedGuard } from '../../../../shared/lib/useUnsavedGuard'
import Cargando from '../../../../shared/ui/Cargando'
import { useConfirm } from '../../../../shared/ui/useConfirm'
import { useToast } from '../../../../shared/ui/useToast'
import { useDatosPortal, useEditor, useGuardarEditor, type Origen } from '../../api'
import { hacerRuta, PortalContext, type BloqueEditable, type PortalCtx } from '../../contexto'
import { aplicarEdicion } from '../../logica'
import type { ContenidoEditable } from '../../schemas'
import PortalRutas from '../PortalRutas'
import ModalesEditor from './Modales'

const ALTO_BARRA = 52

/* El portal de un cliente visto por el equipo (antes index.php?cli=N y
   &edit=1): vista previa de solo lectura y, con permiso, editor en vivo. El
   contenido se edita sobre una copia y no se guarda hasta «Guardar cambios». */
export default function PortalEquipo() {
  const { id: idTxt } = useParams()
  const cli = Number(idTxt)
  const [params] = useSearchParams()
  const origen = useMemo<Origen>(() => ({ tipo: 'equipo', cli }), [cli])
  const datos = useDatosPortal(origen)
  const editor = useEditor(cli)
  const guardar = useGuardarEditor(cli)
  const { aviso } = useToast()
  const { confirm } = useConfirm()
  const [editando, setEditando] = useState(params.get('editar') === '1')
  const [borrador, setBorrador] = useState<ContenidoEditable | null>(null)
  const [password, setPassword] = useState('')
  const [bloque, setBloque] = useState<BloqueEditable | null>(null)
  const sucio = borrador !== null || password !== ''
  useUnsavedGuard(sucio)

  const puede = editor.data?.puede_guardar ?? false
  const contenido = borrador ?? editor.data?.contenido ?? null
  const base = `/clientes/${cli}/portal`
  const enEdicion = editando && puede && !!contenido

  const ctx = useMemo<PortalCtx | null>(() => {
    if (!datos.data) return null
    const vistos = enEdicion && contenido ? aplicarEdicion(datos.data, contenido, new Date()) : datos.data
    return { datos: vistos, origen, base, ruta: hacerRuta(base), editar: enEdicion ? setBloque : null }
  }, [datos.data, enEdicion, contenido, origen, base])

  if (datos.isError) {
    const e = datos.error
    return (
      <div className="flex min-h-screen items-center justify-center p-6 text-center">
        <div>
          <p className="text-[17px] font-bold text-ink-strong">{e instanceof ApiError && e.status === 404 ? 'No encuentro ese cliente' : 'No se ha podido abrir el portal'}</p>
          <p className="mt-1 text-[14px] text-label">{e instanceof ApiError ? e.message : ''}</p>
          <Link to="/clientes" className="mt-4 inline-block font-semibold text-ink-strong underline">
            Ver mis clientes
          </Link>
        </div>
      </div>
    )
  }
  if (!ctx) return <Cargando />

  const salirEdicion = async () => {
    if (sucio && !(await confirm({ title: 'Hay cambios sin guardar', message: 'Si sales del modo edición se pierden.', okLabel: 'Descartar cambios', danger: true }))) return
    setBorrador(null)
    setPassword('')
    setEditando(false)
  }
  const guardarTodo = async () => {
    if (!contenido) return
    try {
      await guardar.mutateAsync({ ...contenido, ...(password ? { password } : {}) })
      setBorrador(null)
      setPassword('')
      aviso('✓ Guardado. Así lo ve el cliente.', { tipo: 'ok' })
    } catch (e) {
      aviso(`⚠ ${e instanceof ApiError ? e.message : 'Error de conexión'}`, { tipo: 'error', ms: 6000 })
    }
  }

  return (
    <PortalContext.Provider value={ctx}>
      <div className="sticky top-0 z-[950] flex items-center gap-2.5 bg-[#0f1012] px-4 text-white print:hidden" style={{ height: ALTO_BARRA }}>
        <span className="flex items-center gap-2 text-[13.5px] font-bold whitespace-nowrap">
          {enEdicion ? <Pencil className="size-4 text-[#ff9500]" /> : <Eye className="size-4" />}
          {enEdicion ? 'Modo edición' : 'Vista previa'}
        </span>
        <span className="truncate text-[13.5px] text-white/60 max-sm:hidden">· {ctx.datos.cliente.name}</span>
        <span className="flex-1" />
        {enEdicion ? (
          <>
            <button type="button" onClick={() => setBloque('identidad')} className="rounded-lg px-3 py-1.5 text-[13px] font-semibold text-white/85 hover:bg-white/10 max-sm:hidden">
              Editar identidad
            </button>
            <button
              type="button"
              onClick={() => void guardarTodo()}
              disabled={!sucio || guardar.isPending}
              className="inline-flex items-center gap-1.5 rounded-lg bg-[#ff9500] px-3.5 py-1.5 text-[13px] font-bold text-white disabled:opacity-50"
            >
              <Save className="size-4" /> Guardar cambios
            </button>
            <button type="button" onClick={() => void salirEdicion()} className="rounded-lg p-1.5 text-white/70 hover:bg-white/10" aria-label="Salir del modo edición" title="Salir del modo edición">
              <X className="size-4" />
            </button>
          </>
        ) : (
          <>
            {puede && (
              <button type="button" onClick={() => setEditando(true)} className="inline-flex items-center gap-1.5 rounded-lg bg-[#ff9500] px-3.5 py-1.5 text-[13px] font-bold text-white">
                <Pencil className="size-4" /> Editar portal
              </button>
            )}
          </>
        )}
        <Link to={`/clientes/${cli}`} className="rounded-lg px-3 py-1.5 text-[13px] font-semibold text-white/85 hover:bg-white/10">
          Salir
        </Link>
      </div>
      <PortalRutas onSalir={null} arriba={ALTO_BARRA} />
      {editor.data && contenido && (
        <ModalesEditor
          bloque={bloque}
          contenido={contenido}
          password={password}
          editor={editor.data}
          onClose={() => setBloque(null)}
          onAplicar={(c, p) => {
            setBorrador(c)
            if (p !== undefined) setPassword(p)
          }}
        />
      )}
    </PortalContext.Provider>
  )
}
