import { useCallback, useEffect, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { api, conQuery } from '../../shared/api/client'
import Avatar from '../../shared/ui/Avatar'
import NotificationStack, { type AvisoEnVivo } from '../../shared/ui/NotificationStack'
import { useAuth } from '../auth/useAuth'
import { clavesNav } from '../nav/api'
import { clavesCom } from './api'
import { estaActivo } from './chat/useConversacion'
import { agruparAvisos } from './logica'
import { AvisosRespuesta } from './schemas'

const PRIMERO = 1500
const CADA = 5000

/* Avisador global del chat (§5.4 del antiguo, erp_nav.php): en cualquier
   pantalla, cada 5 s pregunta si hay mensajes nuevos en tus salas y, si los
   hay (y no son de la sala que tienes abierta), enseña un pop-up, suena y,
   con la pestaña en segundo plano, manda una notificación del navegador.
   Respeta «Silenciar el chat» del perfil (el antiguo no lo hacía).
   Se monta una vez en el layout: <AvisadorChat />. */
export default function AvisadorChat() {
  const { me, can } = useAuth()
  const activo = !!me && can('ver.chat')
  const navegar = useNavigate()
  const { pathname } = useLocation()
  const qc = useQueryClient()
  const [pops, setPops] = useState<AvisoEnVivo[]>([])
  const max = useRef(-1)
  const ruta = useRef(pathname)
  useEffect(() => {
    ruta.current = pathname
  }, [pathname])

  const sondear = useCallback(async () => {
    try {
      const r = await api(conQuery('/api/v1/chat/avisos', { despues: max.current >= 0 ? max.current : undefined, activo: estaActivo() ? 1 : 0 }), { schema: AvisosRespuesta })
      const primera = max.current < 0
      max.current = Math.max(max.current, r.max)
      if (primera || !r.mensajes.length) return
      void qc.invalidateQueries({ queryKey: clavesCom.salas })
      void qc.invalidateQueries({ queryKey: clavesNav.nav })
      if (r.silenciado) return
      const visible = document.visibilityState === 'visible'
      const abierta = /^\/chat\/(\d+)/.exec(ruta.current)?.[1]
      const grupos = agruparAvisos(r.mensajes.filter((m) => !(visible && String(m.sala_id) === abierta)))
      if (!grupos.length) return
      sonar()
      if (visible) {
        setPops((ps) =>
          [
            ...ps.filter((p) => !grupos.some((g) => `chat-${g.sala_id}` === p.id)),
            ...grupos.map((g) => ({
              id: `chat-${g.sala_id}`,
              titulo: g.titulo,
              subtitulo: g.texto,
              icono: <Avatar nombre={g.autor} foto={g.foto} size={34} />,
              onClick: () => navegar(`/chat/${g.sala_id}`),
            })),
          ].slice(-6),
        )
      } else if ('Notification' in window && Notification.permission === 'granted') {
        for (const g of grupos) {
          const n = new Notification(g.titulo, { body: g.texto, tag: `chat-${g.sala_id}` })
          n.onclick = () => {
            window.focus()
            navegar(`/chat/${g.sala_id}`)
            n.close()
          }
        }
      }
    } catch {
      // Sin red o sesión caducada: el siguiente sondeo lo vuelve a intentar.
    }
  }, [navegar, qc])

  useEffect(() => {
    if (!activo) return
    max.current = -1
    let t: number | undefined
    let vivo = true
    const ciclo = async () => {
      if (document.visibilityState === 'visible') await sondear()
      if (vivo) t = window.setTimeout(ciclo, CADA)
    }
    t = window.setTimeout(ciclo, PRIMERO)
    const alVolver = () => document.visibilityState === 'visible' && void sondear()
    document.addEventListener('visibilitychange', alVolver)
    // El navegador solo deja pedir permiso tras un gesto: al primer clic o tecla.
    const pedirPermiso = () => {
      if ('Notification' in window && Notification.permission === 'default') void Notification.requestPermission()
    }
    window.addEventListener('pointerdown', pedirPermiso, { once: true })
    window.addEventListener('keydown', pedirPermiso, { once: true })
    return () => {
      vivo = false
      window.clearTimeout(t)
      document.removeEventListener('visibilitychange', alVolver)
      window.removeEventListener('pointerdown', pedirPermiso)
      window.removeEventListener('keydown', pedirPermiso)
    }
  }, [activo, sondear])

  if (!activo) return null
  return <NotificationStack items={pops} onDismiss={(id) => setPops((ps) => ps.filter((p) => p.id !== id))} />
}

/* «Blip» del antiguo: seno de 620 a 880 Hz en 0,34 s, bajito. */
let audio: AudioContext | null = null
function sonar() {
  try {
    const W = window as unknown as { AudioContext?: typeof AudioContext; webkitAudioContext?: typeof AudioContext }
    const C = W.AudioContext ?? W.webkitAudioContext
    if (!C) return
    audio ??= new C()
    const o = audio.createOscillator()
    const g = audio.createGain()
    const t = audio.currentTime
    o.type = 'sine'
    o.frequency.setValueAtTime(620, t)
    o.frequency.exponentialRampToValueAtTime(880, t + 0.16)
    g.gain.setValueAtTime(0.0001, t)
    g.gain.exponentialRampToValueAtTime(0.12, t + 0.02)
    g.gain.exponentialRampToValueAtTime(0.0001, t + 0.34)
    o.connect(g).connect(audio.destination)
    o.start(t)
    o.stop(t + 0.36)
  } catch {
    // Sin audio (política del navegador hasta el primer gesto): no pasa nada.
  }
}
