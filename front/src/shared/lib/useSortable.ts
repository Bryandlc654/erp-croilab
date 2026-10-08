import { useCallback, useEffect, useRef, useState, type KeyboardEvent, type PointerEvent } from 'react'
import { huecoInsercion, indiceDestino, moverElemento, type Eje } from './ordenar'

type Id = string | number
const UMBRAL = 4

export type EstadoArrastre = { id: Id; desde: number; hueco: number | null }

/* Arrastrar para reordenar con eventos de puntero (ratón, dedo y lápiz) y sin
   dependencias. Cada elemento se registra con `itemRef(id)` y su asa con
   `handleProps(id)`; con el teclado, el asa mueve el elemento con las flechas.
   Al soltar llama a `onReorder` con los ids en el nuevo orden. */
export function useSortable({ ids, onReorder, axis = 'y', disabled = false }: { ids: Id[]; onReorder: (ids: Id[]) => void; axis?: Eje; disabled?: boolean }) {
  const elementos = useRef(new Map<Id, HTMLElement>())
  const [arrastre, setArrastre] = useState<EstadoArrastre | null>(null)
  const inicio = useRef<{ id: Id; x: number; y: number; activo: boolean } | null>(null)
  const ultimo = useRef<EstadoArrastre | null>(null)
  const datos = useRef({ ids, onReorder, axis })
  useEffect(() => {
    datos.current = { ids, onReorder, axis }
  })

  const itemRef = useCallback(
    (id: Id) => (el: HTMLElement | null) => {
      if (el) elementos.current.set(id, el)
      else elementos.current.delete(id)
    },
    [],
  )

  const terminar = useCallback((soltar: boolean) => {
    const a = ultimo.current
    inicio.current = null
    ultimo.current = null
    setArrastre(null)
    if (!soltar || !a || a.hueco === null) return
    const { ids: lista, onReorder: fn } = datos.current
    const hasta = indiceDestino(a.desde, a.hueco)
    if (hasta === a.desde) return
    fn(moverElemento(lista, a.desde, hasta))
    // El clic que sigue a soltar no debe abrir la fila.
    const tragar = (e: Event) => {
      e.stopPropagation()
      e.preventDefault()
    }
    window.addEventListener('click', tragar, { capture: true, once: true })
    setTimeout(() => window.removeEventListener('click', tragar, { capture: true }), 50)
  }, [])

  useEffect(() => {
    const mover = (e: globalThis.PointerEvent) => {
      const ini = inicio.current
      if (!ini) return
      if (!ini.activo) {
        if (Math.hypot(e.clientX - ini.x, e.clientY - ini.y) < UMBRAL) return
        ini.activo = true
      }
      e.preventDefault()
      const { ids: lista, axis: eje } = datos.current
      const cajas = lista.map((id) => elementos.current.get(id)?.getBoundingClientRect() ?? { top: 0, left: 0, width: 0, height: 0 })
      const estado = { id: ini.id, desde: lista.indexOf(ini.id), hueco: huecoInsercion(cajas, { x: e.clientX, y: e.clientY }, eje) }
      ultimo.current = estado
      setArrastre(estado)
    }
    const soltar = () => inicio.current && terminar(!!inicio.current.activo)
    const esc = (e: globalThis.KeyboardEvent) => e.key === 'Escape' && inicio.current?.activo && terminar(false)
    window.addEventListener('pointermove', mover, { passive: false })
    window.addEventListener('pointerup', soltar)
    window.addEventListener('pointercancel', soltar)
    window.addEventListener('keydown', esc)
    return () => {
      window.removeEventListener('pointermove', mover)
      window.removeEventListener('pointerup', soltar)
      window.removeEventListener('pointercancel', soltar)
      window.removeEventListener('keydown', esc)
    }
  }, [terminar])

  const handleProps = useCallback(
    (id: Id) => ({
      onPointerDown: (e: PointerEvent) => {
        if (disabled || e.button !== 0) return
        e.stopPropagation()
        inicio.current = { id, x: e.clientX, y: e.clientY, activo: false }
      },
      onClick: (e: { stopPropagation: () => void }) => e.stopPropagation(),
      onKeyDown: (e: KeyboardEvent) => {
        if (disabled) return
        const { ids: lista, axis: eje, onReorder: fn } = datos.current
        const atras = eje === 'y' ? 'ArrowUp' : 'ArrowLeft'
        const alante = eje === 'y' ? 'ArrowDown' : 'ArrowRight'
        if (e.key !== atras && e.key !== alante) return
        e.preventDefault()
        const i = lista.indexOf(id)
        const j = e.key === atras ? i - 1 : i + 1
        if (i < 0 || j < 0 || j >= lista.length) return
        fn(moverElemento(lista, i, j))
        // El asa sigue enfocada en su nueva posición.
        requestAnimationFrame(() => elementos.current.get(id)?.querySelector<HTMLElement>('[data-asa]')?.focus())
      },
      'data-asa': '',
      role: 'button',
      tabIndex: disabled ? -1 : 0,
      'aria-label': 'Reordenar (flechas para mover)',
      'aria-roledescription': 'asa de arrastre',
      style: { touchAction: 'none' as const, cursor: arrastre?.id === id ? 'grabbing' : 'grab' },
    }),
    [disabled, arrastre],
  )

  /* Marca de inserción de un elemento: 'antes', 'despues' o null. */
  const marca = useCallback(
    (id: Id): 'antes' | 'despues' | null => {
      if (!arrastre || arrastre.hueco === null) return null
      const i = ids.indexOf(id)
      const destino = indiceDestino(arrastre.desde, arrastre.hueco)
      if (destino === arrastre.desde) return null
      if (arrastre.hueco === i) return 'antes'
      if (arrastre.hueco === ids.length && i === ids.length - 1) return 'despues'
      return null
    },
    [arrastre, ids],
  )

  return { itemRef, handleProps, marca, arrastrando: arrastre?.id ?? null }
}
