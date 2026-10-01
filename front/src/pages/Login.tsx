import { useState } from 'react'
import { useNavigate, Navigate } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'

export default function Login() {
  const { me, login } = useAuth()
  const [u, setU] = useState('')
  const [p, setP] = useState('')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const nav = useNavigate()

  if (me) return <Navigate to="/tareas" replace />

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr('')
    const r = await login(u, p)
    setBusy(false)
    if (r.ok) nav('/tareas')
    else setErr(r.msg || 'Error')
  }

  return (
    <div style={{ display:'flex', alignItems:'center', justifyContent:'center', minHeight:'100vh', background:'#f6f7f9' }}>
      <form onSubmit={onSubmit} style={{ background:'#fff', border:1, borderColor:'#e5e7eb', borderStyle:'solid', borderRadius:12, padding:32, width:'100%', maxWidth:360, boxShadow:'0 10px 60px -20px rgba(0,0,0,.3)' }}>
        <h1 style={{ margin:0, marginBottom:24, fontSize:20 }}>Acceder</h1>
        <div style={{ display:'flex', flexDirection:'column', gap:4, marginBottom:16 }}>
          <label style={{ fontSize:14, color:'#374151' }}>Usuario</label>
          <input value={u} onChange={e=>setU(e.target.value)} autoComplete="username" style={{ padding:'10px 12px', border:'1px solid #e5e7eb', borderRadius:10 }} required />
        </div>
        <div style={{ display:'flex', flexDirection:'column', gap:4, marginBottom:20 }}>
          <label style={{ fontSize:14, color:'#374151' }}>Contraseña</label>
          <input type="password" value={p} onChange={e=>setP(e.target.value)} autoComplete="current-password" style={{ padding:'10px 12px', border:'1px solid #e5e7eb', borderRadius:10 }} required />
        </div>
        {err && <div style={{ color:'#b91c1c', marginBottom:12, fontSize:14 }}>{err}</div>}
        <button disabled={busy} type="submit" style={{ width:'100%', background:'#4f46e5', color:'#fff', border:'none', borderRadius:10, padding:'10px 12px', cursor:'pointer' }}>
          {busy ? 'Entrando...' : 'Entrar'}
        </button>
      </form>
    </div>
  )
}
