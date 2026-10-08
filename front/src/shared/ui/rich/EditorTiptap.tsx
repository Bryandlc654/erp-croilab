import { useCallback, useEffect, useImperativeHandle, useMemo, useRef, useState, type ReactNode } from 'react'
import { EditorContent, useEditor, type Editor } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import { Selection } from '@tiptap/pm/state'
import { TaskItem, TaskList } from '@tiptap/extension-list'
import { TableKit } from '@tiptap/extension-table'
import Mention from '@tiptap/extension-mention'
import { Placeholder } from '@tiptap/extensions'
import { AtSign, Code, Link as LinkIcon, ListPlus, Paperclip, Smile, SquareCheckBig } from 'lucide-react'
import { MenuItem, MenuPanel, MenuSeparator } from '../Menu'
import { useConfirm } from '../useConfirm'
import { useToast } from '../useToast'
import type { AnclaPopover } from '../Popover'
import EmojiPicker from './EmojiPicker'
import MentionPopover from './MentionPopover'
import { AtajosErp, ErpArchivo, ErpHuecoImagen, ErpImagen, FuenteErp, htmlMencion } from './extensionesTiptap'
import { docATiptap, tiptapADoc, type NodoTT } from './tiptapFormato'
import { BARRA_DOC, type HerramientaRT, type RichTextEditorProps } from './richTextTipos'
import { parse, serializar } from '../../lib/richtext'
import { filtrarPersonas, type PersonaMencion } from '../../lib/menciones'
import './rich.css'

/* Editor TipTap que lee y escribe el formato del ERP. Se carga bajo demanda
   desde RichTextEditor: no lo importes directamente. */

function aJson(valor: string) {
  return docATiptap(parse(valor))
}

/* Como rtSerialize: sin líneas en blanco al principio ni al final. */
function valorDe(editor: Editor) {
  return serializar(tiptapADoc(editor.getJSON() as NodoTT)).replace(/^\n+|\n+$/g, '')
}

const RE_URL = /^https?:\/\//i

function limpiarPegado(html: string) {
  // El esquema ya descarta estilos y etiquetas que no conoce; esto quita además
  // imágenes y elementos activos antes de que ProseMirror los vea.
  const doc = new DOMParser().parseFromString(html, 'text/html')
  doc.querySelectorAll('img,picture,svg,video,audio,iframe,object,embed,script,style,link,meta,form,input,button').forEach((el) => el.remove())
  return doc.body.innerHTML
}

type EstadoMencion = { items: PersonaMencion[]; activo: number; ancla: { x: number; y: number } | null; elegir: (p: PersonaMencion) => void }

const BLOQUES: ({ t: string; i: string; fn: (e: Editor) => void } | null)[] = [
  { t: 'Texto normal', i: '¶', fn: (e) => e.chain().focus().setParagraph().run() },
  { t: 'Título grande', i: 'H1', fn: (e) => e.chain().focus().toggleHeading({ level: 1 }).run() },
  { t: 'Título mediano', i: 'H2', fn: (e) => e.chain().focus().toggleHeading({ level: 2 }).run() },
  { t: 'Título pequeño', i: 'H3', fn: (e) => e.chain().focus().toggleHeading({ level: 3 }).run() },
  null,
  { t: 'Lista con viñetas', i: '•', fn: (e) => e.chain().focus().toggleBulletList().run() },
  { t: 'Lista numerada', i: '1.', fn: (e) => e.chain().focus().toggleOrderedList().run() },
  { t: 'Lista de control', i: '☑', fn: (e) => e.chain().focus().toggleTaskList().run() },
  null,
  { t: 'Cita', i: '❝', fn: (e) => e.chain().focus().toggleBlockquote().run() },
  { t: 'Bloque de código', i: '{}', fn: (e) => e.chain().focus().toggleCodeBlock().run() },
  {
    t: 'Tabla',
    i: '▦',
    // Como el antiguo: cabecera «Columna | Columna» y dos filas vacías.
    fn: (e) => {
      const celda = (tipo: string, texto = '') => ({ type: tipo, content: [{ type: 'paragraph', ...(texto ? { content: [{ type: 'text', text: texto }] } : {}) }] })
      const fila = (tipo: string, texto?: string) => ({ type: 'tableRow', content: [celda(tipo, texto), celda(tipo, texto)] })
      e.chain()
        .focus()
        .insertContent([{ type: 'table', content: [fila('tableHeader', 'Columna'), fila('tableCell'), fila('tableCell')] }, { type: 'paragraph' }])
        .run()
    },
  },
  { t: 'Divisor', i: '—', fn: (e) => e.chain().focus().setHorizontalRule().run() },
]

