import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider } from './contexts/AuthContext'
import ProtectedRoute from './components/ProtectedRoute'
import Login from './pages/Login'
import TasksApp from './pages/TasksApp'

function App() {
  return (
    <AuthProvider>
      <BrowserRouter basename="/admin/tareas-react">
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route element={<ProtectedRoute />}>
            <Route path="/tareas" element={<TasksApp />} />
            <Route path="/" element={<Navigate to="/tareas" replace />} />
            <Route path="*" element={<Navigate to="/tareas" replace />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
