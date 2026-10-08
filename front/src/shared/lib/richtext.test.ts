import { describe, expect, it } from 'vitest'
import { alternarCheck, esUrlSegura, estaVacio, extracto, menciones, parse, parseInline, serializar, serializarInline, type DocRT } from './richtext'
import { docATiptap, tiptapADoc, type NodoTT } from '../ui/rich/tiptapFormato'

/* Textos como los que guardaba el ERP antiguo (rtSerialize / descSerialize /
   cmSerialize): bloques separados por una línea en blanco, listas «- » y
   «1. », citas «> », tablas «| a | b |» + «| --- | --- |», [[chk:0/1]]. */
const DESCRIPCION = [
  '# Objetivo',
  '',
  'Lanzar la **web nueva** antes del *viernes*.',
  '',
  '- Revisar __textos__',
  '- Subir ~~fotos~~ imágenes',
  '- Hablar con @laura',
  '',
  '1. Diseño',
  '2. Desarrollo',
  '',
  '> Ojo: el cliente quiere `staging` primero.',
  '> Segunda línea de la cita',
  '',
  '```',
  'npm run build',
  '  && deploy',
  '```',
  '',
  '---',
  '',
  '| Tarea | Responsable |',
  '| --- | --- |',
  '| Copy | @marta |',
  '| Fotos | **víctor** |',
  '',
  '[[chk:0]] Llamar al cliente',
  '',
  '[[chk:1]] Enviar presupuesto',
  'Mira https://croilab.com y [la guía](https://docs.croilab.com/guia).',
  '[[img:1700000000_ab12.png]]',
  '[[file:1700000000_cd34.pdf|Contrato firmado.pdf]]',
  '## Notas 🎉',
  '### Pequeño',
].join('\n')

const COMENTARIO = 'Hecho 👍 @laura revisa\n\n[[img]]\n\n**ojo** con esto ~~y esto~~'

const ACTA = [
  '# Reunión semanal',
  '',
  'Asistentes: @laura, @marta y @víctor.',
  '',
  '## Acuerdos',
  '',
  '[[chk:1]] Publicar el **blog**',
  '[[chk:0]] Revisar *SEO* técnico',
  '',
  '> Próxima reunión el lunes',
].join('\n')

/* Variantes que el antiguo también leía (texto escrito a mano). */
const VARIANTES = [
  '• uno\n• dos',
  '1) uno\n2) dos\n7. siete',
  '#   Título con espacios  ',
  '>sin espacio\n> con espacio\n>',
  '```js\nconst a = 1\n',
  '-----',
  '  ---  ',
  '|a|b|\n|-|:-:|\n|1|2|',
  '| a | b |\n| --- | --- |\n| solo una |',
  '  [[chk:1]]pegado\n[[chk:0]]',
  'texto con espacios al final   \n   \n\tsangría',
  '#### cuatro almohadillas es párrafo',
  '-sin espacio no es lista',
  '* asterisco no es lista',
  '**sin cerrar y *a medias',
  'a*b*c 2 * 3 * 4',
]

const idaYVuelta = (s: string) => serializar(parse(s))
const viaTiptap = (s: string) => serializar(tiptapADoc(docATiptap(parse(s))))
/* Sin la memoria de líneas de origen: lo que sale tras editar en el editor. */
const sinFuente = (doc: DocRT): DocRT =>
  doc.map((b) => {
    const c = { ...b }
    delete c.fuente
    return c
  })
const canonico = (s: string) => serializar(sinFuente(tiptapADoc(docATiptap(parse(s)))))

