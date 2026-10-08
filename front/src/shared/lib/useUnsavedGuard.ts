import { useEffect, useState } from 'react'

/* Avisa al cerrar o recargar la pestaña si hay cambios sin guardar
   (beforeunload, como los formularios de ajustes del ERP). Con BrowserRouter
   no se pueden bloquear los cambios de ruta internos: para eso haría falta un
   router de datos (useBlocker). */
export function useUnsavedGuard(dirty: boolean) {
  useEffect(() => {
    if (!dirty) return
    const onUnload = (e: BeforeUnloadEvent) => {
      e.preventDefault()
      // Algunos navegadores aún piden returnValue para enseñar el aviso.
      e.returnValue = ''
    }
    window.addEventListener('beforeunload', onUnload)
    return () => window.removeEventListener('beforeunload', onUnload)
  }, [dirty])
}

/* true durante `ms` cada vez que cambia `marca` (p. ej. la hora del último
   guardado): para el «Guardado ✓» que se va solo. */
export function useRecienGuardado(marca: unknown, ms = 1300) {
  const [visible, setVisible] = useState<unknown>(null)
  useEffect(() => {
    if (marca === null || marca === undefined || marca === false) return
    const on = setTimeout(() => setVisible(marca), 0)
    const off = setTimeout(() => setVisible(null), ms)
    return () => {
      clearTimeout(on)
      clearTimeout(off)
    }
  }, [marca, ms])
  return visible !== null && visible === marca
}
