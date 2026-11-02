import type { User } from '@/types/api'

export const can = (user: User | null | undefined, permission: string) => Boolean(user?.permissions?.includes(permission))
export const hasRole = (user: User | null | undefined, ...roles: string[]) => Boolean(user?.roles?.some((role) => roles.includes(role)))
/** Petugas lapangan (CS/Teller) langsung bekerja di ruang kerja petugas, bukan dashboard manajemen. */
export const isOperator = (user: User | null | undefined) => can(user, 'queues.call') && !can(user, 'reports.view') && !can(user, 'queues.assign')
