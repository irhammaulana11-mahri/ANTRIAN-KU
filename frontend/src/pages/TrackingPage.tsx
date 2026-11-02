import { useState } from 'react'
import { Search } from 'lucide-react'
import { PublicShell } from '@/components/layout/PublicShell'
import { TrackingStatus } from '@/components/TrackingStatus'
import { Card } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Field'
import { loadTicket } from '@/lib/ticket'

function initialToken() {
  const params = new URLSearchParams(window.location.search)
  const fromHash = new URLSearchParams(window.location.hash.slice(1)).get('token')
  return params.get('token') ?? fromHash ?? loadTicket()?.token ?? ''
}

export function CustomerTrackingPage() {
  const [input, setInput] = useState(initialToken)
  const [token, setToken] = useState(() => (initialToken().length >= 32 ? initialToken() : ''))
  return <PublicShell>
    <h1 className="text-2xl font-black sm:text-3xl">Lacak Antrian</h1>
    <p className="mt-1 mb-6 text-sm text-slate-500">Masukkan token dari tiket Anda. Tiket terakhir di perangkat ini terisi otomatis.</p>
    <Card>
      <form className="flex gap-3" onSubmit={(event) => { event.preventDefault(); setToken(input.trim()) }}>
        <Input value={input} onChange={(event) => setInput(event.target.value)} placeholder="Tempel token antrian" aria-label="Token antrian" />
        <Button type="submit" disabled={input.trim().length < 32}><Search size={17} className="mr-2" />Lacak</Button>
      </form>
      {token && <div className="mt-8"><TrackingStatus token={token} /></div>}
    </Card>
  </PublicShell>
}
