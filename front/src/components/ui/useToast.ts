import toast from 'react-hot-toast'

export const useToast = () => ({
  ok: (m: string) => toast.success(m),
  err: (m: string) => toast.error(m),
  info: (m: string) => toast(m),
})
