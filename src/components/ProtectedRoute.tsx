import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'

export default function ProtectedRoute() {
  const { me, loading } = useAuth()
  if (loading) return <div style={{ padding:24 }}>Cargando...</div>
  if (!me) return <Navigate to="/login" replace />
  return <Outlet />
}
