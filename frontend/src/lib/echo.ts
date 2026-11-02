import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

declare global { interface Window { Pusher: typeof Pusher } }
window.Pusher = Pusher

export const STAFF_TOKEN_KEY = 'sqms_token'
export const DISPLAY_TOKEN_KEY = 'sqms_display_token'

const instances = new Map<string, Echo<'reverb'>>()

/** Echo dibuat saat pertama dipakai; token (staf, display) dibaca dari localStorage pada setiap otorisasi channel. */
export function getEcho(tokenKey: string = STAFF_TOKEN_KEY) {
  let instance = instances.get(tokenKey)
  if (!instance) {
    const options = {
      broadcaster: 'reverb',
      key: import.meta.env.VITE_REVERB_APP_KEY ?? 'sqms-local-key',
      wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
      wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
      wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
      forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
      enabledTransports: ['ws', 'wss'],
      // Harus lewat `channelAuthorization` (bukan `auth`): pusher-js hanya menyalin `params`/`headers` dari opsi `auth` lama dan
      // mengabaikan `headersProvider`. Penyedia header dievaluasi pada setiap otorisasi channel, sehingga token hasil
      // perpanjangan langsung terpakai tanpa memutus koneksi.
      channelAuthorization: {
        transport: 'ajax',
        endpoint: '/broadcasting/auth',
        headersProvider: () => ({ Authorization: `Bearer ${localStorage.getItem(tokenKey) ?? ''}`, Accept: 'application/json' }),
      },
    }
    instance = new Echo(options as unknown as ConstructorParameters<typeof Echo<'reverb'>>[0])
    instances.set(tokenKey, instance)
  }
  return instance
}

/** Dipanggil saat login/logout/refresh token supaya koneksi lama (dengan token lama) tidak dipakai lagi. */
export function resetEcho(tokenKey: string = STAFF_TOKEN_KEY) {
  instances.get(tokenKey)?.disconnect()
  instances.delete(tokenKey)
}
