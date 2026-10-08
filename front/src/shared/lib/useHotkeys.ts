import { useEffect, useRef } from 'react'

/* Atajos de teclado globales (§4.11): 'mod+k' (Ctrl o ⌘), '/', 'n', 'escape',
   'shift+arrowleft'… Las teclas sueltas no saltan mientras se escribe en un
   campo; las combinaciones con Ctrl/⌘/Alt sí (Ctrl+K abre la paleta desde
   cualquier sitio, como en el ERP). */

export type MapaAtajos = Record<string, (e: KeyboardEvent) => void>

const ALIAS: Record<string, string> = { esc: 'escape', space: ' ', espacio: ' ', up: 'arrowup', down: 'arrowdown', left: 'arrowleft', right: 'arrowright' }

/* ¿El foco está en algo donde se escribe? */
export function esCampoEditable(el: EventTarget | null): boolean {
  if (!(el instanceof HTMLElement)) return false
  if (el.isContentEditable) return true
  const tag = el.tagName
  if (tag === 'TEXTAREA' || tag === 'SELECT') return true
  if (tag === 'INPUT') {
    const tipo = (el as HTMLInputElement).type
    return !['checkbox', 'radio', 'button', 'submit', 'reset', 'range', 'color', 'file'].includes(tipo)
  }
  return false
}

/* ¿La pulsación coincide con el atajo? 'mod' = Ctrl en Windows/Linux o ⌘ en Mac
   (se aceptan los dos para no depender de detectar el sistema). */
export function coincideAtajo(combo: string, e: Pick<KeyboardEvent, 'key' | 'ctrlKey' | 'metaKey' | 'altKey' | 'shiftKey'>): boolean {
  const partes = combo.toLowerCase().split('+').map((p) => p.trim())
  let tecla = partes.pop() ?? ''
  if (tecla === '' && combo.endsWith('+')) tecla = '+'
  tecla = ALIAS[tecla] ?? tecla
  const quiere = new Set(partes)
  const mod = quiere.has('mod')
  const ctrl = quiere.has('ctrl')
  const meta = quiere.has('meta') || quiere.has('cmd')
  if (mod) {
    if (!(e.ctrlKey || e.metaKey)) return false
  } else {
    if (ctrl !== e.ctrlKey || meta !== e.metaKey) return false
  }
  if (quiere.has('alt') !== e.altKey) return false
  // Shift solo se exige si se pide: '/' y '?' dependen de la distribución del teclado.
  if (quiere.has('shift') && !e.shiftKey) return false
  return (e.key ?? '').toLowerCase() === tecla
}

function tieneModificador(combo: string) {
  return /(^|\+)(mod|ctrl|meta|cmd|alt)\+/i.test(combo)
}

type Opciones = { enabled?: boolean; ignoreInputs?: boolean }

export function useHotkeys(mapa: MapaAtajos, { enabled = true, ignoreInputs = true }: Opciones = {}) {
  // El mapa suele ser un objeto nuevo en cada render: se lee de una ref para
  // no reinstalar el escuchador cada vez.
  const ref = useRef(mapa)
  useEffect(() => {
    ref.current = mapa
  })

  useEffect(() => {
    if (!enabled) return
    const onKey = (e: KeyboardEvent) => {
      if (e.defaultPrevented || e.isComposing) return
      for (const [combo, fn] of Object.entries(ref.current)) {
        if (!coincideAtajo(combo, e)) continue
        if (ignoreInputs && !tieneModificador(combo) && esCampoEditable(e.target)) continue
        fn(e)
        return
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [enabled, ignoreInputs])
}
