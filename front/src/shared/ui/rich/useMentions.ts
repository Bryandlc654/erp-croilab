import { useEffect, useMemo, useRef, useState, type KeyboardEvent, type RefObject } from 'react'
import { consultaMencion, filtrarPersonas, insertarMencion, type PersonaMencion } from '../../lib/menciones'
import { ponerValor } from '../../lib/campos'

/* Menciones @ en un <textarea>/<input> normal (chat, notas del CRM). Uso:
     const m = useMentions({ ref, people })
     <textarea ref={ref} {...m.bind} onKeyDown={(e) => { if (m.onKeyDown(e)) return; …enviar… }} />
     <MentionPopover {...m.popover} anchor={ref} placement="top-start" />
   `onKeyDown` devuelve true si la tecla era para la lista (no enviar). */
export function useMentions<T extends PersonaMencion>({ ref, people, max = 6 }: { ref: RefObject<HTMLInputElement | HTMLTextAreaElement | null>; people: T[]; max?: number }) {
  const [consulta, setConsulta] = useState<string | null>(null)
  const [activo, setActivo] = useState(0)
  const blur = useRef<ReturnType<typeof setTimeout> | null>(null)
  const items = useMemo(() => (consulta === null ? [] : filtrarPersonas(people, consulta, max)), [people, consulta, max])
  const abierto = items.length > 0

  useEffect(() => () => {
    if (blur.current) clearTimeout(blur.current)
  }, [])

  function actualizar() {
    const el = ref.current
    if (!el) return
    const q = consultaMencion(el.value.slice(0, el.selectionStart ?? el.value.length))
    if (q === consulta) return
    setConsulta(q)
    setActivo(0)
  }

  function cerrar() {
    setConsulta(null)
  }

  function elegir(p: T) {
    const el = ref.current
    if (!el) return
    const r = insertarMencion(el.value, el.selectionStart ?? el.value.length, p.username)
    ponerValor(el, r.valor, r.cursor)
    setConsulta(null)
  }

  function onKeyDown(e: KeyboardEvent): boolean {
    if (!abierto) return false
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      const d = e.key === 'ArrowDown' ? 1 : -1
      setActivo((a) => (a + d + items.length) % items.length)
      return true
    }
    if (e.key === 'Enter' || e.key === 'Tab') {
      e.preventDefault()
      elegir(items[Math.min(activo, items.length - 1)])
      return true
    }
    if (e.key === 'Escape') {
      e.preventDefault()
      e.stopPropagation()
      cerrar()
      return true
    }
    return false
  }

  return {
    abierto,
    items,
    activo,
    elegir,
    cerrar,
    actualizar,
    onKeyDown,
    /* Para el campo: detecta la @ al escribir o mover el cursor y cierra al salir. */
    bind: {
      onSelect: actualizar,
      onBlur: () => {
        if (blur.current) clearTimeout(blur.current)
        blur.current = setTimeout(cerrar, 150)
      },
    },
    popover: { open: abierto, onClose: cerrar, items, active: activo, onActiveChange: setActivo, onPick: elegir },
  }
}
