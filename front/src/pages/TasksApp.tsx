import { useState } from 'react'

export default function TasksApp() {
  const [msg] = useState('Módulo Tareas (React) — en desarrollo')
  return (
    <div style={{ padding: 24 }}>
      <h1>Tareas</h1>
      <p>{msg}</p>
    </div>
  )
}