const TOOL =
  'inline-flex h-7 min-w-7 items-center justify-center rounded-[7px] p-1.5 text-[13px] leading-none text-label transition-colors hover:bg-soft hover:text-ink disabled:opacity-50 [&>svg]:size-4'

export default function EditorTiptap({
  value,
  onChange,
  onBlurSave,
  debounceMs = 700,
  placeholder = '',
  mentions,
  toolbar = BARRA_DOC,
  onUploadFiles,
  resolveFileUrl,
  onSubmit,
  variant = 'doc',
  toolbarEnd,
  autoFocus = false,
  disabled = false,
  minHeight,
  ariaLabel,
  className = '',
  ref,
}: RichTextEditorProps) {
  const { prompt } = useConfirm()
  const { aviso } = useToast()
  // Lo que cambia entre renders se lee siempre de aquí: las extensiones de
  // TipTap se crean una vez y guardan funciones.
  const p = useRef({ onChange, onBlurSave, onSubmit, onUploadFiles, resolveFileUrl, mentions, debounceMs })
  useEffect(() => {
    p.current = { onChange, onBlurSave, onSubmit, onUploadFiles, resolveFileUrl, mentions, debounceMs }
  })
  const ultimo = useRef(value)
  const temporizador = useRef<ReturnType<typeof setTimeout> | null>(null)
  const pendiente = useRef<string | null>(null)
  const mn = useRef<EstadoMencion | null>(null)
  const [mencion, setMencion] = useState<EstadoMencion | null>(null)
  const [emoji, setEmoji] = useState<AnclaPopover>(null)
  const [bloques, setBloques] = useState(false)
  const [subiendo, setSubiendo] = useState(0)
  const [tabla, setTabla] = useState<{ top: number; right: number } | null>(null)
  const caja = useRef<HTMLDivElement>(null)
  const tuvoFoco = useRef(false)
  const botonBloques = useRef<HTMLButtonElement>(null)
  const botonEmoji = useRef<HTMLButtonElement>(null)
  const fichero = useRef<HTMLInputElement>(null)
  const acciones = useRef<{ enlace: () => Promise<void> | void; emojiEnCursor: () => void; subir: (f: File[]) => Promise<void> | void }>({ enlace: () => {}, emojiEnCursor: () => {}, subir: () => {} })

  // Manda ya el onChange que esperaba al debounce (solo usa refs: estable).
  const volcar = useCallback(() => {
    if (temporizador.current) clearTimeout(temporizador.current)
    temporizador.current = null
    if (pendiente.current !== null) {
      const v = pendiente.current
      pendiente.current = null
      p.current.onChange?.(v)
    }
  }, [])

  /* Las extensiones guardan funciones que TipTap llama más tarde (al teclear,
     al pegar…), nunca durante el render: por eso pueden leer refs. */
  /* eslint-disable react-hooks/refs */
  const extensiones = useMemo(
    () => [
      StarterKit.configure({
        heading: { levels: [1, 2, 3] },
        link: {
          openOnClick: false,
          autolink: true,
          linkOnPaste: true,
          defaultProtocol: 'https',
          // El formato solo guarda enlaces http(s).
          isAllowedUri: (u, ctx) => RE_URL.test(u) && ctx.defaultValidate(u),
          HTMLAttributes: { target: '_blank', rel: 'noopener noreferrer' },
        },
      }),
      TaskList,
      TaskItem.configure({ nested: false }),
      TableKit.configure({ table: { resizable: false } }),
      Placeholder.configure({ placeholder: () => placeholder }),
      ErpImagen.configure({ resolver: (fn) => p.current.resolveFileUrl?.(fn) }),
      ErpArchivo.configure({ resolver: (fn) => p.current.resolveFileUrl?.(fn) }),
      ErpHuecoImagen,
      FuenteErp,
      AtajosErp.configure({
        enviar: () => {
          const ed = editorRef.current
          if (!p.current.onSubmit || !ed || mn.current) return false
          if (['codeBlock', 'bulletList', 'orderedList', 'taskList', 'table'].some((t) => ed.isActive(t))) return false
          p.current.onSubmit()
          return true
        },
        enlace: () => {
          acciones.current.enlace()
          return true
        },
        emoji: () => {
          acciones.current.emojiEnCursor()
          return true
        },
      }),
      ...(mentions
        ? [
            Mention.configure({
              renderText: ({ node }) => '@' + String(node.attrs.id ?? node.attrs.label ?? ''),
              renderHTML: ({ node }) => {
                const nombre = String(node.attrs.id ?? node.attrs.label ?? '')
                const persona = (p.current.mentions ?? []).find((x) => x.username.toLowerCase() === nombre.toLowerCase())
                return htmlMencion(nombre, persona?.foto)
              },
              suggestion: {
                char: '@',
                items: ({ query }) => filtrarPersonas(p.current.mentions ?? [], query, 8),
                render: () => {
                  const pintar = (props: { items: PersonaMencion[]; clientRect?: (() => DOMRect | null) | null; command: (a: { id: string; label: string }) => void }, activo: number) => {
                    const r = props.clientRect?.()
                    mn.current = props.items.length
                      ? { items: props.items, activo, ancla: r ? { x: r.left, y: r.bottom } : null, elegir: (x) => props.command({ id: x.username, label: x.username }) }
                      : null
                    setMencion(mn.current)
                  }
                  return {
                    onStart: (props) => pintar(props, 0),
                    onUpdate: (props) => pintar(props, 0),
                    onExit: () => {
                      mn.current = null
                      setMencion(null)
                    },
                    onKeyDown: ({ event }) => {
                      const m = mn.current
                      if (!m) return false
                      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        const d = event.key === 'ArrowDown' ? 1 : -1
                        mn.current = { ...m, activo: (m.activo + d + m.items.length) % m.items.length }
                        setMencion(mn.current)
                        return true
                      }
                      if (event.key === 'Enter' || event.key === 'Tab') {
                        m.elegir(m.items[m.activo])
                        return true
                      }
                      if (event.key === 'Escape') {
                        mn.current = null
                        setMencion(null)
                        return true
                      }
                      return false
                    },
                  }
                },
              },
            }),
          ]
        : []),
    ],
    // Las extensiones se crean una vez: lo que cambia se lee de `p`.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [],
  )
  /* eslint-enable react-hooks/refs */

  const editorRef = useRef<Editor | null>(null)
  const editor = useEditor({
    extensions: extensiones,
    content: aJson(value),
    editable: !disabled,
    autofocus: autoFocus ? 'end' : false,
    editorProps: {
      attributes: {
        class: `rt-content ${variant === 'doc' ? 'py-3.5 px-0.5' : ''}`,
        style: `min-height:${minHeight ?? (variant === 'doc' ? 140 : 40)}px`,
        role: 'textbox',
        'aria-multiline': 'true',
        ...(ariaLabel ? { 'aria-label': ariaLabel } : {}),
      },
      transformPastedHTML: limpiarPegado,
      handlePaste: (_view, event) => {
        const files = Array.from(event.clipboardData?.files ?? [])
        if (!files.length || !p.current.onUploadFiles) return false
        event.preventDefault()
        acciones.current.subir(files)
        return true
      },
      handleDrop: (view, event, _slice, moved) => {
        const files = Array.from(event.dataTransfer?.files ?? [])
        if (moved || !files.length || !p.current.onUploadFiles) return false
        event.preventDefault()
        // Que no lo recoja también la zona de soltar de la ventana.
        event.stopPropagation()
        const pos = view.posAtCoords({ left: event.clientX, top: event.clientY })
        if (pos) editorRef.current?.commands.setTextSelection(pos.pos)
        acciones.current.subir(files)
        return true
      },
    },
    onUpdate: ({ editor: ed }) => {
      const v = valorDe(ed)
      if (v === ultimo.current) return
      ultimo.current = v
      pendiente.current = v
      if (temporizador.current) clearTimeout(temporizador.current)
      if (p.current.debounceMs <= 0) volcar()
      else temporizador.current = setTimeout(volcar, p.current.debounceMs)
    },
    onFocus: () => {
      tuvoFoco.current = true
    },
    onBlur: ({ editor: ed }) => {
      volcar()
      p.current.onBlurSave?.(valorDe(ed))
    },
  })

  useEffect(() => {
    editorRef.current = editor
  }, [editor])

  // Valor nuevo desde fuera (otra tarea, recarga): se repinta sin avisar de cambio.
  useEffect(() => {
    if (!editor || value === ultimo.current) return
    ultimo.current = value
    pendiente.current = null
    editor.commands.setContent(aJson(value), { emitUpdate: false })
  }, [editor, value])

  useEffect(() => {
    editor?.setEditable(!disabled)
  }, [editor, disabled])

  // Al desmontar no se pierde lo último escrito.
  useEffect(() => () => volcar(), [volcar])

  // Barra flotante de la tabla (+col, +fila, −col, −fila) sobre la tabla del cursor.
  useEffect(() => {
    if (!editor) return
    const actualizar = () => {
      const c = caja.current
      if (!c || !editor.isActive('table') || !editor.isEditable) {
        setTabla(null)
        return
      }
      const dom = editor.view.domAtPos(editor.state.selection.from).node
      const el = (dom instanceof Element ? dom : dom.parentElement)?.closest('table')
      if (!el) {
        setTabla(null)
        return
      }
      const r = el.getBoundingClientRect()
      const rc = c.getBoundingClientRect()
      setTabla({ top: Math.max(0, r.top - rc.top - 32), right: Math.max(0, rc.right - Math.min(r.right, rc.right)) })
    }
    editor.on('selectionUpdate', actualizar)
    editor.on('update', actualizar)
    editor.on('blur', actualizar)
    return () => {
      editor.off('selectionUpdate', actualizar)
      editor.off('update', actualizar)
      editor.off('blur', actualizar)
    }
  }, [editor])

  // Acciones que usan los atajos de las extensiones (creadas una sola vez).
  useEffect(() => {
  acciones.current.enlace = async () => {
    if (!editor) return
    const { from, to, empty } = editor.state.selection
    const seleccion = editor.state.doc.textBetween(from, to, ' ')
    const actual = editor.getAttributes('link').href as string | undefined
    const u = await prompt({ title: 'Pega o escribe el enlace (URL):', value: actual ?? (RE_URL.test(seleccion) ? seleccion : 'https://'), okLabel: 'Aceptar' })
    if (!u) return
    const href = RE_URL.test(u) ? u.trim() : 'https://' + u.trim().replace(/^\/+/, '')
    if (empty && !actual) {
      editor
        .chain()
        .focus()
        .insertContent([{ type: 'text', text: href, marks: [{ type: 'link', attrs: { href } }] }, { type: 'text', text: ' ' }])
        .run()
    } else editor.chain().focus().extendMarkRange('link').setLink({ href }).run()
  }

  acciones.current.emojiEnCursor = () => {
    if (!editor) return
    const c = editor.view.coordsAtPos(editor.state.selection.from)
    setEmoji({ x: c.left, y: c.bottom })
  }

  acciones.current.subir = async (files: File[]) => {
    const subir = p.current.onUploadFiles
    if (!subir || !editor) return
    setSubiendo((n) => n + 1)
    try {
      const hechos = await subir(files)
      const nodos = hechos.flatMap((a) => [a.imagen ? { type: 'erpImagen', attrs: { fn: a.fn } } : { type: 'erpArchivo', attrs: { fn: a.fn, orig: a.nombre } }, { type: 'text', text: ' ' }])
      if (nodos.length) editor.chain().focus().insertContent(nodos).run()
    } catch {
      aviso('No se ha podido subir el archivo.', { tipo: 'error' })
    } finally {
      setSubiendo((n) => n - 1)
    }
  }
  })

  useImperativeHandle(
    ref,
    () => ({
      focus: () => editor?.commands.focus('end'),
      clear: () => {
        if (temporizador.current) clearTimeout(temporizador.current)
        pendiente.current = null
        ultimo.current = ''
        editor?.commands.setContent(aJson(''), { emitUpdate: false })
      },
      getValue: () => (editor ? valorDe(editor) : ultimo.current),
      setValue: (v: string) => {
        ultimo.current = v
        editor?.commands.setContent(aJson(v), { emitUpdate: false })
      },
      insertText: (t: string) => {
        editor?.chain().focus().insertContent(t).run()
      },
      flush: volcar,
    }),
    [editor, volcar],
  )

  // Sin quitar el foco al selector (elegir no cierra). Si aún no se ha escrito
  // nada (cursor fuera de un párrafo), el emoji va al final del texto.
  function insertarEmoji(e: string) {
    if (!editor) return
    const ch = editor.chain()
    if (!tuvoFoco.current || !editor.state.selection.$from.parent.isTextblock) ch.setTextSelection(Selection.atEnd(editor.state.doc).from)
    ch.insertContent(e).run()
  }

  const herramientas = toolbar === false || disabled ? [] : toolbar.filter((h) => (h === 'attach' ? !!onUploadFiles : h === 'mention' ? !!mentions : true))
  const sinFoco = (e: { preventDefault: () => void }) => e.preventDefault()

  function boton(h: HerramientaRT, k: number): ReactNode {
    if (!editor) return null
    const props = { type: 'button' as const, onMouseDown: sinFoco, className: TOOL }
    switch (h) {
      case '|':
        return <span key={k} className="mx-[5px] h-[18px] w-px bg-line" aria-hidden="true" />
      case 'blocks':
        return (
          <button key={k} {...props} ref={botonBloques} title="Bloques: títulos, listas, cita, tabla…" aria-label="Bloques" aria-expanded={bloques} onClick={() => setBloques((v) => !v)}>
            <ListPlus />
          </button>
        )
      case 'attach':
        return (
          <button key={k} {...props} title="Subir imagen o archivo" aria-label="Adjuntar" onClick={() => fichero.current?.click()} disabled={subiendo > 0}>
            <Paperclip />
          </button>
        )
      case 'checklist':
        return (
          <button key={k} {...props} title="Lista de control" aria-label="Lista de control" onClick={() => editor.chain().focus().toggleTaskList().run()}>
            <SquareCheckBig />
          </button>
        )
      case 'emoji':
        return (
          <button key={k} {...props} ref={botonEmoji} title="Emoji (Ctrl+.)" aria-label="Emoji" onClick={() => setEmoji((v) => (v ? null : botonEmoji))}>
            <Smile />
          </button>
        )
      case 'mention':
        return (
          <button
            key={k}
            {...props}
            title="Mencionar"
            aria-label="Mencionar"
            onClick={() => {
              const { from } = editor.state.selection
              const antes = from > 1 ? editor.state.doc.textBetween(from - 1, from) : ''
              editor
                .chain()
                .focus()
                .insertContent(antes && !/\s/.test(antes) ? ' @' : '@')
                .run()
            }}
          >
            <AtSign />
          </button>
        )
      case 'bold':
        return (
          <button key={k} {...props} title="Negrita (Ctrl+B)" aria-label="Negrita" className={`${TOOL} font-extrabold`} onClick={() => editor.chain().focus().toggleBold().run()}>
            B
          </button>
        )
      case 'italic':
        return (
          <button key={k} {...props} title="Cursiva (Ctrl+I)" aria-label="Cursiva" className={`${TOOL} font-bold italic`} onClick={() => editor.chain().focus().toggleItalic().run()}>
            I
          </button>
        )
      case 'underline':
        return (
          <button key={k} {...props} title="Subrayado (Ctrl+U)" aria-label="Subrayado" className={`${TOOL} font-bold underline`} onClick={() => editor.chain().focus().toggleUnderline().run()}>
            U
          </button>
        )
      case 'strike':
        return (
          <button key={k} {...props} title="Tachado" aria-label="Tachado" className={`${TOOL} font-bold line-through`} onClick={() => editor.chain().focus().toggleStrike().run()}>
            S
          </button>
        )
      case 'link':
        return (
          <button key={k} {...props} title="Enlace (Ctrl+K)" aria-label="Enlace" onClick={() => void acciones.current.enlace()}>
            <LinkIcon />
          </button>
        )
      case 'code':
        return (
          <button
            key={k}
            {...props}
            title="Código / comando"
            aria-label="Código"
            onClick={() => {
              if (editor.state.selection.empty && !editor.isActive('code'))
                editor
                  .chain()
                  .focus()
                  .insertContent([{ type: 'text', text: 'código', marks: [{ type: 'code' }] }, { type: 'text', text: ' ' }])
                  .run()
              else editor.chain().focus().toggleCode().run()
            }}
          >
            <Code />
          </button>
        )
    }
  }

  const barra =
    herramientas.length > 0 || toolbarEnd ? (
      <div className={`flex flex-wrap items-center gap-0.5 ${variant === 'doc' ? 'pt-1 pb-2' : 'mt-1.5'}`} role="toolbar" aria-label="Formato">
        {herramientas.map(boton)}
        {subiendo > 0 && <span className="ml-1.5 text-[12px] text-muted">Subiendo…</span>}
        {toolbarEnd && <div className="ml-auto flex items-center gap-2">{toolbarEnd}</div>}
      </div>
    ) : null

  return (
    <div
      ref={caja}
      className={`relative ${variant === 'doc' ? 'border-b border-line transition-colors duration-150 focus-within:border-accent' : ''} ${className}`}
    >
      <EditorContent editor={editor} className={`overflow-y-auto overscroll-contain ${variant === 'doc' ? 'max-h-[min(640px,70vh)]' : 'max-h-[340px]'} [&_.ProseMirror]:outline-none`} />
      {barra}
      {onUploadFiles && (
        <input
          ref={fichero}
          type="file"
          multiple
          hidden
          onChange={(e) => {
            const files = Array.from(e.target.files ?? [])
            e.target.value = ''
            if (files.length) void acciones.current.subir(files)
          }}
        />
      )}
      {tabla && editor && (
        <div
          className="absolute z-10 flex gap-[3px] rounded-[9px] border border-line bg-pop p-[3px] shadow-[0_10px_30px_rgba(16,19,24,.16)]"
          style={{ top: tabla.top, right: tabla.right }}
          onMouseDown={sinFoco}
        >
          {(
            [
              ['＋col', 'Añadir columna a la derecha', () => editor.chain().focus().addColumnAfter().run(), false],
              ['＋fila', 'Añadir fila debajo', () => editor.chain().focus().addRowAfter().run(), false],
              ['－col', 'Borrar esta columna', () => editor.chain().focus().deleteColumn().run(), true],
              ['－fila', 'Borrar esta fila', () => editor.chain().focus().deleteRow().run(), true],
            ] as const
          ).map(([t, titulo, fn, peligro]) => (
            <button
              key={t}
              type="button"
              title={titulo}
              aria-label={titulo}
              onClick={fn}
              className={`rounded-md bg-soft px-2 py-1 text-[11px] font-semibold whitespace-nowrap text-ink transition-colors ${peligro ? 'hover:bg-[#feecec] hover:text-[#c0343a] dark:hover:bg-danger-bg dark:hover:text-danger' : 'hover:bg-accent-soft'}`}
            >
              {t}
            </button>
          ))}
        </div>
      )}
      {editor && (
        <MenuPanel open={bloques} onClose={() => setBloques(false)} anchor={botonBloques} minWidth={212} label="Bloques">
          {BLOQUES.map((b, k) =>
            b ? (
              <MenuItem
                key={k}
                onSelect={() => b.fn(editor)}
                icon={<span className="flex h-[22px] w-[26px] items-center justify-center rounded-md bg-soft text-[11px] font-bold text-muted">{b.i}</span>}
              >
                {b.t}
              </MenuItem>
            ) : (
              <MenuSeparator key={k} />
            ),
          )}
        </MenuPanel>
      )}
      <EmojiPicker open={!!emoji} onClose={() => setEmoji(null)} anchor={emoji} onPick={insertarEmoji} />
      {mencion?.ancla && (
        <MentionPopover
          open
          onClose={() => {
            mn.current = null
            setMencion(null)
          }}
          anchor={mencion.ancla}
          items={mencion.items}
          active={mencion.activo}
          onActiveChange={(i) => {
            if (!mn.current) return
            mn.current = { ...mn.current, activo: i }
            setMencion(mn.current)
          }}
          onPick={(x) => mencion.elegir(x)}
        />
      )}
    </div>
  )
}
