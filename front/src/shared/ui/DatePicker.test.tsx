import { useState } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { DateInput } from './DatePicker'

function Controlado({ onChange, inicial = '2026-10-08' }: { onChange: (v: string | null) => void; inicial?: string | null }) {
  const [v, setV] = useState<string | null>(inicial)
  return (
    <>
      <DateInput
        aria-label="Fecha límite"
        value={v}
        onChange={(x) => {
          setV(x)
          onChange(x)
        }}
      />
      <button type="button">fuera</button>
    </>
  )
}

describe('<DateInput>', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date(2026, 9, 8, 12, 0))
  })
  afterEach(() => vi.useRealTimers())

  it('se ve en dd/mm/aa y abrir el calendario no cambia nada', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Controlado onChange={onChange} />)
    const input = screen.getByLabelText('Fecha límite')
    expect(input).toHaveValue('08/10/26')
    await user.click(input)
    expect(screen.getByRole('grid', { name: 'Octubre 2026' })).toBeInTheDocument()
    // Semana en lunes: la primera cabecera es «L».
    expect(screen.getAllByRole('columnheader')[0]).toHaveTextContent('L')
    await user.click(screen.getByText('fuera'))
    expect(onChange).not.toHaveBeenCalled()
  })

  it('escribir a mano y Enter guarda en ISO', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Controlado onChange={onChange} />)
    const input = screen.getByLabelText('Fecha límite')
    await user.clear(input)
    await user.type(input, '3-11-2026{Enter}')
    expect(onChange).toHaveBeenCalledWith('2026-11-03')
    expect(input).toHaveValue('03/11/26')
  })

  it('un texto inválido vuelve a la fecha anterior al salir', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Controlado onChange={onChange} />)
    const input = screen.getByLabelText('Fecha límite')
    await user.clear(input)
    await user.type(input, '31/02/26')
    await user.click(screen.getByText('fuera'))
    expect(onChange).not.toHaveBeenCalled()
    expect(input).toHaveValue('08/10/26')
  })

  it('vaciar el campo borra la fecha', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Controlado onChange={onChange} />)
    const input = screen.getByLabelText('Fecha límite')
    await user.clear(input)
    await user.click(screen.getByText('fuera'))
    expect(onChange).toHaveBeenCalledWith(null)
  })

  it('elegir un día, «Hoy» y «Borrar» del calendario', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Controlado onChange={onChange} inicial={null} />)
    const input = screen.getByLabelText('Fecha límite')
    await user.click(input)
    await user.click(screen.getByRole('gridcell', { name: '15/10/26' }))
    expect(onChange).toHaveBeenLastCalledWith('2026-10-15')
    expect(screen.queryByRole('grid')).not.toBeInTheDocument()

    await user.click(input)
    await user.click(screen.getByRole('button', { name: 'Hoy' }))
    expect(onChange).toHaveBeenLastCalledWith('2026-10-08')

    await user.click(input)
    await user.click(screen.getByRole('button', { name: 'Borrar' }))
    expect(onChange).toHaveBeenLastCalledWith(null)
  })

  it('con el calendario abierto, ↓ baja una semana y Enter la elige', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Controlado onChange={onChange} />)
    await user.click(screen.getByLabelText('Fecha límite'))
    await user.keyboard('{ArrowDown}{Enter}')
    expect(onChange).toHaveBeenCalledWith('2026-10-15')
  })
})
