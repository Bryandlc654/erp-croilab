import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import Select, { type OpcionSelect } from './Select'
import Field from './Field'

const OPCIONES: OpcionSelect[] = [
  { value: 'pendiente', label: 'En espera' },
  { value: 'proceso', label: 'En proceso' },
  { value: 'atemporal', label: 'Atemporal', disabled: true },
  { value: 'hecha', label: 'Completada' },
]

function Controlado({ onChange, searchable = false, inicial = 'proceso' }: { onChange: (v: string) => void; searchable?: boolean; inicial?: string | null }) {
  const [v, setV] = useState<string | null>(inicial)
  return (
    <Select
      aria-label="Estado"
      value={v}
      options={OPCIONES}
      searchable={searchable}
      onChange={(x) => {
        setV(x)
        onChange(x)
      }}
    />
  )
}

describe('<Select>', () => {
  it('enseña la opción elegida y al abrir marca la actual', async () => {
    const user = userEvent.setup()
    render(<Controlado onChange={vi.fn()} />)
    const combo = screen.getByRole('combobox', { name: 'Estado' })
    expect(combo).toHaveTextContent('En proceso')
    expect(combo).toHaveAttribute('aria-expanded', 'false')

    await user.click(combo)
    expect(combo).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByRole('option', { name: 'En proceso' })).toHaveAttribute('aria-selected', 'true')
  })

  it('con el ratón elige y se cierra; repetir la actual no avisa', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Controlado onChange={onChange} />)
    await user.click(screen.getByRole('combobox'))
    await user.click(screen.getByRole('option', { name: 'Completada' }))
    expect(onChange).toHaveBeenCalledWith('hecha')
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    expect(screen.getByRole('combobox')).toHaveTextContent('Completada')

    await user.click(screen.getByRole('combobox'))
    await user.click(screen.getByRole('option', { name: 'Completada' }))
    expect(onChange).toHaveBeenCalledTimes(1)
  })

  it('teclado: flechas saltan las opciones desactivadas, Enter elige y el foco vuelve al disparador', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Controlado onChange={onChange} />)
    const combo = screen.getByRole('combobox')
    combo.focus()
    await user.keyboard('{ArrowDown}')
    const lista = screen.getByRole('listbox')
    expect(lista).toHaveFocus()
    // Activa la actual (En proceso); abajo salta «Atemporal» (desactivada) y llega a «Completada».
    await user.keyboard('{ArrowDown}')
    expect(lista.getAttribute('aria-activedescendant')).toBe(screen.getByRole('option', { name: 'Completada' }).id)
    await user.keyboard('{Enter}')
    expect(onChange).toHaveBeenCalledWith('hecha')
    expect(combo).toHaveFocus()
  })

  it('Esc cierra sin elegir y la letra salta a la primera opción que empieza por ella', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Controlado onChange={onChange} />)
    await user.click(screen.getByRole('combobox'))
    await user.keyboard('c')
    expect(screen.getByRole('listbox').getAttribute('aria-activedescendant')).toBe(screen.getByRole('option', { name: 'Completada' }).id)
    await user.keyboard('{Escape}')
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    expect(onChange).not.toHaveBeenCalled()
  })

  it('con buscador filtra sin tildes y Enter elige la primera coincidencia', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Controlado onChange={onChange} searchable inicial={null} />)
    await user.click(screen.getByRole('combobox', { name: 'Estado' }))
    const buscador = screen.getByRole('combobox', { name: 'Buscar…' })
    expect(buscador).toHaveFocus()
    await user.type(buscador, 'complé')
    expect(screen.getAllByRole('option')).toHaveLength(1)
    await user.keyboard('{Enter}')
    expect(onChange).toHaveBeenCalledWith('hecha')
  })

  it('dentro de un Field queda enlazado a su etiqueta', () => {
    render(
      <Field label="Estado del trabajo">
        <Select value="proceso" onChange={() => {}} options={OPCIONES} />
      </Field>,
    )
    expect(screen.getByRole('combobox', { name: 'Estado del trabajo' })).toBeInTheDocument()
  })
})
