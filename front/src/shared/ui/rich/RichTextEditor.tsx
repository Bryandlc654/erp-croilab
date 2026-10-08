import { lazy, Suspense } from 'react'
import RichTextView from './RichTextView'
import type { RichTextEditorProps } from './richTextTipos'

// TipTap pesa: va en su propio trozo y solo se descarga cuando aparece un editor.
const EditorTiptap = lazy(() => import('./EditorTiptap'))

/* Editor de texto enriquecido con el formato del ERP (descripciones,
   comentarios, actas). Mientras carga se ve el texto tal cual, sin saltos. */
export default function RichTextEditor(props: RichTextEditorProps) {
  const { variant = 'doc', value, minHeight, className = '' } = props
  return (
    <Suspense
      fallback={
        <div className={`${variant === 'doc' ? 'border-b border-line' : ''} ${className}`} aria-busy="true">
          <div className={variant === 'doc' ? 'px-0.5 py-3.5' : ''} style={{ minHeight: minHeight ?? (variant === 'doc' ? 140 : 40) }}>
            {value ? <RichTextView value={value} /> : <span className="text-[14px] text-label">{props.placeholder}</span>}
          </div>
          {props.toolbar !== false && <div className={variant === 'doc' ? 'h-[39px]' : 'mt-1.5 h-7'} />}
        </div>
      }
    >
      <EditorTiptap {...props} />
    </Suspense>
  )
}
