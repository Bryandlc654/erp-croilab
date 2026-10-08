import { useNavigate } from 'react-router-dom'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { mensajeError, useConvertir } from '../api'

/* «Convertir en cliente» (ficha, menú de fila y negocio): confirma, crea el
   cliente con el servicio de Clientes y enseña UNA vez el usuario y la
   contraseña del portal (no se vuelve a mostrar ni se guarda en claro). */
export function useConvertirCliente() {
  const conv = useConvertir()
  const { confirm, alert } = useConfirm()
  const { aviso } = useToast()
  const navigate = useNavigate()

  return async (d: { contacto?: number; negocio?: number }) => {
    const ok = await confirm({
      title: '¿Convertir en cliente?',
      message: 'Se crea la ficha con sus datos de facturación y sus listas por defecto. El contacto seguirá en el CRM, enlazado al cliente nuevo.',
      okLabel: 'Convertir',
    })
    if (!ok) return
    try {
      const r = await conv.mutateAsync(d)
      if (r.ya) {
        aviso(r.msg)
        navigate(`/clientes/${r.cliente_id}`)
        return
      }
      await alert({
        title: r.msg,
        message: `Usuario: ${r.usuario ?? ''}\nContraseña: ${r.password ?? ''}\n\nApúntala ahora: no se vuelve a mostrar. Puedes cambiarla desde la ficha del cliente.`,
        okLabel: 'Abrir la ficha',
      })
      navigate(`/clientes/${r.cliente_id}`)
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido crear el cliente.'), { tipo: 'error' })
    }
  }
}
