import { describe, expect, it } from 'vitest'
import type { ClienteFila, DatosCliente } from '../schemas'
import { contarSegmentos, filtrarClientes, segmentoDe, textoALaVista } from './filtro'
import { cuerpoDesdeForm, erroresBasicos, filasAProgreso, formDesdeDatos, formVacio, hayCambios, progresoAFilas, seccionDeCampo, seccionDeHash } from './formulario'
import { importe } from './etiquetas'

function cli(p: Partial<ClienteFila>): ClienteFila {
  return {
    id: 1, name: 'Cliente', iniciales: '', activo: true, username: 'cli', conversiones: true, tipo_id: null, tipo_nombre: null,
    partner_id: null, tareas_abiertas: 0, tickets_abiertos: 0, pendiente_cobro: 0, ...p,
  }
}

describe('filtro del listado', () => {
  const lista = [
    cli({ id: 1, name: 'Clínica Dental', username: 'sonrisa', tipo_id: 2 }),
    cli({ id: 2, name: 'Hotel Mirador', username: 'mirador', activo: false, tipo_id: 2 }),
    cli({ id: 3, name: 'Panadería', username: 'espiga' }),
  ]

  it('el segmento sale de ?f= y por defecto es «En alta»', () => {
    expect(segmentoDe(null)).toBe('alta')
    expect(segmentoDe('baja')).toBe('baja')
    expect(segmentoDe('raro')).toBe('alta')
  })

  it('combina segmento, buscador (nombre o usuario, sin acentos) y tipo', () => {
    expect(filtrarClientes(lista, { segmento: 'alta', q: '', tipo: '' }).map((c) => c.id)).toEqual([1, 3])
    expect(filtrarClientes(lista, { segmento: 'todos', q: 'clinica', tipo: '' }).map((c) => c.id)).toEqual([1])
    expect(filtrarClientes(lista, { segmento: 'todos', q: 'MIRA', tipo: '' }).map((c) => c.id)).toEqual([2])
    expect(filtrarClientes(lista, { segmento: 'todos', q: '', tipo: 'none' }).map((c) => c.id)).toEqual([3])
    expect(filtrarClientes(lista, { segmento: 'alta', q: '', tipo: '2' }).map((c) => c.id)).toEqual([1])
  })

  it('cuenta los segmentos y escribe el subtítulo', () => {
    expect(contarSegmentos(lista)).toEqual({ alta: 2, baja: 1, todos: 3 })
    expect(textoALaVista(1)).toBe('1 cliente a la vista.')
    expect(textoALaVista(0)).toBe('0 clientes a la vista.')
  })

  it('sin permiso de importes enseña puntos', () => {
    expect(importe(null)).toBe('·····')
    expect(importe(123456)).toBe('1.234,56 €')
  })
})

describe('formulario del cliente', () => {
  const datos: DatosCliente = {
    id: 4, name: 'La Espiga', username: 'laespiga', iniciales: 'LE', saludo: 'Marta', conversiones: true, activo: true, actual: 'Junio',
    tipo_id: 1, tipo_nombre: 'SEO', login_email: '', partner_id: null, contact_id: null, google: false,
    fact_nombre: '', fact_nif: '', fact_dir: '', fact_email: '', fact_tel: '',
    estado: { nombre: 'Etapa', etiqueta: '', siguiente: '', fases: [{ t: 'Auditoría', s: '', estado: 'done' }] },
    plan: { resumen: '', items: [{ n: '4', t: 'Artículos' }], detalle: [] },
    accesos: [{ b: 'Figma', s: '', u: 'https://figma.com', tipo: 'figma' }],
    tareas: [{ mes: 'Junio', completado: [{ t: 'A', d: 'x' }], pendiente: [{ t: 'B', d: '' }] }],
  }

  it('el progreso va y vuelve entre filas y meses', () => {
    const filas = progresoAFilas(datos.tareas)
    expect(filas.map((f) => [f.mes, f.estado, f.t])).toEqual([['Junio', 'completado', 'A'], ['Junio', 'pendiente', 'B']])
    expect(filasAProgreso(filas)).toEqual(datos.tareas)
    // Filas sin título no cuentan.
    expect(filasAProgreso([{ key: 'x', mes: 'Julio', estado: 'pendiente', t: ' ', d: '' }])).toEqual([])
  })

  it('cargar y no tocar nada no es un cambio; tocar sí', () => {
    const inicial = formDesdeDatos(datos)
    const igual = formDesdeDatos(datos)
    expect(hayCambios(igual, inicial)).toBe(false)
    expect(hayCambios({ ...igual, saludo: 'Hola' }, inicial)).toBe(true)
    expect(hayCambios({ ...igual, password: 'nueva' }, inicial)).toBe(true)
  })

  it('el cuerpo descarta filas vacías y solo manda la contraseña si se escribe', () => {
    const f = formVacio()
    f.items.push({ key: 'a', n: '', t: '' })
    f.accesos.push({ key: 'b', b: '', s: 'x', u: '', tipo: 'web' })
    const c = cuerpoDesdeForm(f)
    expect(c.password).toBeUndefined()
    expect((c.plan as { items: unknown[] }).items).toEqual([])
    expect(c.accesos).toEqual([])
    expect((c.estado as { fases: unknown[] }).fases).toHaveLength(4)
    expect(cuerpoDesdeForm({ ...f, password: 'secreta' }).password).toBe('secreta')
  })

  it('valida lo básico y sabe a qué sección llevar cada error', () => {
    expect(erroresBasicos(formVacio(), true)).toEqual({
      name: 'El nombre es obligatorio.', username: 'El usuario es obligatorio.', password: 'Pon una contraseña para el cliente.',
    })
    expect(erroresBasicos({ ...formVacio(), name: 'x', username: 'y' }, false)).toEqual({})
    expect(seccionDeCampo('fact_email')).toBe('facturacion')
    expect(seccionDeCampo('tareas')).toBe('progreso-cliente')
    expect(seccionDeCampo('username')).toBe('datos')
    expect(seccionDeHash('#fact')).toBe('facturacion')
    expect(seccionDeHash('')).toBe('datos')
  })
})