describe('richtext: ida y vuelta exacta', () => {
  it.each([
    ['descripción', DESCRIPCION],
    ['comentario', COMENTARIO],
    ['acta', ACTA],
    ...VARIANTES.map((v, k) => [`variante ${k}`, v] as [string, string]),
  ])('%s: texto → árbol → texto', (_n, s) => {
    expect(idaYVuelta(s)).toBe(s)
  })

  it.each([
    ['descripción', DESCRIPCION],
    ['comentario', COMENTARIO],
    ['acta', ACTA],
    ...VARIANTES.map((v, k) => [`variante ${k}`, v] as [string, string]),
    // (salvo la tabla con filas cortas: ProseMirror la necesita rectangular)
  ].filter(([n]) => n !== 'variante 8'))('%s: texto → TipTap → texto (sin editar, igual)', (_n, s) => {
    expect(viaTiptap(s)).toBe(s)
  })

  it('una tabla con filas cortas se completa al pasar por el editor', () => {
    expect(canonico('| a | b |\n| --- | --- |\n| solo una |')).toBe('| a | b |\n| --- | --- |\n| solo una |  |')
  })

  it('lo que produce el antiguo ya está en forma canónica', () => {
    expect(canonico(DESCRIPCION)).toBe(DESCRIPCION)
    expect(canonico(ACTA)).toBe(ACTA)
    expect(canonico(COMENTARIO)).toBe(COMENTARIO)
  })

  it('las variantes escritas a mano se normalizan al editarlas', () => {
    expect(canonico('• uno\n• dos')).toBe('- uno\n- dos')
    expect(canonico('1) uno\n2) dos\n7. siete')).toBe('1. uno\n2. dos\n3. siete')
    expect(canonico('3. tres\n4. cuatro')).toBe('3. tres\n4. cuatro')
    expect(canonico('>sin espacio\n>')).toBe('> sin espacio\n>')
    // Bloque de código sin cerrar: se cierra (la línea en blanco final es suya).
    expect(canonico('```js\nconst a = 1\n')).toBe('```js\nconst a = 1\n\n```')
    expect(canonico('-----')).toBe('---')
    expect(canonico('|a|b|\n|-|:-:|\n|1|2|')).toBe('| a | b |\n| --- | --- |\n| 1 | 2 |')
    expect(canonico('[[chk:0]]')).toBe('[[chk:0]]')
  })

  it('normaliza \\r\\n y el texto vacío', () => {
    expect(serializar(parse('a\r\nb'))).toBe('a\nb')
    expect(parse('')).toEqual([])
    expect(serializar([])).toBe('')
    expect(viaTiptap('')).toBe('')
  })
})

describe('richtext: bloques', () => {
  it('reconoce cada bloque como rt_blocks', () => {
    const doc = parse(DESCRIPCION)
    const tipos = doc.map((b) => b.t)
    expect(tipos).toEqual(['h', 'p', 'p', 'p', 'ul', 'p', 'ol', 'p', 'quote', 'p', 'code', 'p', 'hr', 'p', 'table', 'p', 'tareas', 'p', 'tareas', 'p', 'p', 'p', 'h', 'h'])
    const tabla = doc[14]
    expect(tabla.t === 'table' && tabla.filas.length).toBe(3)
    const code = doc[10]
    expect(code.t === 'code' && code.lineas).toEqual(['npm run build', '  && deploy'])
    const chk = doc[18]
    expect(chk.t === 'tareas' && chk.items[0].hecho).toBe(true)
  })

  it('agrupa líneas seguidas de lista, cita y lista de control', () => {
    const doc = parse(ACTA)
    const tareas = doc.find((b) => b.t === 'tareas')
    expect(tareas?.t === 'tareas' && tareas.items.map((i) => i.hecho)).toEqual([true, false])
    expect(parse('- a\n- b\n\n- c').map((b) => b.t)).toEqual(['ul', 'p', 'ul'])
  })

  it('una tabla necesita la fila separadora', () => {
    expect(parse('| a | b |\n| c | d |').map((b) => b.t)).toEqual(['p', 'p'])
  })
})

