import { describe, expect, it } from 'vitest'
import { classifyVoices } from '@/lib/voiceAnnouncement'

describe('status suara perangkat', () => {
  it('mengenali suara id-ID', () => {
    const status = classifyVoices([{ name: 'Google US English', lang: 'en-US' }, { name: 'Google Bahasa Indonesia', lang: 'id-ID' }])
    expect(status.level).toBe('indonesian')
    expect(status.name).toBe('Google Bahasa Indonesia')
  })
  it('menerima penulisan id_ID dan kode bahasa id saja', () => {
    expect(classifyVoices([{ name: 'espeak', lang: 'id_ID' }]).level).toBe('indonesian')
    expect(classifyVoices([{ name: 'Indonesian', lang: 'id' }]).level).toBe('indonesian')
  })
  it('memberi peringatan bila hanya ada suara bahasa lain', () => {
    const status = classifyVoices([{ name: 'Google US English', lang: 'en-US' }, { name: 'Malay', lang: 'ms-MY' }])
    expect(status.level).toBe('other')
    expect(status.message).toContain('2 suara lain')
    expect(status.name).toBeNull()
  })
  it('memberi peringatan bila tidak ada suara sama sekali', () => {
    expect(classifyVoices([]).level).toBe('none')
  })
  it('melaporkan peramban tanpa dukungan suara', () => {
    expect(classifyVoices([], false).level).toBe('unsupported')
  })
})
