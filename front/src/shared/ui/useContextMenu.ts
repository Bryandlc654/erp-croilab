import { useCallback, useMemo, useState, type MouseEvent } from 'react'

/* Menú contextual (clic derecho) en la posición del cursor:
     const cm = useContextMenu()
     <tr onContextMenu={cm.onContextMenu}>…</tr>
     <MenuPanel {...cm.panel} label="Acciones">…</MenuPanel>
   El MenuPanel lo acota a la pantalla y lo voltea si no cabe. */
export function useContextMenu<T = undefined>() {
  const [estado, setEstado] = useState<{ x: number; y: number; dato: T | undefined } | null>(null)

  const abrirEn = useCallback((x: number, y: number, dato?: T) => setEstado({ x, y, dato }), [])
  const cerrar = useCallback(() => setEstado(null), [])
  const onContextMenu = useCallback(
    (e: MouseEvent, dato?: T) => {
      e.preventDefault()
      e.stopPropagation()
      abrirEn(e.clientX, e.clientY, dato)
    },
    [abrirEn],
  )

  // El punto se memoriza para que el Popover no se recoloque en cada render.
  const anchor = useMemo(() => (estado ? { x: estado.x, y: estado.y } : null), [estado])

  return {
    open: estado !== null,
    dato: estado?.dato,
    abrirEn,
    cerrar,
    onContextMenu,
    panel: { open: estado !== null, onClose: cerrar, anchor },
  }
}
