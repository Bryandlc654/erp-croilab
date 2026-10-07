import { Toaster } from 'react-hot-toast'

export default function Toast() {
  return (
    <Toaster
      position="top-right"
      toastOptions={{
        duration: 2500,
        className: 'text-sm',
        success: { className: 'bg-green-600 text-white' },
        error: { className: 'bg-red-600 text-white' },
      }}
    />
  )
}
