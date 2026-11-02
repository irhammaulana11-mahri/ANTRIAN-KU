import { useCallback, useEffect, useRef, useState } from 'react'
import { callFromEvent, isExpired, type DisplayCall } from '@/lib/announcement'
import { activateDisplay, clearDisplaySession, displayToken, fetchSnapshot, refreshDisplayToken, type DisplaySnapshot } from '@/lib/displayApi'
import { DisplayEventGuard } from '@/lib/displayEventGuard'
import { DISPLAY_TOKEN_KEY, getEcho } from '@/lib/echo'
import type { RealtimeEvent } from '@/types/realtime'

export type DisplayPhase = 'checking' | 'unpaired' | 'ready'
type PusherConnection = { bind: (event: string, handler: () => void) => void; unbind: (event: string, handler: () => void) => void }

const CALL_EVENTS = ['queue.called', 'queue.recalled']
const REFRESH_EVENTS = ['queue.created', 'queue.assigned', 'queue.started', 'queue.completed', 'queue.transferred', 'queue.status-changed', 'display.updated']
const POLL_MS = 30_000
const TOKEN_REFRESH_MS = 12 * 60 * 60 * 1000

/**
 * Sumber data Central Display: snapshot REST (sumber kebenaran) + event WebSocket (kecepatan).
 * Snapshot tidak pernah memicu suara; hanya event baru yang belum kedaluwarsa yang diumumkan.
 */
export function useDisplayFeed(onNewCall: (call: DisplayCall, speakable: boolean) => void) {
  const [phase, setPhase] = useState<DisplayPhase>(displayToken() ? 'checking' : 'unpaired')
  const [error, setError] = useState('')
  const [snapshot, setSnapshot] = useState<DisplaySnapshot | null>(null)
  const [calls, setCalls] = useState<DisplayCall[]>([])
  const [connected, setConnected] = useState(false)
  const [epoch, setEpoch] = useState(0)
  const guard = useRef(new DisplayEventGuard())
  const clockOffset = useRef(0)
  const expiry = useRef(60)
  const callback = useRef(onNewCall)
  callback.current = onNewCall
  const reloadTimer = useRef<number | undefined>(undefined)

  const load = useCallback(async () => {
    try {
      const data = await fetchSnapshot()
      clockOffset.current = Date.parse(data.server_time) - Date.now()
      expiry.current = data.settings.call_expiry_seconds
      setSnapshot(data)
      setCalls(data.calls)
      setPhase('ready')
      setError('')
    } catch (failure) {
      const status = (failure as { response?: { status?: number } }).response?.status
      if (status === 401 || status === 403) {
        clearDisplaySession()
        setSnapshot(null)
        setPhase('unpaired')
        setError('Sesi perangkat berakhir atau perangkat dinonaktifkan. Masukkan kunci pairing baru.')
      } else {
        setPhase((current) => (current === 'checking' ? 'ready' : current)) // tetap tampilkan layar; poll berikutnya akan mencoba lagi
      }
    }
  }, [])

  const scheduleReload = useCallback(() => {
    window.clearTimeout(reloadTimer.current)
    reloadTimer.current = window.setTimeout(() => { void load() }, 500)
  }, [load])

  const pair = useCallback(async (credential: string) => {
    setError('')
    try {
      await activateDisplay(credential)
      setEpoch((value) => value + 1)
      setPhase('checking')
    } catch (failure) {
      setError((failure as { response?: { data?: { message?: string } } }).response?.data?.message ?? (failure as Error).message ?? 'Aktivasi gagal. Periksa kunci pairing dan koneksi.')
    }
  }, [])

  const unpair = useCallback(() => { clearDisplaySession(); setSnapshot(null); setCalls([]); setPhase('unpaired') }, [])

  // Muat snapshot saat sudah punya token, lalu poll sebagai jaring pengaman bila WebSocket terputus.
  useEffect(() => {
    if (!displayToken()) return
    void load()
    const poll = window.setInterval(() => { void load() }, POLL_MS)
    return () => window.clearInterval(poll)
  }, [load, epoch])

  // Perpanjang token berkala (token perangkat berumur 7 hari).
  useEffect(() => {
    if (phase !== 'ready') return
    const timer = window.setInterval(() => { refreshDisplayToken().then(() => setEpoch((value) => value + 1)).catch(() => undefined) }, TOKEN_REFRESH_MS)
    return () => window.clearInterval(timer)
  }, [phase])

  const branchId = snapshot?.branch.id
  useEffect(() => {
    if (!branchId || !localStorage.getItem(DISPLAY_TOKEN_KEY)) return
    const echo = getEcho(DISPLAY_TOKEN_KEY)
    const channel = echo.private(`branch.${branchId}`)
    CALL_EVENTS.forEach((name) => channel.listen(`.${name}`, (event: RealtimeEvent) => {
      if (!guard.current.accept(event)) return // duplikat / urutan terbalik
      const call = callFromEvent({ ...event, event_name: name })
      if (!call) return
      setCalls((previous) => [call, ...previous.filter((item) => item.event_id !== call.event_id)].slice(0, 40))
      callback.current(call, !isExpired(event.occurred_at, Date.now() + clockOffset.current, expiry.current))
      scheduleReload()
    }))
    REFRESH_EVENTS.forEach((name) => channel.listen(`.${name}`, scheduleReload))
    const connection = (echo.connector as unknown as { pusher?: { connection?: PusherConnection } }).pusher?.connection
    const onUp = () => { setConnected(true); void load() } // pulihkan state yang terlewat selama terputus
    const onDown = () => setConnected(false)
    connection?.bind('connected', onUp)
    ;['disconnected', 'unavailable', 'failed'].forEach((name) => connection?.bind(name, onDown))
    return () => {
      connection?.unbind('connected', onUp)
      ;['disconnected', 'unavailable', 'failed'].forEach((name) => connection?.unbind(name, onDown))
      echo.leave(`branch.${branchId}`)
      window.clearTimeout(reloadTimer.current)
    }
  }, [branchId, epoch, load, scheduleReload])

  return { phase, error, snapshot, calls, connected, pair, unpair }
}
