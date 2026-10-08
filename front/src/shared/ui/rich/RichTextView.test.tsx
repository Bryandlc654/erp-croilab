import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import RichTextView from './RichTextView'

describe('RichTextView', () => {
  it('pinta el formato con elementos, sin HTML del usuario', () => {
    const { container } = render(<RichTextView value={'# Hola\n**negrita** <script>alert(1)</script> <b>no</b>\n- uno\n| a | b |\n| --- | --- |\n| 1 | 2 |'} />)
    expect(container.querySelector('h1')?.textContent).toBe('Hola')
    expect(container.querySelector('b')?.textContent).toBe('negrita')
    expect(container.querySelector('script')).toBeNull()
    expect(container.textContent).toContain('<script>alert(1)</script>')
    expect(container.querySelectorAll('li')).toHaveLength(1)
    expect(container.querySelectorAll('th')).toHaveLength(2)
  })

  it('enlaces solo http(s) con rel="noopener noreferrer"', () => {
    render(<RichTextView value={'[web](https://a.com) [mal](javascript:alert(1))'} />)
    const a = screen.getByRole('link', { name: 'web' })
    expect(a).toHaveAttribute('href', 'https://a.com')
    expect(a).toHaveAttribute('rel', 'noopener noreferrer')
    expect(screen.queryByRole('link', { name: 'mal' })).toBeNull()
  })

  it('menciones conocidas con avatar, huecos de imagen y casillas', () => {
    const onChange = vi.fn()
    const { container } = render(
      <RichTextView value={'@laura y @nadie\n[[img]]\n[[chk:0]] tarea'} people={[{ id: 1, username: 'laura' }]} imageSlots={['/f/1.png']} onChange={onChange} />,
    )
    expect(container.querySelectorAll('.rt-mention')).toHaveLength(2)
    expect(container.querySelector('img.rt-img')).toHaveAttribute('src', '/f/1.png')
    fireEvent.click(screen.getByRole('checkbox'))
    expect(onChange).toHaveBeenCalledWith('@laura y @nadie\n[[img]]\n[[chk:1]] tarea')
  })

  it('[[img:…]] con nombre raro no se pinta', () => {
    const { container } = render(<RichTextView value={'[[img:../../etc/passwd]] ok'} resolveFileUrl={(fn) => '/f/' + fn} />)
    expect(container.querySelector('img')).toBeNull()
  })
})
