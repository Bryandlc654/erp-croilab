import { useEffect } from 'react'

/* Las variables de color del portal también hacen falta en lo que se pinta
   fuera de su árbol (modales y avisos van a <body>). */
export function useClasePortal() {
  useEffect(() => {
    document.body.classList.add('portal')
    return () => document.body.classList.remove('portal')
  }, [])
}

export const CAMPO =
  'h-12 w-full rounded-xl border border-(--p-line) bg-(--p-card) px-4 text-[15px] text-(--p-ink-strong) outline-none placeholder:text-(--p-muted) focus:border-(--p-ink-strong) max-sm:text-[16px]'
export const BOTON = 'flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-(--p-acc) text-[15px] font-bold text-(--p-acc-fg) hover:opacity-90 disabled:opacity-60'
