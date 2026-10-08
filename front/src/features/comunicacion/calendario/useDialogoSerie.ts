import { useCallback, useRef, useState } from 'react'

export type Eleccion = 'este' | 'serie'

/* «Este evento se repite. ¿A qué quieres aplicarlo?» como promesa:
     const eleccion = await serie.preguntar('Eliminar evento repetido')
   null si se cierra sin elegir. */
export function useDialogoSerie() {
  const [titulo, setTitulo] = useState<string | null>(null)
  const resolver = useRef<((v: Eleccion | null) => void) | null>(null)

  const preguntar = useCallback(
    (t: string) =>
      new Promise<Eleccion | null>((res) => {
        resolver.current?.(null)
        resolver.current = res
        setTitulo(t)
      }),
    [],
  )

  const responder = useCallback((v: Eleccion | null) => {
    resolver.current?.(v)
    resolver.current = null
    setTitulo(null)
  }, [])

  return { preguntar, dialogo: { titulo, responder } }
}
