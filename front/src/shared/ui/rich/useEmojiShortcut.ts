import { useEffect, useRef, type RefObject } from 'react'

/* Atajo Ctrl/⌘ + . sobre un campo (como erp_foot del antiguo): abre el selector
   de emojis. Solo escucha en ese campo, no en toda la página. */
export function useEmojiShortcut(ref: RefObject<HTMLElement | null>, abrir: () => void, activo = true) {
  const fn = useRef(abrir)
  useEffect(() => {
    fn.current = abrir
  })
  useEffect(() => {
    const el = ref.current
    if (!el || !activo) return
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === '.' || e.code === 'Period')) {
        e.preventDefault()
        fn.current()
      }
    }
    el.addEventListener('keydown', onKey)
    return () => el.removeEventListener('keydown', onKey)
  }, [ref, activo])
}

export function esAtajoEmoji(e: { ctrlKey: boolean; metaKey: boolean; altKey: boolean; key: string; code?: string }) {
  return (e.ctrlKey || e.metaKey) && !e.altKey && (e.key === '.' || e.code === 'Period')
}
