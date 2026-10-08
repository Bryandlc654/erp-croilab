import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type MouseEvent } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ChevronLeft, Copy, CornerUpLeft, MessageCircle, MoreVertical, Pencil, Trash2, UserRound } from 'lucide-react'
import { api } from '../../../shared/api/client'
import { PRESENCIA } from '../../../shared/lib/paletas'
import EmptyState from '../../../shared/ui/EmptyState'
import { MenuItem, MenuPanel, MenuSeparator } from '../../../shared/ui/Menu'
import { Lightbox, QuickReactions, type Adjunto } from '../../../shared/ui/rich'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { useAuth } from '../../auth/useAuth'
import { clavesCom, enviarFormulario, mensaje, useSalas } from '../api'
import { agruparMensajes, etiquetaDia, textoEscribiendo } from '../logica'
import { MensajeRespuesta, SalaRespuesta, VacioRespuesta, type Mensaje, type Sala, type SalasDatos } from '../schemas'
import Burbuja, { type AccionesMensaje } from './Burbuja'
import Compositor from './Compositor'
import ListaSalas, { AvatarSala } from './ListaSalas'
import { InfoGrupoModal, NuevoMensajeModal } from './Modales'
import { useConversacion } from './useConversacion'

/* Chat del equipo (chat.php): salas a la izquierda y la conversación a la
   derecha; en el móvil, una cosa u otra. /chat/:sala abre una sala y
   /chat?dm=<id> el directo con esa persona (lo crea si no existe). */
export default function ChatPage() {
  const { can } = useAuth()
  const salaId = Number(useParams().sala ?? 0) || 0
  const [params] = useSearchParams()
  const navegar = useNavigate()
  const q = useSalas()
  const qc = useQueryClient()
  const { aviso } = useToast()
  const [nuevo, setNuevo] = useState(false)
  const dm = Number(params.get('dm') ?? 0) || 0

  // ?dm=<id>: busca o crea el directo y lo abre.
  useEffect(() => {
    if (!dm) return
    let vivo = true
    api('/api/v1/chat/salas', { method: 'POST', body: { tipo: 'dm', con: dm }, schema: SalaRespuesta })
      .then((r) => {
        if (!vivo) return
        void qc.invalidateQueries({ queryKey: clavesCom.salas })
        navegar(`/chat/${r.sala.id}`, { replace: true })
      })
      .catch((e: unknown) => {
        if (vivo) aviso(mensaje(e, 'No se ha podido abrir el chat.'), { tipo: 'error' })
        navegar('/chat', { replace: true })
      })
    return () => {
      vivo = false
    }
  }, [dm, navegar, qc, aviso])

  // En escritorio, /chat abre la conversación más reciente (como el antiguo).
  useEffect(() => {
    if (salaId || dm || !q.data?.salas.length) return
    if (window.matchMedia('(min-width: 768px)').matches) navegar(`/chat/${q.data.salas[0].id}`, { replace: true })
  }, [salaId, dm, q.data, navegar])

  if (!can('ver.chat')) return <EmptyState icon={<MessageCircle />} title="Sin acceso" text="No tienes permiso para usar el chat del equipo." />

  const datos = q.data
  const sala = datos?.salas.find((s) => s.id === salaId) ?? null
  return (
    <div className="-mx-4 -my-5 flex h-[calc(100dvh-53px)] overflow-hidden md:-mx-6 md:-my-6 lg:-mx-[52px] lg:-mt-9 lg:-mb-20">
      <aside className={`w-[290px] shrink-0 border-r border-line bg-card max-md:w-full ${salaId ? 'max-md:hidden' : ''}`}>
        {datos ? (
          <ListaSalas datos={datos} activa={salaId} onNuevo={() => setNuevo(true)} />
        ) : (
          <div className="space-y-3 p-5" aria-busy="true">
            {[0, 1, 2, 3].map((i) => (
              <div key={i} className="h-12 animate-pulse rounded-xl bg-soft" />
            ))}
          </div>
        )}
      </aside>
      <section className={`flex min-w-0 flex-1 flex-col bg-page ${salaId ? '' : 'max-md:hidden'}`}>
        {datos && sala ? (
          <Conversacion key={sala.id} sala={sala} datos={datos} />
        ) : datos && salaId && !q.isFetching ? (
          <EmptyState variant="inline" className="m-auto" icon={<MessageCircle />} title="Conversación no encontrada" text="Puede que ya no estés en ella." />
        ) : (
          <div className="m-auto text-center">
            <MessageCircle className="mx-auto mb-3 size-9 text-label" strokeWidth={1.6} aria-hidden="true" />
            <p className="text-[14px] font-semibold text-ink">Selecciona una conversación</p>
            <p className="mt-0.5 text-[13.5px] text-muted">o empieza un grupo / mensaje directo.</p>
          </div>
        )}
      </section>
      {datos && <NuevoMensajeModal open={nuevo} onClose={() => setNuevo(false)} datos={datos} />}
    </div>
  )
}

