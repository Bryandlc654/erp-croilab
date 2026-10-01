import { BrowserRouter, Routes, Route } from 'react-router-dom'
import TasksApp from './pages/TasksApp'

function App() {
  return (
    <BrowserRouter basename="/admin/tareas-react">
      <Routes>
        <Route path="/*" element={<TasksApp />} />
      </Routes>
    </BrowserRouter>
  )
}

export default App
