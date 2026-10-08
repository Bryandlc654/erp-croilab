import { useCallback, useEffect, useRef, useState } from 'react'

/* Apertura «con intención» (§4.6): algo flotante se abre si el ratón se queda
   quieto `abrirMs` encima; si ya había otro abierto el cambio es instantáneo;
   al salir hay `cerrarMs` de gracia para llegar al panel sin que se cierre.
   Raíl: 380/200 ms. Tarjeta de perfil: 450/180 ms. */
export function useIntencion<K = true>({ abrirMs, cerrarMs }: { abrirMs: number; cerrarMs: number }) {
  const [abierto, setAbierto] = useState<K | null>(null)
  const tAbrir = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  const tCerrar = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  const actual = useRef<K | null>(null)

  const fijar = useCallback((k: K | null) => {
    actual.current = k
    setAbierto(k)
  }, [])

  const limpiar = () => {
    clearTimeout(tAbrir.current)
    clearTimeout(tCerrar.current)
  }

  const entrar = useCallback(
    (k: K) => {
      clearTimeout(tCerrar.current)
      clearTimeout(tAbrir.current)
      if (actual.current !== null) fijar(k)
      else tAbrir.current = setTimeout(() => fijar(k), abrirMs)
    },
    [abrirMs, fijar],
  )

  const salir = useCallback(() => {
    clearTimeout(tAbrir.current)
    clearTimeout(tCerrar.current)
    tCerrar.current = setTimeout(() => fijar(null), cerrarMs)
  }, [cerrarMs, fijar])

  /* El ratón ha llegado al panel: no cerrar. */
  const mantener = useCallback(() => clearTimeout(tCerrar.current), [])

  const cerrar = useCallback(() => {
    limpiar()
    fijar(null)
  }, [fijar])

  const abrirYa = useCallback(
    (k: K) => {
      limpiar()
      fijar(k)
    },
    [fijar],
  )

  useEffect(() => limpiar, [])

  return { abierto, entrar, salir, mantener, cerrar, abrirYa }
}