describe('richtext: en línea', () => {
  const tipos = (s: string) => parseInline(s).map((n) => n.t)

  it('formato básico', () => {
    expect(parseInline('**b** *i* __u__ ~~s~~ `c`')).toEqual([
      { t: 'b', c: [{ t: 'text', v: 'b' }] },
      { t: 'text', v: ' ' },
      { t: 'i', c: [{ t: 'text', v: 'i' }] },
      { t: 'text', v: ' ' },
      { t: 'u', c: [{ t: 'text', v: 'u' }] },
      { t: 'text', v: ' ' },
      { t: 's', c: [{ t: 'text', v: 's' }] },
      { t: 'text', v: ' ' },
      { t: 'code', v: 'c' },
    ])
  })

  it('negrita y cursiva combinadas', () => {
    expect(parseInline('***x***')).toEqual([{ t: 'b', c: [{ t: 'i', c: [{ t: 'text', v: 'x' }] }] }])
    expect(parseInline('**a *b***')).toEqual([{ t: 'b', c: [{ t: 'text', v: 'a ' }, { t: 'i', c: [{ t: 'text', v: 'b' }] }] }])
    expect(parseInline('*a **b***')).toEqual([{ t: 'i', c: [{ t: 'text', v: 'a ' }, { t: 'b', c: [{ t: 'text', v: 'b' }] }] }])
    expect(parseInline('***b** a*')).toEqual([{ t: 'i', c: [{ t: 'b', c: [{ t: 'text', v: 'b' }] }, { t: 'text', v: ' a' }] }])
  })

  it('el código y los enlaces protegen su contenido', () => {
    expect(parseInline('`**no**`')).toEqual([{ t: 'code', v: '**no**' }])
    expect(parseInline('**a `x*y` b**')[0].t).toBe('b')
    expect(parseInline('[a *b*](https://x.com/a_b_c)')).toEqual([{ t: 'link', label: 'a *b*', href: 'https://x.com/a_b_c' }])
  })

  it('URLs sueltas sin la puntuación final', () => {
    expect(parseInline('ver https://croilab.com/a.')).toEqual([{ t: 'text', v: 'ver ' }, { t: 'url', href: 'https://croilab.com/a' }, { t: 'text', v: '.' }])
    expect(parseInline('(https://x.com/a)')).toEqual([{ t: 'text', v: '(' }, { t: 'url', href: 'https://x.com/a' }, { t: 'text', v: ')' }])
    expect(tipos('javascript:alert(1)')).toEqual(['text'])
    expect(tipos('[x](javascript:alert(1))')).toEqual(['text'])
  })

  it('menciones a principio de palabra', () => {
    expect(parseInline('hola @laura.')).toEqual([{ t: 'text', v: 'hola ' }, { t: 'mention', name: 'laura' }, { t: 'text', v: '.' }])
    expect(tipos('pepe@empresa.com')).toEqual(['text'])
    expect(parseInline('@víctor_2')).toEqual([{ t: 'mention', name: 'víctor_2' }])
  })

  it('adjuntos y huecos de imagen', () => {
    expect(parseInline('[[img:a.png]][[img]][[file:b.pdf|B.pdf]][[file:c.zip]]')).toEqual([
      { t: 'img', fn: 'a.png' },
      { t: 'imgSlot' },
      { t: 'file', fn: 'b.pdf', orig: 'B.pdf' },
      { t: 'file', fn: 'c.zip', orig: null },
    ])
  })

  it('marcas sin cerrar quedan como texto', () => {
    expect(serializarInline(parseInline('**hola'))).toBe('**hola')
    expect(tipos('**hola')).toEqual(['text'])
    expect(tipos('a ~~ b')).toEqual(['text'])
  })

  it('texto patológico: no se cuelga y vuelve igual', () => {
    const s = '*a **b __c ~~d '.repeat(300)
    expect(serializarInline(parseInline(s))).toBe(s)
  })
})

