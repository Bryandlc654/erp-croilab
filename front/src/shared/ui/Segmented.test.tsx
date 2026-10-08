import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import Segmented from './Segmented'

type V = 'todas' | 'mias' | 'equipo'

function Ejemplo({ onChange }: { onChange: (v: V) => void }) {
  const [v, setV] = useState<V>('todas')
  return (
    <Segmented
      aria-label="Vista"
      value={v}
      onChange={(x) => {
        setV(x)
        onChange(x)
      }}
      items={[
        { value: 'todas', label: 'Todas', count: 24 },
        { value: 'mias', label: 'Mías', disabled: true },
        { value: 'equipo', label: 'Equipo' },
      ]}
    />
  )
}

describe('<Segmented>', () => {
  it('es un grupo de radio con el elegido marcado y su contador', () => {
    render(<Ejemplo onChange={vi.fn()} />)
    expect(screen.getByRole('radiogroup', { name: 'Vista' })).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: 'Todas 24' })).toHaveAttribute('aria-checked', 'true')
    expect(screen.getByRole('radio', { name: 'Equipo' })).toHaveAttribute('aria-checked', 'false')
  })

  it('clic cambia; volver a pulsar el elegido no avisa', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Ejemplo onChange={onChange} />)
    await user.click(screen.getByRole('radio', { name: 'Equipo' }))
    expect(onChange).toHaveBeenCalledWith('equipo')
    await user.click(screen.getByRole('radio', { name: 'Equipo' }))
    expect(onChange).toHaveBeenCalledTimes(1)
  })

  it('flechas: mueven la selección y el foco, saltando los desactivados y dando la vuelta', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Ejemplo onChange={onChange} />)
    screen.getByRole('radio', { name: 'Todas 24' }).focus()
    await user.keyboard('{ArrowRight}')
    expect(onChange).toHaveBeenLastCalledWith('equipo')
    expect(screen.getByRole('radio', { name: 'Equipo' })).toHaveFocus()
    await user.keyboard('{ArrowRight}')
    expect(onChange).toHaveBeenLastCalledWith('todas')
    // Solo el elegido está en el orden de tabulación (roving tabindex).
    expect(screen.getByRole('radio', { name: 'Equipo' })).toHaveAttribute('tabindex', '-1')
  })

  it('con href cada segmento es un enlace con aria-current', () => {
    render(
      <MemoryRouter>
        <Segmented
          value="mine"
          items={[
            { value: 'all', label: 'Todas', href: '/tareas?view=all' },
            { value: 'mine', label: 'Mías', href: '/tareas?view=mine' },
          ]}
        />
      </MemoryRouter>,
    )
    expect(screen.getByRole('link', { name: 'Mías' })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByRole('link', { name: 'Todas' })).not.toHaveAttribute('aria-current')
  })
})
