import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import TareaFila from './TareaFila'
import { tarea } from '../../../test/fixtures'
import type { Persona } from '../../../shared/schemas'

const equipo: Persona[] = [
  { id: 1, username: 'laura', foto: null },
  { id: 2, username: 'bdelacruz654', foto: null },
]

function pintar(props: { puedeEditar: boolean; puedeBorrar: boolean }) {
  const t = tarea({ id: 7, titulo: 'Preparar informe', estado: 'pendiente', prioridad: 0, asignados: [equipo[0]], responsable_id: 1 })
  const onCampo = vi.fn()
  const onBorrar = vi.fn()
  render(<TareaFila t={t} equipo={equipo} mostrarLista onCampo={onCampo} onAbrir={vi.fn()} onBorrar={onBorrar} {...props} />)
  return { t, onCampo, onBorrar }
}

describe('<TareaFila>', () => {
  it('sin permiso de edición los controles en línea están desactivados y sin permiso de borrado no hay botón', () => {
    pintar({ puedeEditar: false, puedeBorrar: false })
    expect(screen.getByRole('button', { name: 'Estado: En espera' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Prioridad: Ninguna' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Persona asignada' })).toBeDisabled()
    expect(screen.getByLabelText('Fecha límite')).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Borrar Preparar informe' })).not.toBeInTheDocument()
    // La vista rápida sigue disponible.
    expect(screen.getByRole('button', { name: 'Vista rápida de Preparar informe' })).toBeEnabled()
  })

  it('con permisos elegir una prioridad llama a onCampo con el valor como texto', async () => {
    const user = userEvent.setup()
    const { t, onCampo } = pintar({ puedeEditar: true, puedeBorrar: true })
    expect(screen.getByLabelText('Fecha límite')).toBeEnabled()

    await user.click(screen.getByRole('button', { name: 'Prioridad: Ninguna' }))
    await user.click(screen.getByRole('menuitem', { name: 'Urgente' }))

    expect(onCampo).toHaveBeenCalledTimes(1)
    expect(onCampo).toHaveBeenCalledWith(t, 'prioridad', '4')
    // El menú se cierra después de elegir.
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('con permiso de borrado el botón llama a onBorrar', async () => {
    const user = userEvent.setup()
    const { t, onBorrar } = pintar({ puedeEditar: true, puedeBorrar: true })
    await user.click(screen.getByRole('button', { name: 'Borrar Preparar informe' }))
    expect(onBorrar).toHaveBeenCalledWith(t)
  })

  it('asignar a varias personas manda los ids separados por comas', async () => {
    const user = userEvent.setup()
    const { t, onCampo } = pintar({ puedeEditar: true, puedeBorrar: false })
    await user.click(screen.getByRole('button', { name: 'Persona asignada' }))
    await user.click(screen.getByRole('option', { name: /bdelacruz654/ }))
    expect(onCampo).toHaveBeenCalledWith(t, 'asignados', '1,2')
  })

  it('clic en la fila abre la ficha; la lupa, la vista rápida', async () => {
    const user = userEvent.setup()
    const onAbrir = vi.fn()
    const onVistaRapida = vi.fn()
    const t = tarea({ id: 9, titulo: 'Fila' })
    render(<TareaFila t={t} equipo={equipo} mostrarLista={false} puedeEditar={false} puedeBorrar={false} onCampo={vi.fn()} onAbrir={onAbrir} onBorrar={vi.fn()} onVistaRapida={onVistaRapida} />)
    await user.click(screen.getByText('Fila'))
    expect(onAbrir).toHaveBeenCalledWith(t)
    await user.click(screen.getByRole('button', { name: 'Vista rápida de Fila' }))
    expect(onVistaRapida).toHaveBeenCalledWith(t)
    expect(onAbrir).toHaveBeenCalledTimes(1)
  })

  it('volver a elegir la prioridad actual no manda nada', async () => {
    const user = userEvent.setup()
    const { onCampo } = pintar({ puedeEditar: true, puedeBorrar: false })
    await user.click(screen.getByRole('button', { name: 'Prioridad: Ninguna' }))
    await user.click(screen.getByRole('menuitem', { name: 'Ninguna' }))
    expect(onCampo).not.toHaveBeenCalled()
  })
})
