import { describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ConfirmProvider } from './ConfirmDialog'
import { useConfirm, type ConfirmCtx } from './useConfirm'

/* Expone la API del hook a la prueba. */
function Gancho({ recibir }: { recibir: (c: ConfirmCtx) => void }) {
  recibir(useConfirm())
  return <button type="button">antes</button>
}

function montar() {
  let api!: ConfirmCtx
  render(
    <ConfirmProvider>
      <Gancho recibir={(c) => (api = c)} />
    </ConfirmProvider>,
  )
  return () => api
}

describe('useConfirm()', () => {
  it('confirm: «Eliminar» en peligro resuelve true', async () => {
    const user = userEvent.setup()
    const api = montar()
    const p = api().confirm({ message: 'La tarea va a la papelera.', danger: true })
    const dialogo = await screen.findByRole('alertdialog', { name: '¿Seguro?' })
    expect(dialogo).toHaveAccessibleDescription('La tarea va a la papelera.')
    // El foco empieza en el botón de aceptar.
    expect(screen.getByRole('button', { name: 'Eliminar' })).toHaveFocus()
    await user.click(screen.getByRole('button', { name: 'Eliminar' }))
    await expect(p).resolves.toBe(true)
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  })

  it('Esc o «Cancelar» resuelven false', async () => {
    const user = userEvent.setup()
    const api = montar()
    const p1 = api().confirm('¿Seguro que quieres salir?')
    await screen.findByRole('alertdialog')
    await user.keyboard('{Escape}')
    await expect(p1).resolves.toBe(false)

    const p2 = api().confirm({ title: 'Salir', okLabel: 'Salir' })
    await screen.findByRole('alertdialog', { name: 'Salir' })
    await user.click(screen.getByRole('button', { name: 'Cancelar' }))
    await expect(p2).resolves.toBe(false)
  })

  it('Enter acepta salvo con el foco en «Cancelar»', async () => {
    const user = userEvent.setup()
    const api = montar()
    const p1 = api().confirm('¿Seguro?')
    await screen.findByRole('alertdialog')
    await user.keyboard('{Enter}')
    await expect(p1).resolves.toBe(true)

    const p2 = api().confirm('¿Seguro?')
    await screen.findByRole('alertdialog')
    // Espera al foco inicial (en «Aceptar») antes de moverlo.
    await waitFor(() => expect(screen.getByRole('button', { name: 'Aceptar' })).toHaveFocus())
    screen.getByRole('button', { name: 'Cancelar' }).focus()
    await user.keyboard('{Enter}')
    await expect(p2).resolves.toBe(false)
  })

  it('prompt devuelve el texto (o null si se deja vacío)', async () => {
    const user = userEvent.setup()
    const api = montar()
    const p1 = api().prompt({ title: 'Renombrar lista', value: 'Tareas' })
    const input = await screen.findByRole('textbox', { name: 'Renombrar lista' })
    expect(input).toHaveFocus()
    await user.clear(input)
    await user.type(input, 'Campañas{Enter}')
    await expect(p1).resolves.toBe('Campañas')

    const p2 = api().prompt({ title: 'Renombrar lista' })
    await screen.findByRole('textbox')
    await user.click(screen.getByRole('button', { name: 'Guardar' }))
    await expect(p2).resolves.toBeNull()
  })

  it('alert y la cola: el segundo espera a que se cierre el primero', async () => {
    const user = userEvent.setup()
    const api = montar()
    const fin = vi.fn()
    const a = api().alert('Primero').then(fin)
    const b = api().confirm('Segundo')
    expect(await screen.findByText('Primero')).toBeInTheDocument()
    expect(screen.queryByText('Segundo')).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Entendido' }))
    await a
    expect(fin).toHaveBeenCalled()
    expect(await screen.findByText('Segundo')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Aceptar' }))
    await expect(b).resolves.toBe(true)
  })

  it('al cerrar devuelve el foco a donde estaba', async () => {
    const user = userEvent.setup()
    const api = montar()
    const antes = screen.getByRole('button', { name: 'antes' })
    antes.focus()
    const p = api().confirm('¿Seguro?')
    await screen.findByRole('alertdialog')
    await user.keyboard('{Escape}')
    await p
    expect(antes).toHaveFocus()
  })
})
