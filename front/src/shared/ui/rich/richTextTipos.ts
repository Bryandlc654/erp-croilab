import type { ReactNode, Ref } from 'react'
import type { PersonaMencion } from '../../lib/menciones'

/* Tipos del editor, aparte del componente: así RichTextEditor (ligero) y el
   editor TipTap (cargado bajo demanda) comparten contrato sin importarse. */

export type HerramientaRT = 'blocks' | 'attach' | 'checklist' | 'emoji' | 'mention' | 'bold' | 'italic' | 'underline' | 'strike' | 'link' | 'code' | '|'

/* Barra del antiguo: Bloques «+», (Adjuntar), Lista de control, Emoji | B I U S Enlace Código. */
export const BARRA_DOC: HerramientaRT[] = ['blocks', 'attach', 'checklist', 'emoji', '|', 'bold', 'italic', 'underline', 'strike', 'link', 'code']
/* Barra del compositor de comentarios del antiguo. */
export const BARRA_COMENTARIO: HerramientaRT[] = ['attach', 'emoji', 'mention', 'checklist', 'blocks', '|', 'bold', 'italic', 'underline', 'strike', 'link', 'code']

/* Lo que devuelve la subida: el nombre guardado (va en [[img:FN]] / [[file:FN|nombre]]). */
export type ArchivoSubido = { fn: string; nombre: string; imagen: boolean }

export type RichTextEditorHandle = {
  focus: () => void
  /* Vacía el editor (tras enviar un comentario). */
  clear: () => void
  /* Valor actual en el formato del ERP (sin esperar al debounce). */
  getValue: () => string
  setValue: (v: string) => void
  insertText: (t: string) => void
  /* Manda ya el onChange pendiente del debounce. */
  flush: () => void
}

export type RichTextEditorProps = {
  /* Texto en el formato del ERP. */
  value: string
  /* Cambios (con debounce de `debounceMs`). */
  onChange?: (valor: string) => void
  /* Al salir del editor, con el valor final (descripción: guarda en blur). */
  onBlurSave?: (valor: string) => void
  debounceMs?: number
  placeholder?: string
  /* Personas para @mencionar (sin esta prop no hay menciones). */
  mentions?: PersonaMencion[]
  /* Herramientas y orden; `false` sin barra. Por defecto BARRA_DOC. */
  toolbar?: HerramientaRT[] | false
  /* Sube ficheros (pegar, soltar o «Adjuntar») e inserta [[img:…]]/[[file:…]].
     Sin ella no hay botón de adjuntar y se ignoran los ficheros pegados. */
  onUploadFiles?: (files: File[]) => Promise<ArchivoSubido[]>
  /* URL de un fichero ya subido, para pintar imágenes y enlaces dentro. */
  resolveFileUrl?: (fn: string) => string | null | undefined
  /* Intro (sin Mayús) envía: compositores. Fuera de listas, tablas y código. */
  onSubmit?: () => void
  /* 'doc': descripción/acta (línea inferior, alto 140+). 'compact': dentro de una caja (comentario). */
  variant?: 'doc' | 'compact'
  /* Al final de la barra (p. ej. el botón «Comentar»). */
  toolbarEnd?: ReactNode
  autoFocus?: boolean
  disabled?: boolean
  /* Alto mínimo del área de escritura en px. */
  minHeight?: number
  ariaLabel?: string
  className?: string
  ref?: Ref<RichTextEditorHandle>
}
