import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api } from '@/lib/api'
import { resetEcho } from '@/lib/echo'
import type { User } from '@/types/api'

interface AuthContextValue { user: User | null; loading: boolean; login: (email: string, password: string) => Promise<void>; logout: () => Promise<void>; refresh: () => Promise<void> }
const AuthContext = createContext<AuthContextValue | null>(null)

const META_KEY = 'sqms_token_meta'
const CHECK_MS = 2 * 60 * 1000
interface TokenMeta { issued: number; expires: number }

function readMeta(): TokenMeta | null { try { const raw = localStorage.getItem(META_KEY); return raw ? (JSON.parse(raw) as TokenMeta) : null } catch { return null } }
function saveSession(token: string, expiresAt: string | undefined) {
  localStorage.setItem('sqms_token', token)
  if (expiresAt) localStorage.setItem(META_KEY, JSON.stringify({ issued: Date.now(), expires: Date.parse(expiresAt) } satisfies TokenMeta))
  else localStorage.removeItem(META_KEY)
}

let refreshing = false
/**
 * Memperpanjang token bila masa berlakunya tinggal separuh. Selama tab terbuka sesi tetap hidup; bila tab/PC tidur melewati
 * batas kedaluwarsa, permintaan berikutnya mendapat 401 dan pengguna diarahkan ke login dengan pesan "sesi berakhir".
 */
async function refreshIfNeeded() {
  const meta = readMeta()
  if (refreshing || !meta || !localStorage.getItem('sqms_token')) return
  const now = Date.now()
  if (now >= meta.expires || meta.expires - now > (meta.expires - meta.issued) / 2) return
  refreshing = true
  try {
    const { data } = await api.post<{ data: { token: string; expires_at: string } }>('/auth/refresh')
    saveSession(data.data.token, data.data.expires_at) // Echo membaca token terbaru dari localStorage pada otorisasi channel berikutnya
  } catch { /* 401 ditangani interceptor; kegagalan jaringan dicoba lagi pada pengecekan berikutnya */ } finally { refreshing = false }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(() => { const raw = localStorage.getItem('sqms_user'); return raw ? JSON.parse(raw) : null })
  const [loading, setLoading] = useState(Boolean(localStorage.getItem('sqms_token')))
  useEffect(() => { if (!localStorage.getItem('sqms_token')) return setLoading(false); api.get<{ data: User }>('/auth/me').then(({ data }) => { setUser(data.data); localStorage.setItem('sqms_user', JSON.stringify(data.data)) }).catch(() => setUser(null)).finally(() => setLoading(false)) }, [])

  useEffect(() => {
    if (!user) return
    void refreshIfNeeded()
    const timer = window.setInterval(() => { void refreshIfNeeded() }, CHECK_MS)
    const onWake = () => { if (document.visibilityState === 'visible') void refreshIfNeeded() }
    document.addEventListener('visibilitychange', onWake)
    window.addEventListener('focus', onWake)
    return () => { window.clearInterval(timer); document.removeEventListener('visibilitychange', onWake); window.removeEventListener('focus', onWake) }
  }, [user])

  const login = useCallback(async (email: string, password: string) => {
    const { data } = await api.post<{ data: { token: string; expires_at?: string; user: User } }>('/auth/login', { email, password, device_name: 'sqms-web' })
    resetEcho(); saveSession(data.data.token, data.data.expires_at)
    localStorage.setItem('sqms_user', JSON.stringify(data.data.user)); setUser(data.data.user)
  }, [])
  const logout = useCallback(async () => {
    try { await api.post('/auth/logout') } finally { resetEcho(); localStorage.removeItem('sqms_token'); localStorage.removeItem('sqms_user'); localStorage.removeItem(META_KEY); setUser(null) }
  }, [])
  const refresh = useCallback(async () => {
    const { data } = await api.get<{ data: User }>('/auth/me')
    setUser(data.data); localStorage.setItem('sqms_user', JSON.stringify(data.data))
  }, [])
  const value = useMemo<AuthContextValue>(() => ({ user, loading, login, logout, refresh }), [user, loading, login, logout, refresh])
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
export function useAuth() { const value = useContext(AuthContext); if (!value) throw new Error('useAuth harus digunakan di dalam AuthProvider'); return value }
