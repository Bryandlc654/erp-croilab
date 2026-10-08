import { leer } from './importes'

/* Proyecto elegido en un combobox: uno existente (id) o uno nuevo que se crea al guardar (nombre). */
export type ProyectoElegido = { id: number | null; nombre: string; color?: string } | null

/* Cuerpo para la API: project_id existente o project_nombre para crearlo. */
export function proyectoACuerpo(p: ProyectoElegido) {
  if (!p) return { project_id: null }
  return p.id ? { project_id: p.id } : { project_nombre: p.nombre }
}

/* Línea de un formulario (editor de facturas, programaciones, calculadora):
   cantidad y precio como los escribe la persona («2,5», «1.234,56»). */
export type LineaForm = { key: string; concepto: string; cantidad: string; precio: string }

let contador = 0
export function nuevaLinea(l: Partial<Omit<LineaForm, 'key'>> = {}): LineaForm {
  contador += 1
  return { key: `l${contador}`, concepto: l.concepto ?? '', cantidad: l.cantidad ?? '1', precio: l.precio ?? '' }
}

/* Para calcular y para mandar a la API: números normalizados («1234.56»). */
export function lineasNormalizadas(ls: LineaForm[]) {
  return ls.map((l) => ({ concepto: l.concepto.trim(), cantidad: leer(l.cantidad) ?? '0.00', precio: leer(l.precio) ?? '0.00' }))
}

/* Lo que se manda: sin las líneas vacías del todo (el servidor descarta las que no tienen concepto). */
export function lineasACuerpo(ls: LineaForm[]) {
  return lineasNormalizadas(ls).filter((l) => l.concepto !== '' || leer(l.precio) !== '0.00')
}

/* «1234.50» guardado → «1234,5» para editar. */
export function aCampo(dec: string) {
  if (!dec.includes('.')) return dec
  return dec.replace(/0+$/, '').replace(/\.$/, '').replace('.', ',') || '0'
}
