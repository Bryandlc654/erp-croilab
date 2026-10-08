import { useEffect, useRef, useState } from 'react'

function traeFicheros(e: DragEvent) {
  return !!e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files')
}

/* Arrastrar ficheros a CUALQUIER parte de la ventana (#dropOverlay del
   antiguo). Cuenta los dragenter/dragleave (cada hijo dispara los suyos) para
   saber cuándo el ratón sale de verdad. `onFiles` recibe también el elemento
   donde se soltó: así la ficha de tarea decide si adjunta al comentario (panel
   de actividad) o a la tarea. Si una zona propia (FileDropzone, el editor) se
   queda el drop, aquí solo se oculta la capa. */
export function useWindowFileDrop({ onFiles, enabled = true }: { onFiles: (files: File[], destino: Element | null) => void; enabled?: boolean }) {
  const [activo, setActivo] = useState(false)
  const contador = useRef(0)
  const fn = useRef(onFiles)
  useEffect(() => {
    fn.current = onFiles
  })

  useEffect(() => {
    if (!enabled) return
    const entra = (e: DragEvent) => {
      if (!traeFicheros(e)) return
      contador.current++
      setActivo(true)
    }
    const sale = (e: DragEvent) => {
      if (!traeFicheros(e)) return
      contador.current = Math.max(0, contador.current - 1)
      if (contador.current === 0) setActivo(false)
    }
    const encima = (e: DragEvent) => {
      if (!traeFicheros(e)) return
      // Sin esto el navegador abriría el fichero en vez de soltarlo aquí.
      e.preventDefault()
      if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy'
    }
    // En captura: se oculta la capa aunque una zona propia se quede el drop.
    const reiniciar = () => {
      contador.current = 0
      setActivo(false)
    }
    const suelta = (e: DragEvent) => {
      if (!traeFicheros(e)) return
      e.preventDefault()
      const files = Array.from(e.dataTransfer?.files ?? [])
      if (files.length) fn.current(files, e.target instanceof Element ? e.target : null)
    }
    window.addEventListener('dragenter', entra)
    window.addEventListener('dragleave', sale)
    window.addEventListener('dragover', encima)
    window.addEventListener('drop', reiniciar, true)
    window.addEventListener('drop', suelta)
    window.addEventListener('dragend', reiniciar)
    return () => {
      window.removeEventListener('dragenter', entra)
      window.removeEventListener('dragleave', sale)
      window.removeEventListener('dragover', encima)
      window.removeEventListener('drop', reiniciar, true)
      window.removeEventListener('drop', suelta)
      window.removeEventListener('dragend', reiniciar)
      contador.current = 0
    }
  }, [enabled])

  return { activo: enabled && activo }
}
