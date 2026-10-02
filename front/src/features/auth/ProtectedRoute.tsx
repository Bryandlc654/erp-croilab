import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from './useAuth'
import Cargando from '../../shared/ui/Cargando'

export default function ProtectedRoute() {
  const { me, loading } = useAuth()
  const location = useLocation()
  if (loading) return <Cargando />
  // Guarda adónde iba para volver ahí después de iniciar sesión.
  if (!me) return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />
  return <Outlet />
}
