export interface SavedTicket { token: string; queue_number: string; branch_name?: string; saved_at: string }
const KEY = 'sqms_last_ticket'

/** Menyimpan tiket terakhir di perangkat agar pelanggan dapat kembali melacak tanpa menyalin token. */
export function saveTicket(ticket: SavedTicket) { try { localStorage.setItem(KEY, JSON.stringify(ticket)) } catch { /* penyimpanan tidak tersedia */ } }
export function loadTicket(): SavedTicket | null { try { const raw = localStorage.getItem(KEY); return raw ? (JSON.parse(raw) as SavedTicket) : null } catch { return null } }
export function clearTicket() { try { localStorage.removeItem(KEY) } catch { /* abaikan */ } }

export function formatWait(seconds: number | null | undefined): string {
  if (seconds == null) return 'Belum dapat diperkirakan'
  if (seconds <= 0) return 'Segera'
  const minutes = Math.max(1, Math.round(seconds / 60))
  return minutes >= 60 ? `±${Math.floor(minutes / 60)} jam ${minutes % 60} menit` : `±${minutes} menit`
}
