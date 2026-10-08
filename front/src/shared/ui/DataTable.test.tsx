import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import DataTable, { type Columna } from './DataTable'
import { ordenarFilas, siguienteOrden, estadoSeleccion } from './tabla'

type F = { id: number; nombre: string; valor: number | null }
const FILAS: F[] = [
  { id: 1, nombre: 'Bodegas Ribera', valor: 300 },
  { id: 2, nombre: 'aeternum', valor: null },
  { id: 3, nombre: 'Clínica Sur', valor: 50 },
]
const COLS: Columna<F>[] = [
  { key: 'nombre', header: 'Nombre', sortable: true, sortValue: (f) => f.nombre, render: (f) => f.nombre },
  { key: 'valor', header: 'Valor', sortable: true, sortValue: (f) => f.valor, render: (f) => f.valor ?? '—' },
]

const nombres = () =>
  screen
    .getAllByRole('row')
    .slice(1)
    .map((r) => within(r).getAllByRole('cell')[1]?.textContent)

function Tabla({ onSelect = vi.fn(), onRowClick }: { onSelect?: (ids: (string | number)[]) => void; onRowClick?: (f: F) => void }) {
  const [sel, setSel] = useState<(string | number)[]>([])
  return (
    <DataTable
      aria-label="Clientes"
      columns={COLS}
      rows={FILAS}
      getRowId={(f) => f.id}
      selectable
      selected={sel}
      onRowClick={onRowClick}
      onSelect={(ids) => {
        setSel(ids)
        onSelect(ids)
      }}
    />
  )
}

describe('<DataTable>', () => {
  it('ordena al pulsar la cabecera: ascendente, descendente y sin orden (vacíos al final)', async () => {
    const user = userEvent.setup()
    render(<Tabla />)
    expect(nombres()).toEqual(['Bodegas Ribera', 'aeternum', 'Clínica Sur'])

    const valor = screen.getByRole('button', { name: /Valor/ })
    await user.click(valor)
    expect(screen.getByRole('columnheader', { name: /Valor/ })).toHaveAttribute('aria-sort', 'ascending')
    expect(nombres()).toEqual(['Clínica Sur', 'Bodegas Ribera', 'aeternum'])

    await user.click(valor)
    expect(screen.getByRole('columnheader', { name: /Valor/ })).toHaveAttribute('aria-sort', 'descending')
    expect(nombres()).toEqual(['Bodegas Ribera', 'Clínica Sur', 'aeternum'])

    await user.click(valor)
    expect(nombres()).toEqual(['Bodegas Ribera', 'aeternum', 'Clínica Sur'])
  })

  it('selección por fila y «todos» a medias', async () => {
    const user = userEvent.setup()
    const onSelect = vi.fn()
    render(<Tabla onSelect={onSelect} />)
    const todas = screen.getByRole('checkbox', { name: 'Seleccionar todas las filas' })
    const filas = screen.getAllByRole('checkbox', { name: 'Seleccionar fila' })

    await user.click(filas[0])
    expect(onSelect).toHaveBeenLastCalledWith([1])
    expect(todas).toHaveAttribute('aria-checked', 'mixed')
    expect((todas as HTMLInputElement).indeterminate).toBe(true)

    await user.click(todas)
    expect(onSelect).toHaveBeenLastCalledWith([1, 2, 3])
    expect(screen.getByRole('checkbox', { name: 'Quitar la selección' })).toBeChecked()

    await user.click(screen.getByRole('checkbox', { name: 'Quitar la selección' }))
    expect(onSelect).toHaveBeenLastCalledWith([])
  })

  it('marcar la casilla no abre la fila; pulsar la fila sí', async () => {
    const user = userEvent.setup()
    const onRowClick = vi.fn()
    render(<Tabla onRowClick={onRowClick} />)
    await user.click(screen.getAllByRole('checkbox', { name: 'Seleccionar fila' })[0])
    expect(onRowClick).not.toHaveBeenCalled()
    await user.click(screen.getByText('Clínica Sur'))
    expect(onRowClick).toHaveBeenCalledWith(FILAS[2])
  })

  it('sin filas enseña el vacío', () => {
    render(<DataTable columns={COLS} rows={[]} getRowId={(f) => f.id} empty="Aún no hay clientes" />)
    expect(screen.getByText('Aún no hay clientes')).toBeInTheDocument()
  })
})

describe('helpers de tabla', () => {
  it('ordenarFilas es estable y compara en español (acentos, mayúsculas, números)', () => {
    const l = [{ n: 'b' }, { n: 'Á' }, { n: 'a' }, { n: 'item 10' }, { n: 'item 2' }]
    expect(ordenarFilas(l, (x) => x.n, 'asc').map((x) => x.n)).toEqual(['Á', 'a', 'b', 'item 2', 'item 10'])
  })

  it('siguienteOrden y estadoSeleccion', () => {
    expect(siguienteOrden(null, 'a')).toEqual({ key: 'a', dir: 'asc' })
    expect(siguienteOrden({ key: 'a', dir: 'asc' }, 'a')).toEqual({ key: 'a', dir: 'desc' })
    expect(siguienteOrden({ key: 'a', dir: 'desc' }, 'a')).toBeNull()
    expect(siguienteOrden({ key: 'a', dir: 'desc' }, 'b')).toEqual({ key: 'b', dir: 'asc' })
    expect(estadoSeleccion([1, 2], new Set([1]))).toBe('some')
    expect(estadoSeleccion([1, 2], new Set([1, 2, 3]))).toBe('all')
    expect(estadoSeleccion([], new Set([1]))).toBe('none')
  })
})