describe('richtext: desde el editor (TipTap)', () => {
  const p = (...content: NodoTT[]): NodoTT => ({ type: 'paragraph', content })
  const t = (text: string, ...marks: string[]): NodoTT => ({ type: 'text', text, marks: marks.map((m) => (m.startsWith('http') ? { type: 'link', attrs: { href: m } } : { type: m })) })
  const desde = (...content: NodoTT[]) => serializar(tiptapADoc({ type: 'doc', content }))

  it('marcas solapadas se anidan de forma que se releen igual', () => {
    const casos = [
      desde(p(t('x', 'bold', 'italic'))),
      desde(p(t('a ', 'bold'), t('b', 'bold', 'italic'))),
      desde(p(t('a ', 'italic'), t('b', 'italic', 'bold'))),
      desde(p(t('b', 'bold', 'italic'), t(' a', 'italic'))),
      desde(p(t('u', 'underline', 'bold'), t(' s', 'strike'))),
      desde(p(t('code', 'code', 'bold'), t(' y ', 'bold'))),
    ]
    expect(casos).toEqual(['***x***', '**a *b***', '*a **b***', '***b** a*', '__**u**__~~ s~~', '**`code` y **'])
    for (const s of casos) expect(serializar(sinFuente(tiptapADoc(docATiptap(parse(s)))))).toBe(s)
  })

  it('enlaces: URL suelta si el texto es la URL; solo http(s)', () => {
    expect(desde(p(t('https://a.com', 'https://a.com')))).toBe('https://a.com')
    expect(desde(p(t('la web', 'https://a.com/x y')))).toBe('[la web](https://a.com/x%20y)')
    expect(desde(p({ type: 'text', text: 'correo', marks: [{ type: 'link', attrs: { href: 'mailto:a@b.c' } }] }))).toBe('correo')
  })

  it('saltos de línea, listas anidadas y tablas', () => {
    expect(desde(p(t('uno'), { type: 'hardBreak' }, t('dos')))).toBe('uno\ndos')
    expect(
      desde({
        type: 'bulletList',
        content: [{ type: 'listItem', content: [p(t('a')), { type: 'bulletList', content: [{ type: 'listItem', content: [p(t('a.1'))] }] }] }],
      }),
    ).toBe('- a\n- a.1')
    expect(
      desde({
        type: 'table',
        content: [
          { type: 'tableRow', content: [{ type: 'tableHeader', content: [p(t('A'))] }, { type: 'tableHeader', content: [p(t('B|C'))] }] },
          { type: 'tableRow', content: [{ type: 'tableCell', content: [p()] }, { type: 'tableCell', content: [p(t('2'))] }] },
        ],
      }),
    ).toBe('| A | B/C |\n| --- | --- |\n|  | 2 |')
    expect(desde({ type: 'taskList', content: [{ type: 'taskItem', attrs: { checked: true }, content: [p(t('hecho'))] }] })).toBe('[[chk:1]] hecho')
    expect(desde({ type: 'mention' as const, attrs: { id: 'laura' } })).toBe('')
    expect(desde(p({ type: 'mention', attrs: { id: 'laura', label: 'laura' } }, t(' mira')))).toBe('@laura mira')
  })

  it('un bloque editado sale canónico y el resto conserva su texto', () => {
    const doc = parse('• uno\n\nhola')
    const tt = docATiptap(doc)
    const ultimo = tt.content?.[2]
    if (ultimo?.content?.[0]) ultimo.content[0].text = 'hola!'
    expect(serializar(tiptapADoc(tt))).toBe('• uno\n\nhola!')
  })
})

describe('richtext: utilidades', () => {
  it('alternarCheck cambia solo ese elemento', () => {
    expect(alternarCheck(ACTA, 1, true)).toBe(ACTA.replace('[[chk:0]] Revisar', '[[chk:1]] Revisar'))
    expect(alternarCheck('sin checks', 0, true)).toBe('sin checks')
  })

  it('extracto sin marcadores, con 📷/📎 opcionales', () => {
    expect(extracto(COMENTARIO)).toBe('Hecho 👍 @laura revisa ojo con esto y esto')
    expect(extracto(COMENTARIO, 160, { adjuntos: true })).toBe('Hecho 👍 @laura revisa 📷 ojo con esto y esto')
    expect(extracto('# Título\n\n' + 'x'.repeat(200), 20)).toBe('Título ' + 'x'.repeat(13) + '…')
  })

  it('menciones y vacío', () => {
    expect(menciones(ACTA)).toEqual(['laura', 'marta', 'víctor'])
    expect(estaVacio('  \n ')).toBe(true)
    expect(estaVacio(null)).toBe(true)
  })

  it('URLs seguras', () => {
    expect(esUrlSegura('https://a.com')).toBe(true)
    expect(esUrlSegura('mailto:a@b.c')).toBe(true)
    expect(esUrlSegura('javascript:alert(1)')).toBe(false)
    expect(esUrlSegura('data:text/html,x')).toBe(false)
  })
})
