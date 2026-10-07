export default function Cargando({ texto = 'Cargando...' }: { texto?: string }) {
  return (
    <div className="flex items-center gap-2 text-gray-600 py-2">
      <div className="h-4 w-4 animate-spin rounded-full border-2 border-gray-300 border-t-blue-600" />
      <span>{texto}</span>
    </div>
  )
}
