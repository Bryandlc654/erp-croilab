import { numero } from '../../../shared/lib/formato'

/* Exportación de los movimientos de Contabilidad (como el antiguo
   contabilidad.php?export=csv|xls). Se genera aquí, con los mismos datos que la
   tabla: la API solo devuelve JSON. */

export type FilaExport = {
  fecha: string | null
  concepto: string
  tipo: string
  categoria: string
  ambito_nombre: string
  project: { nombre: string } | null
  metodo: string
  legal: boolean
  deducible: boolean
  personal: boolean
  importe: number
}

const CABECERA = ['Fecha', 'Concepto', 'Tipo', 'Categoría', 'Ámbito', 'Proyecto', 'Método', 'Legal', 'Deducible', 'Personal', 'Importe (€)']

function fecha(iso: string | null) {
  const m = iso ? /^(\d{4})-(\d{2})-(\d{2})/.exec(iso) : null
  return m ? `${m[3]}/${m[2]}/${m[1]}` : ''
}

function celdas(f: FilaExport): string[] {
  return [
    fecha(f.fecha),
    f.concepto,
    f.tipo === 'ingreso' ? 'Ingreso' : 'Gasto',
    f.categoria,
    f.ambito_nombre,
    f.project?.nombre ?? '',
    f.metodo,
    f.legal ? 'Sí' : 'No',
    f.deducible ? 'Sí' : 'No',
    f.personal ? 'Sí' : 'No',
    numero((f.tipo === 'gasto' ? -f.importe : f.importe) / 100, 2),
  ]
}

function sumas(filas: FilaExport[]) {
  const ing = filas.filter((f) => f.tipo === 'ingreso').reduce((s, f) => s + f.importe, 0)
  const gas = filas.filter((f) => f.tipo === 'gasto').reduce((s, f) => s + f.importe, 0)
  return { ing, gas, ben: ing - gas }
}

/* CSV con BOM (Excel lo abre con tildes), separador «;» e importes «1.234,56». */
export function csv(filas: FilaExport[]): string {
  const esc = (v: string) => (/[;"\n\r]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v)
  const s = sumas(filas)
  const lineas = [CABECERA, ...filas.map(celdas)].map((l) => l.map(esc).join(';'))
  lineas.push('', `Ingresos;${numero(s.ing / 100, 2)}`, `Gastos;${numero(s.gas / 100, 2)}`, `Beneficio;${numero(s.ben / 100, 2)}`)
  return '﻿' + lineas.join('\r\n') + '\r\n'
}

const xml = (v: string) => v.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

/* Hoja de Excel (SpreadsheetML 2003, .xls): importes como número, con título,
   cabecera oscura y totales. Sin HTML ni librerías. */
export function xls(filas: FilaExport[], titulo: string): string {
  const s = sumas(filas)
  const num = (cent: number, estilo = 'n') => `<Cell ss:StyleID="${estilo}"><Data ss:Type="Number">${(cent / 100).toFixed(2)}</Data></Cell>`
  const txt = (v: string, estilo = '') => `<Cell${estilo ? ` ss:StyleID="${estilo}"` : ''}><Data ss:Type="String">${xml(v)}</Data></Cell>`
  const filasXml = filas
    .map((f) => {
      const c = celdas(f)
      return `<Row>${c.slice(0, 10).map((v) => txt(v)).join('')}${num(f.tipo === 'gasto' ? -f.importe : f.importe, f.tipo === 'gasto' ? 'neg' : 'pos')}</Row>`
    })
    .join('')
  return `<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
<Styles>
<Style ss:ID="t"><Font ss:Bold="1" ss:Size="14"/></Style>
<Style ss:ID="h"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#111318" ss:Pattern="Solid"/></Style>
<Style ss:ID="n"><NumberFormat ss:Format="#,##0.00"/></Style>
<Style ss:ID="pos"><Font ss:Color="#12854A"/><NumberFormat ss:Format="#,##0.00"/></Style>
<Style ss:ID="neg"><Font ss:Color="#E5484D"/><NumberFormat ss:Format="#,##0.00"/></Style>
<Style ss:ID="b"><Font ss:Bold="1"/></Style>
</Styles>
<Worksheet ss:Name="Contabilidad"><Table>
<Row>${txt(titulo, 't')}</Row><Row/>
<Row>${CABECERA.map((h) => txt(h, 'h')).join('')}</Row>
${filasXml}
<Row/>
<Row>${txt('Ingresos', 'b')}${num(s.ing)}</Row>
<Row>${txt('Gastos', 'b')}${num(s.gas)}</Row>
<Row>${txt('Beneficio', 'b')}${num(s.ben)}</Row>
</Table></Worksheet></Workbook>`
}

/* Descarga un texto o un binario como archivo. */
export function descargar(nombre: string, contenido: BlobPart, mime: string) {
  const url = URL.createObjectURL(new Blob([contenido], { type: mime }))
  const a = document.createElement('a')
  a.href = url
  a.download = nombre
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

export function base64ABytes(b64: string) {
  const bin = atob(b64)
  const out = new Uint8Array(bin.length)
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i)
  return out
}
