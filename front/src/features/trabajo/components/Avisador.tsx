import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { AtSign, Bell, BriefcaseBusiness, CheckSquare, FileText, MessageCircle, Ticket } from 'lucide-react'
import { useAuth } from '../../auth/useAuth'
import NotificationStack, { type AvisoEnVivo } from '../../../shared/ui/NotificationStack'
import { rutaDesdeLegado } from '../../../shared/lib/rutas'
import { api } from '../../../shared/api/client'
import { clavesNav } from '../../nav/api'
import type { Nav } from '../../nav/schemas'
import { clavesTrabajo, pedirSondeo } from '../api'
import { subtituloPopup, tituloPopup } from '../logica'
import { AccionAvisosRespuesta, type Sondeo } from '../schemas'

const CADA = 5000
const MAX = 6

const ICONOS: Record<string, typeof Bell> = { tarea: CheckSquare, lead: BriefcaseBusiness, factura: FileText, ticket: Ticket, tkreply: Ticket, mencion: AtSign, chat: MessageCircle }

/* «Blip» del antiguo (seno 680 → 920 Hz, 0,3 s). Si el navegador no deja
   sonar todavía (sin interacción), se calla sin más. */
function sonar() {
  try {
    const Ctx = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
    if (!Ctx) return
    const ctx = new Ctx()
    const o = ctx.createOscillator()
    const g = ctx.createGain()
    o.type = 'sine'
    o.frequency.setValueAtTime(680, ctx.currentTime)
    o.frequency.exponentialRampToValueAtTime(920, ctx.currentTime + 0.18)
    g.gain.setValueAtTime(0.0001, ctx.currentTime)
    g.gain.exponentialRampToValueAtTime(0.1, ctx.currentTime + 0.02)
    g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.3)
    o.connect(g).connect(ctx.destination)
    o.start()
    o.stop(ctx.currentTime + 0.32)
    o.onended = () => void ctx.close()
  } catch {
    // Sin audio: el aviso se ve igual.
  }
}

/* Avisador global (erp_foot del antiguo): pregunta cada 5 s, con la pestaña
   visible y al volver a ella, si hay avisos nuevos. El primer sondeo solo fija
   la línea base; después, cada aviso nuevo sale en la pila de pop-ups con un
   «blip», y el globo de la campana se actualiza con el mismo sondeo. */
export default function Avisador() {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const chat = useAuth().can('ver.chat')
  const [pila, setPila] = useState<AvisoEnVivo[]>([])
  const base = useRef<number | null>(null)
  const enCurso = useRef(false)

  const recibir = useCallback(
    (r: Sondeo) => {
      // El globo de la campana sale del mismo sondeo: no hace falta otro.
      qc.setQueryData<Nav>(clavesNav.nav, (n) => (n && n.no_leidas !== r.no_leidas ? { ...n, no_leidas: r.no_leidas } : n))
      const primera = base.current === null
      base.current = Math.max(base.current ?? 0, r.ultimo_id)
      if (primera || r.nuevos.length === 0) return
      /* Como el antiguo: un pop-up con el más reciente y «+N más» si llegan varios. */
      const a = r.nuevos[r.nuevos.length - 1]
      const Icono = ICONOS[a.tipo] ?? Bell
      const nuevos: AvisoEnVivo[] = [
        {
          id: a.id,
          titulo: tituloPopup(a),
          subtitulo: subtituloPopup(a, r.nuevos.length - 1) || undefined,
          icono: <Icono />,
          onClick: () => {
            void api('/api/v1/notificaciones/acciones', { method: 'POST', body: { accion: 'leer', ids: [a.id] }, schema: AccionAvisosRespuesta })
              .then(() => {
                void qc.invalidateQueries({ queryKey: clavesNav.nav })
                void qc.invalidateQueries({ queryKey: clavesTrabajo.avisos })
              })
              .catch(() => undefined)
            navigate(a.url ? rutaDesdeLegado(a.url) : '/notificaciones')
          },
        },
      ]
      setPila((p) => [...p, ...nuevos].slice(-MAX))
      sonar()
      void qc.invalidateQueries({ queryKey: clavesTrabajo.avisos })
    },
    [qc, navigate],
  )

  useEffect(() => {
    let vivo = true
    const ctl = new AbortController()
    async function sondear() {
      if (enCurso.current || document.visibilityState !== 'visible') return
      enCurso.current = true
      try {
        const r = await pedirSondeo(base.current ?? 0, ctl.signal)
        if (vivo) recibir(r)
      } catch {
        // Sin red o sesión caducada: el siguiente sondeo lo vuelve a intentar.
      } finally {
        enCurso.current = false
      }
    }
    void sondear()
    const t = setInterval(() => void sondear(), CADA)
    const alVolver = () => document.visibilityState === 'visible' && void sondear()
    document.addEventListener('visibilitychange', alVolver)
    return () => {
      vivo = false
      ctl.abort()
      clearInterval(t)
      document.removeEventListener('visibilitychange', alVolver)
    }
  }, [recibir])

  const pilaAvisos = <NotificationStack items={pila} onDismiss={(id) => setPila((p) => p.filter((x) => x.id !== id))} />
  /* El avisador del chat usa la misma pila arriba a la derecha: con chat, la
     de avisos baja por debajo de la suya (un ancestro con transform hace que
     la posición fija de la pila cuente desde aquí). */
  if (!chat || pila.length === 0) return pilaAvisos
  return <div className="pointer-events-none fixed top-[264px] right-0 z-[600] h-0 w-full [transform:translateZ(0)] [&>*]:pointer-events-auto">{pilaAvisos}</div>
}
