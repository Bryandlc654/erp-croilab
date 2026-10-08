import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import Modal, { ModalBody, ModalFooter } from './Modal'
import Select from './Select'

function Ejemplo({ onClose = vi.fn() }: { onClose?: () => void }) {
  const [abierto, setAbierto] = useState(false)
  const [v, setV] = useState<string | null>('a')
  return (
    <>
      <button type="button" onClick={() => setAbierto(true)}>
        Abrir
      </button>
      <Modal
        open={abierto}
        onClose={() => {
          onClose()
          setAbierto(false)
        }}
        title="Nueva tarea"
      >
        <ModalBody>
          <input aria-label="Título" />
          <Select aria-label="Responsable" value={v} onChange={setV} options={[{ value: 'a', label: 'Ana' }, { value: 'b', label: 'Bea' }]} />
        </ModalBody>
        <ModalFooter>
          <button type="button">Crear</button>
        </ModalFooter>
      </Modal>
    </>
  )
}

describe('<Modal>', () => {
  it('se etiqueta con su título, pone el foco dentro y lo devuelve al cerrar con Esc', async () => {
    const user = userEvent.setup()
    const onClose = vi.fn()
    render(<Ejemplo onClose={onClose} />)
    const abrir = screen.getByRole('button', { name: 'Abrir' })
    await user.click(abrir)
    const dialogo = screen.getByRole('dialog', { name: 'Nueva tarea' })
    expect(dialogo).toHaveAttribute('aria-modal', 'true')
    // El primer enfocable es la X de la cabecera.
    expect(screen.getByRole('button', { name: 'Cerrar' })).toHaveFocus()
    expect(document.body.style.overflow).toBe('hidden')

    await user.keyboard('{Escape}')
    expect(onClose).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(abrir).toHaveFocus()
    expect(document.body.style.overflow).toBe('')
  })

  it('el Tab no sale del modal (trampa de foco en los dos sentidos)', async () => {
    const user = userEvent.setup()
    render(<Ejemplo />)
    await user.click(screen.getByRole('button', { name: 'Abrir' }))
    const cerrar = screen.getByRole('button', { name: 'Cerrar' })
    const crear = screen.getByRole('button', { name: 'Crear' })
    await user.tab()
    expect(screen.getByLabelText('Título')).toHaveFocus()
    await user.tab()
    expect(screen.getByRole('combobox', { name: 'Responsable' })).toHaveFocus()
    await user.tab()
    expect(crear).toHaveFocus()
    await user.tab()
    expect(cerrar).toHaveFocus()
    await user.tab({ shift: true })
    expect(crear).toHaveFocus()
  })

  it('Esc con un Select abierto dentro cierra solo el Select', async () => {
    const user = userEvent.setup()
    const onClose = vi.fn()
    render(<Ejemplo onClose={onClose} />)
    await user.click(screen.getByRole('button', { name: 'Abrir' }))
    await user.click(screen.getByRole('combobox', { name: 'Responsable' }))
    expect(screen.getByRole('listbox')).toBeInTheDocument()
    await user.keyboard('{Escape}')
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
    expect(onClose).not.toHaveBeenCalled()
  })

  it('clic en la máscara cierra; clic dentro no', async () => {
    const user = userEvent.setup()
    const onClose = vi.fn()
    render(<Ejemplo onClose={onClose} />)
    await user.click(screen.getByRole('button', { name: 'Abrir' }))
    await user.click(screen.getByLabelText('Título'))
    expect(onClose).not.toHaveBeenCalled()
    await user.click(screen.getByRole('dialog').parentElement!)
    expect(onClose).toHaveBeenCalledTimes(1)
  })
})
