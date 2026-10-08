import { useCallback, useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, conQuery } from '../../../shared/api/client'
import { clavesNav } from '../../nav/api'
import { clavesCom } from '../api'
import { fusionarMensajes } from '../logica'
import { HistorialRespuesta, NovedadesRespuesta, type Mensaje, type PresenciaEstado, type SalasDatos } from '../schemas'
import type { Persona } from '../../../shared/schemas'

const CADA = 2500

/* «Activo» = pestaña visible y alguien ha tocado ratón o teclado en el último minuto. */
let ultimaInteraccion = Date.now()
if (typeof window !== 'undefined') {
  for (const ev of ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'focus']) {
    window.addEventListener(ev, () => (ultimaInteraccion = Date.now()), { passive: true, capture: true })
  }
}
export function estaActivo() {
  return document.visibilityState === 'visible' && Date.now() - ultimaInteraccion < 60_000
}

export type EstadoConversacion = {
  mensajes: Mensaje[]
  cargando: boolean
  error: unknown
  hayMas: boolean
  leidoHasta: number
  escribiendo: Persona[]
  presencia: Record<string, PresenciaEstado>
  /* Mensajes de otros que han llegado desde la apertura (para «Mensajes nuevos»). */
  sinLeerAlAbrir: number
}

/* Una sala abierta: carga el historial y sondea cada 2,5 s con cursores
   (solo lo nuevo y lo cambiado). En segundo plano no sondea: el avisador
   global se encarga; al volver a la pestaña sondea en el acto. */
export function useConversacion(salaId: number, noLeidosAlAbrir: number) {
  const qc = useQueryClient()
  const [e, setE] = useState<EstadoConversacion>({ mensajes: [], cargando: true, error: null, hayMas: false, leidoHasta: 0, escribiendo: [], presencia: {}, sinLeerAlAbrir: noLeidosAlAbrir })
  const cursor = useRef('')
  const ultimo = useRef(0)
  const enCurso = useRef(false)
  const vivo = useRef(true)
  const sala = useRef(salaId)

  const aplicar = useCallback((llegan: Mensaje[]) => {
    if (!llegan.length) return
    setE((s) => {
      const mensajes = fusionarMensajes(s.mensajes, llegan)
      ultimo.current = Math.max(ultimo.current, ...llegan.map((m) => m.id))
      return { ...s, mensajes }
    })
  }, [])

  const sondear = useCallback(async () => {
    if (enCurso.current || !ultimo.current && !cursor.current) return
    enCurso.current = true
    const id = sala.current
    try {
      const n = await api(
        conQuery(`/api/v1/chat/salas/${id}/novedades`, { despues: ultimo.current, cursor: cursor.current, activo: estaActivo() ? 1 : 0, leer: document.visibilityState === 'visible' ? 1 : 0 }),
        { schema: NovedadesRespuesta },
      )
      if (!vivo.current || id !== sala.current) return
      cursor.current = n.cursor
      const llegan = [...n.cambios, ...n.mensajes]
      if (llegan.length) aplicar(llegan)
      setE((s) => ({ ...s, leidoHasta: n.leido_hasta, escribiendo: n.escribiendo, presencia: n.presencia }))
      // Los no leídos de las otras salas, sin pedir la lista entera.
      qc.setQueryData<SalasDatos>(clavesCom.salas, (d) =>
        d ? { ...d, salas: d.salas.map((x) => ({ ...x, no_leidos: x.id === id ? 0 : (n.no_leidos[String(x.id)] ?? 0) })), presencia: { ...d.presencia, ...n.presencia } } : d,
      )
      if (n.mensajes.length) {
        void qc.invalidateQueries({ queryKey: clavesCom.salas })
        void qc.invalidateQueries({ queryKey: clavesNav.nav })
      }
    } catch {
      // Un sondeo fallido no rompe la conversación: el siguiente lo reintenta.
    } finally {
      enCurso.current = false
    }
  }, [aplicar, qc])

  // Carga inicial al cambiar de sala.
  useEffect(() => {
    vivo.current = true
    sala.current = salaId
    cursor.current = ''
    ultimo.current = 0
    const ctl = new AbortController()
    api(`/api/v1/chat/salas/${salaId}/mensajes?limit=50`, { schema: HistorialRespuesta, signal: ctl.signal })
      .then((h) => {
        cursor.current = h.cursor
        ultimo.current = h.mensajes.length ? h.mensajes[h.mensajes.length - 1].id : 0
        // Sin mensajes también hay que poder sondear: el cursor ya marca el inicio.
        setE((s) => ({ ...s, mensajes: h.mensajes, cargando: false, hayMas: h.hay_mas, leidoHasta: h.leido_hasta }))
        qc.setQueryData<SalasDatos>(clavesCom.salas, (d) => (d ? { ...d, salas: d.salas.map((x) => (x.id === salaId ? { ...x, no_leidos: 0 } : x)) } : d))
        void qc.invalidateQueries({ queryKey: clavesNav.nav })
      })
      .catch((err: unknown) => {
        if ((err as { name?: string } | null)?.name === 'AbortError') return
        setE((s) => ({ ...s, cargando: false, error: err }))
      })
    return () => {
      vivo.current = false
      ctl.abort()
    }
  }, [salaId, qc])

  // Sondeo: cada 2,5 s con la pestaña visible; al volver a ella, en el acto.
  useEffect(() => {
    const t = window.setInterval(() => {
      if (document.visibilityState === 'visible') void sondear()
    }, CADA)
    const alVolver = () => document.visibilityState === 'visible' && void sondear()
    document.addEventListener('visibilitychange', alVolver)
    return () => {
      window.clearInterval(t)
      document.removeEventListener('visibilitychange', alVolver)
    }
  }, [sondear])

  /* Mensajes anteriores (al subir hasta arriba). */
  const cargarAnteriores = useCallback(async () => {
    const primero = e.mensajes[0]?.id
    if (!primero || !e.hayMas) return 0
    const h = await api(`/api/v1/chat/salas/${salaId}/mensajes?antes=${primero}&limit=50`, { schema: HistorialRespuesta })
    setE((s) => ({ ...s, mensajes: fusionarMensajes(s.mensajes, h.mensajes), hayMas: h.hay_mas }))
    return h.mensajes.length
  }, [e.mensajes, e.hayMas, salaId])

  return { ...e, aplicar, sondear, cargarAnteriores }
}
