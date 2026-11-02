import { describe, expect, it } from 'vitest'
import { fieldError } from '@/lib/api'

describe('fieldError', () => {
  const axiosError = { response: { data: { errors: { 'display_media.0.url': ['Link YouTube tidak dikenali.'], reason: ['Wajib diisi'] } } } }
  it('mengambil pesan pertama untuk field yang gagal', () => {
    expect(fieldError(axiosError, 'display_media.0.url')).toBe('Link YouTube tidak dikenali.')
    expect(fieldError(axiosError, 'reason')).toBe('Wajib diisi')
  })
  it('mengembalikan undefined untuk field yang tidak bermasalah', () => {
    expect(fieldError(axiosError, 'display_media.1.url')).toBeUndefined()
  })
  it('TIDAK melempar saat belum ada galat (null/undefined) — regresi layar putih /display-devices', () => {
    expect(() => fieldError(null, 'x')).not.toThrow()
    expect(fieldError(null, 'x')).toBeUndefined()
    expect(fieldError(undefined, 'x')).toBeUndefined()
  })
  it('tidak melempar untuk galat non-axios atau tanpa body validasi', () => {
    expect(fieldError(new Error('jaringan putus'), 'x')).toBeUndefined()
    expect(fieldError({ response: { data: { message: '500' } } }, 'x')).toBeUndefined()
    expect(fieldError({ response: null }, 'x')).toBeUndefined()
    expect(fieldError('teks', 'x')).toBeUndefined()
  })
})