function Conversacion({ sala, datos }: { sala: Sala; datos: SalasDatos }) {
  const { me } = useAuth()
  const { aviso } = useToast()
  const { confirm } = useConfirm()
  const navegar = useNavigate()
  const c = useConversacion(sala.id, sala.no_leidos)
  const feed = useRef<HTMLDivElement>(null)
  const [respondiendo, setRespondiendo] = useState<Mensaje | null>(null)
  const [editando, setEditando] = useState<Mensaje | null>(null)
  const [resaltado, setResaltado] = useState(0)
  const [info, setInfo] = useState(false)
  const [abajo, setAbajo] = useState(true)
  const [nuevosAbajo, setNuevosAbajo] = useState(0)
  const [lightbox, setLightbox] = useState<{ items: Adjunto[]; i: number } | null>(null)
  const [menu, setMenu] = useState<{ m: Mensaje; x: number; y: number } | null>(null)
  const yo = me?.id ?? datos.yo
  const miNombre = me?.username ?? ''
  const nombres = useMemo(() => sala.miembros.map((m) => m.username), [sala.miembros])
  const otros = useMemo(() => sala.miembros.filter((m) => m.id !== yo), [sala.miembros, yo])
  const feedItems = useMemo(() => agruparMensajes(c.mensajes), [c.mensajes])

  /* «Mensajes nuevos»: antes del primero de los que no había leído al abrir. */
  const primerNuevo = useMemo(() => {
    if (!c.sinLeerAlAbrir) return 0
    const ajenos = c.mensajes.filter((m) => m.autor_id !== yo)
    return ajenos[Math.max(0, ajenos.length - c.sinLeerAlAbrir)]?.id ?? 0
    // Se calcula con la carga inicial y no cambia al llegar más.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [c.sinLeerAlAbrir, c.cargando])

  // Al abrir: abajo del todo (o al divisor de nuevos). Al llegar mensajes: abajo si ya estaba abajo.
  const ultimoId = c.mensajes[c.mensajes.length - 1]?.id ?? 0
  const prevUltimo = useRef(0)
  useLayoutEffect(() => {
    const el = feed.current
    if (!el || c.cargando) return
    if (prevUltimo.current === 0) {
      const div = primerNuevo ? document.getElementById(`msg-${primerNuevo}`) : null
      if (div) el.scrollTop = Math.max(0, div.offsetTop - 80)
      else el.scrollTop = el.scrollHeight
    } else if (ultimoId > prevUltimo.current) {
      const mio = c.mensajes[c.mensajes.length - 1]?.autor_id === yo
      if (abajo || mio) el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' })
      else setNuevosAbajo((n) => n + c.mensajes.filter((m) => m.id > prevUltimo.current && m.autor_id !== yo).length)
    }
    prevUltimo.current = ultimoId
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ultimoId, c.cargando])

  const altoAntes = useRef(0)
  async function alSubir() {
    const el = feed.current
    if (!el || !c.hayMas) return
    altoAntes.current = el.scrollHeight
    const n = await c.cargarAnteriores()
    // Mantiene la vista en el mismo mensaje tras meter los anteriores arriba.
    requestAnimationFrame(() => {
      if (n && feed.current) feed.current.scrollTop += feed.current.scrollHeight - altoAntes.current
    })
  }

  function alDesplazar() {
    const el = feed.current
    if (!el) return
    const enFondo = el.scrollHeight - el.scrollTop - el.clientHeight < 120
    setAbajo(enFondo)
    if (enFondo) setNuevosAbajo(0)
    if (el.scrollTop < 60 && c.hayMas) void alSubir()
  }

  const irA = useCallback((id: number) => {
    const el = document.getElementById(`msg-${id}`)
    if (!el) return
    el.scrollIntoView({ behavior: 'smooth', block: 'center' })
    setResaltado(id)
    window.setTimeout(() => setResaltado(0), 1600)
  }, [])

  async function enviar(texto: string, archivos: File[]) {
    try {
      let m: Mensaje
      if (archivos.length) {
        const f = new FormData()
        f.append('texto', texto)
        if (respondiendo) f.append('responde_a', String(respondiendo.id))
        for (const a of archivos) f.append('adjuntos[]', a)
        m = (await enviarFormulario(`/api/v1/chat/salas/${sala.id}/mensajes`, f, MensajeRespuesta)).mensaje
      } else {
        m = (await api(`/api/v1/chat/salas/${sala.id}/mensajes`, { method: 'POST', body: { texto, responde_a: respondiendo?.id ?? null }, schema: MensajeRespuesta })).mensaje
      }
      c.aplicar([m])
    } catch (e) {
      aviso(mensaje(e, 'No se ha podido enviar el mensaje.'), { tipo: 'error' })
      throw e
    }
  }

  const accion = useCallback(
    async (f: () => Promise<{ mensaje: Mensaje }>) => {
      try {
        c.aplicar([(await f()).mensaje])
      } catch (e) {
        aviso(mensaje(e), { tipo: 'error' })
        throw e
      }
    },
    // c.aplicar es estable
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [aviso],
  )

  const acciones = useMemo<AccionesMensaje>(
    () => ({
      onReaccionar: (m, emoji) => void accion(() => api(`/api/v1/chat/mensajes/${m.id}/reacciones`, { method: 'POST', body: { emoji }, schema: MensajeRespuesta })).catch(() => {}),
      onMenu: (m, e: MouseEvent) => {
        e.preventDefault()
        setMenu({ m, x: e.clientX, y: e.clientY })
      },
      onMenuEn: (m, x, y) => setMenu({ m, x, y }),
      onCita: irA,
      onAbrirImagen: (items, i) => setLightbox({ items, i }),
    }),
    [accion, irA],
  )

  async function borrar(m: Mensaje) {
    const ok = await confirm({ title: 'Eliminar mensaje', message: '¿Eliminar este mensaje para todos?', okLabel: 'Eliminar', danger: true })
    if (ok) await accion(() => api(`/api/v1/chat/mensajes/${m.id}`, { method: 'DELETE', schema: MensajeRespuesta })).catch(() => {})
  }

  const otro = sala.tipo === 'dm' ? sala.miembros.find((m) => m.id === sala.otro_id) : undefined
  const presOtro = otro ? (c.presencia[String(otro.id)] ?? datos.presencia[String(otro.id)]) : undefined
  const escriben = textoEscribiendo(c.escribiendo.map((p) => p.username))
  const anchoMenu = useMemo(() => (menu ? { x: menu.x, y: menu.y } : null), [menu])

  return (
    <>
      <header className="flex h-[60px] shrink-0 items-center gap-3 border-b border-line bg-card px-4 max-sm:px-2.5">
        <button type="button" onClick={() => navegar('/chat')} aria-label="Volver a las conversaciones" className="flex size-8 items-center justify-center rounded-lg text-label hover:bg-soft md:hidden">
          <ChevronLeft className="size-5" />
        </button>
        <AvatarSala sala={sala} datos={{ ...datos, presencia: { ...datos.presencia, ...c.presencia } }} size={38} />
        <div className="min-w-0 flex-1">
          <p className="truncate text-[15px] font-semibold text-ink-strong">{sala.nombre}</p>
          <p className="truncate text-[12px]">
            {escriben ? (
              <span className="font-semibold text-[#1a9d5b]">escribiendo…</span>
            ) : sala.tipo === 'grupo' ? (
              <span className="text-muted">{sala.miembros.length} miembros</span>
            ) : presOtro ? (
              <span className={presOtro.estado === 'online' ? 'font-semibold text-[#1a9d5b]' : 'text-muted'} style={presOtro.estado === 'idle' ? { color: PRESENCIA.idle.color } : undefined}>
                {presOtro.texto}
              </span>
            ) : (
              <span className="text-muted">Mensaje directo</span>
            )}
          </p>
        </div>
        <button
          type="button"
          onClick={() => (sala.tipo === 'grupo' ? setInfo(true) : otro && navegar(`/perfil/${otro.id}`))}
          aria-label={sala.tipo === 'grupo' ? 'Info del grupo' : 'Ver perfil'}
          title={sala.tipo === 'grupo' ? 'Info del grupo' : 'Ver perfil'}
          className="flex size-9 items-center justify-center rounded-[10px] text-label hover:bg-soft hover:text-ink"
        >
          <MoreVertical className="size-[18px]" />
        </button>
      </header>

      <div className="relative min-h-0 flex-1">
        <div ref={feed} onScroll={alDesplazar} className="h-full overflow-y-auto px-[30px] py-[26px] max-sm:px-3 max-sm:py-4" aria-live="polite">
          {c.cargando ? (
            <div className="space-y-3" aria-busy="true">
              {[0, 1, 2].map((i) => (
                <div key={i} className={`h-10 w-2/5 animate-pulse rounded-2xl bg-soft ${i % 2 ? 'ml-auto' : ''}`} />
              ))}
            </div>
          ) : c.error ? (
            <EmptyState variant="inline" title="No se ha podido abrir la conversación" text={mensaje(c.error, 'Inténtalo de nuevo.')} />
          ) : c.mensajes.length === 0 ? (
            <p className="mt-10 text-center text-[13px] text-muted">Aún no hay mensajes. ¡Escribe el primero!</p>
          ) : (
            <>
              {c.hayMas && (
                <button type="button" onClick={() => void alSubir()} className="mx-auto mb-3 block rounded-full border border-line bg-card px-3 py-1 text-[11.5px] font-semibold text-muted hover:text-ink">
                  Cargar mensajes anteriores
                </button>
              )}
              {feedItems.map(({ m, dia, nuevoDia, continua }) => (
                <div key={m.id}>
                  {nuevoDia && (
                    <div className="my-4 flex justify-center">
                      <span className="rounded-full border border-line bg-card px-3 py-[3px] text-[11px] font-semibold text-muted">{etiquetaDia(dia)}</span>
                    </div>
                  )}
                  {m.id === primerNuevo && (
                    <div className="my-3 rounded-md bg-[rgba(47,111,237,.08)] py-1 text-center text-[10.5px] font-bold tracking-[.6px] text-[#2f6fed] uppercase dark:text-[#7aa7ff]">Mensajes nuevos</div>
                  )}
                  <Burbuja
                    m={m}
                    propio={m.autor_id === yo}
                    grupo={sala.tipo === 'grupo'}
                    continua={continua && m.id !== primerNuevo}
                    leido={m.id <= c.leidoHasta}
                    resaltado={resaltado === m.id}
                    nombres={nombres}
                    yo={miNombre}
                    acciones={acciones}
                  />
                </div>
              ))}
            </>
          )}
        </div>
        {!abajo && (
          <button
            type="button"
            onClick={() => feed.current?.scrollTo({ top: feed.current.scrollHeight, behavior: 'smooth' })}
            aria-label="Ir al último mensaje"
            className="absolute right-5 bottom-4 flex size-10 items-center justify-center rounded-full border border-line bg-card text-ink shadow-pop"
          >
            <ArrowDown className="size-[18px]" />
            {nuevosAbajo > 0 && <span className="absolute -top-1.5 -right-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-[#12a150] px-1 text-[11px] font-bold text-white">{nuevosAbajo}</span>}
          </button>
        )}
      </div>

      {escriben && (
        <div className="flex items-center gap-2 bg-page px-[30px] pb-1.5 text-[12px] text-muted max-sm:px-3" aria-live="polite">
          <span className="flex gap-[3px]" aria-hidden="true">
            {[0, 1, 2].map((i) => (
              <span key={i} className="size-1.5 animate-bounce rounded-full bg-[#b7bcc4]" style={{ animationDelay: `${i * 150}ms` }} />
            ))}
          </span>
          {escriben}
        </div>
      )}

      <Compositor
        personas={otros}
        respondiendo={respondiendo}
        editando={editando}
        onCancelar={() => {
          setRespondiendo(null)
          setEditando(null)
        }}
        onEnviar={enviar}
        onGuardarEdicion={(m, texto) => accion(() => api(`/api/v1/chat/mensajes/${m.id}`, { method: 'PATCH', body: { texto }, schema: MensajeRespuesta }))}
        onEscribiendo={() => void api(`/api/v1/chat/salas/${sala.id}/escribiendo`, { method: 'POST', schema: VacioRespuesta }).catch(() => {})}
        onEditarUltimo={() => {
          const m = [...c.mensajes].reverse().find((x) => x.autor_id === yo && !x.borrado)
          if (m) {
            setRespondiendo(null)
            setEditando(m)
          }
        }}
      />

      <MenuPanel open={!!menu} onClose={() => setMenu(null)} anchor={anchoMenu} label="Acciones del mensaje" minWidth={230}>
        {menu && (
          <>
            <div className="px-1 pt-1 pb-1.5">
              <QuickReactions
                className="!border-0 !p-0 !shadow-none"
                onPick={(emoji) => {
                  acciones.onReaccionar(menu.m, emoji)
                  setMenu(null)
                }}
              />
            </div>
            <MenuSeparator />
            <MenuItem icon={<CornerUpLeft />} onSelect={() => { setEditando(null); setRespondiendo(menu.m) }}>
              Responder
            </MenuItem>
            {menu.m.texto && (
              <MenuItem icon={<Copy />} onSelect={() => void navigator.clipboard?.writeText(menu.m.texto).then(() => aviso('Copiado'))}>
                Copiar
              </MenuItem>
            )}
            {menu.m.autor_id !== yo ? (
              <MenuItem icon={<UserRound />} onSelect={() => navegar(`/perfil/${menu.m.autor_id}`)}>
                Ver perfil de {menu.m.autor}
              </MenuItem>
            ) : (
              <>
                <MenuItem icon={<Pencil />} onSelect={() => { setRespondiendo(null); setEditando(menu.m) }}>
                  Editar
                </MenuItem>
                <MenuItem icon={<Trash2 />} danger onSelect={() => void borrar(menu.m)}>
                  Eliminar
                </MenuItem>
              </>
            )}
          </>
        )}
      </MenuPanel>
      {lightbox && <Lightbox items={lightbox.items} index={lightbox.i} onClose={() => setLightbox(null)} onIndexChange={(i) => setLightbox((l) => (l ? { ...l, i } : l))} />}
      {sala.tipo === 'grupo' && <InfoGrupoModal open={info} onClose={() => setInfo(false)} sala={sala} datos={datos} />}
    </>
  )
}
