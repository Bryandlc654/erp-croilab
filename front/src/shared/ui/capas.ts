import { useEffect, useId, useLayoutEffect, useRef, type RefObject } from 'react'

/* Pila de capas flotantes (popovers, modales, diálogos, paleta). Solo la de
   arriba atiende a Esc y al clic fuera: así un Select dentro de un modal se
   cierra sin cerrar también el modal. */
const pila: string[] = []
let bloqueos = 0
let overflowPrevio = ''

export function esCapaSuperior(id: string) {
  return pila[pila.length - 1] === id
}

/* Registra la capa mientras `activa`. `onEscape` solo salta si es la de arriba. */
export function useCapa(activa: boolean, { onEscape, bloquearScroll = false }: { onEscape?: () => void; bloquearScroll?: boolean } = {}) {
  const id = useId()
  const esc = useRef(onEscape)
  useEffect(() => {
    esc.current = onEscape
  })

  // useLayoutEffect: la capa tiene que estar apilada antes de que cualquier
  // efecto hijo (p. ej. el foco inicial) pueda recibir un Esc.
  useLayoutEffect(() => {
    if (!activa) return
    pila.push(id)
    if (bloquearScroll) {
      if (bloqueos++ === 0) {
        overflowPrevio = document.body.style.overflow
        document.body.style.overflow = 'hidden'
      }
    }
    return () => {
      const i = pila.lastIndexOf(id)
      if (i >= 0) pila.splice(i, 1)
      if (bloquearScroll && --bloqueos === 0) document.body.style.overflow = overflowPrevio
    }
  }, [activa, id, bloquearScroll])

  useEffect(() => {
    if (!activa) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Escape' || !esCapaSuperior(id) || !esc.current) return
      e.preventDefault()
      e.stopPropagation()
      esc.current()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [activa, id])

  return id
}

const ENFOCABLES =
  'a[href], area[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"]), [contenteditable="true"]'

export function enfocables(raiz: HTMLElement) {
  return Array.from(raiz.querySelectorAll<HTMLElement>(ENFOCABLES)).filter((el) => !el.hasAttribute('inert') && el.getAttribute('aria-hidden') !== 'true')
}

/* Atrapa el Tab dentro de `ref` mientras está activo, pone el foco inicial y,
   al cerrar, lo devuelve a donde estaba (el botón que abrió el modal).
   Layout effect: el foco entra en el mismo commit en que aparece el diálogo
   (con un efecto normal hay un instante en que se ve sin foco y Enter/Esc
   irían a la página de debajo). */
export function useTrampaFoco(ref: RefObject<HTMLElement | null>, activa: boolean, inicial?: RefObject<HTMLElement | null>) {
  useLayoutEffect(() => {
    if (!activa) return
    const previo = document.activeElement as HTMLElement | null
    const raiz = ref.current
    if (!raiz) return
    const destino = inicial?.current ?? enfocables(raiz)[0] ?? raiz
    destino.focus({ preventScroll: true })
    if (destino instanceof HTMLInputElement || destino instanceof HTMLTextAreaElement) destino.select()

    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Tab' || !raiz.contains(document.activeElement)) return
      const lista = enfocables(raiz)
      if (lista.length === 0) {
        e.preventDefault()
        return
      }
      const primero = lista[0]
      const ultimo = lista[lista.length - 1]
      if (e.shiftKey && document.activeElement === primero) {
        e.preventDefault()
        ultimo.focus()
      } else if (!e.shiftKey && document.activeElement === ultimo) {
        e.preventDefault()
        primero.focus()
      }
    }
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('keydown', onKey)
      // Solo se devuelve si el foco seguía dentro (o se perdió en el body).
      const ahora = document.activeElement
      if (previo && previo.isConnected && (!ahora || ahora === document.body || raiz.contains(ahora))) previo.focus({ preventScroll: true })
    }
    // `inicial` es una ref: su .current se lee al abrir.
  }, [activa, ref, inicial])
}
