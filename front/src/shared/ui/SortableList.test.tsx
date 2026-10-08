import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import SortableList, { RowGrip } from './SortableList'

function Lista({ onReorder }: { onReorder: (ids: (string | number)[]) => void }) {
  const [items, setItems] = useState(['Diseño', 'Copy', 'Desarrollo'])
  return (
    <SortableList
      aria-label="Fases"
      items={items}
      getId={(x) => x}
      onReorder={(ids) => {
        setItems(ids as string[])
        onReorder(ids)
      }}
      renderItem={(x, { handleProps }) => (
        <div>
          <RowGrip {...handleProps} aria-label={`Mover ${x}`} />
          {x}
        </div>
      )}
    />
  )
}

describe('<SortableList>', () => {
  it('con el teclado, las flechas sobre el asa mueven el elemento', async () => {
    const user = userEvent.setup()
    const onReorder = vi.fn()
    render(<Lista onReorder={onReorder} />)
    screen.getByRole('button', { name: 'Mover Diseño' }).focus()
    await user.keyboard('{ArrowDown}')
    expect(onReorder).toHaveBeenLastCalledWith(['Copy', 'Diseño', 'Desarrollo'])
    expect(screen.getAllByRole('listitem').map((li) => li.textContent)).toEqual(['Copy', 'Diseño', 'Desarrollo'])
  })

  it('en los extremos no hace nada', async () => {
    const user = userEvent.setup()
    const onReorder = vi.fn()
    render(<Lista onReorder={onReorder} />)
    screen.getByRole('button', { name: 'Mover Diseño' }).focus()
    await user.keyboard('{ArrowUp}')
    expect(onReorder).not.toHaveBeenCalled()
  })
})
