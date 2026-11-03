import type { ReactNode } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout } from '@/components/layout/AppLayout'
import { useAuth } from '@/context/AuthContext'
import { Loading } from '@/components/Feedback'
import { LoginPage } from '@/pages/LoginPage'
import { DashboardPage } from '@/pages/DashboardPage'
import { MasterDataPage } from '@/pages/master/MasterDataPage'
import { branchesConfig, countersConfig, employeesConfig, servicesConfig, unitsConfig } from '@/pages/master/configs'
import { QueueMonitoringPage } from '@/pages/QueuePages'
import { CustomerRegistrationPage } from '@/pages/RegistrationPage'
import { CustomerTrackingPage } from '@/pages/TrackingPage'
import { OperatorPage } from '@/pages/OperatorPage'
import { DisplayDevicesPage } from '@/pages/DisplayDevicesPage'
import { KioskPage } from '@/pages/KioskPage'
import { SupervisorPage } from '@/pages/SupervisorPage'
import { QueueSettingsPage } from '@/pages/QueueSettingsPage'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { can, isOperator } from '@/lib/access'
import { CentralDisplayRealtimePage } from '@/pages/CentralDisplayRealtimePage'
import { ReportsPage } from '@/pages/ReportsPage'
import { ProfilePage } from '@/pages/ProfilePage'

function Protected() { const { user, loading } = useAuth(); if (loading) return <Loading />; return user ? <AppLayout /> : <Navigate to="/login" replace /> }
/** Menolak akses halaman bila user tidak punya permission; backend tetap menjadi penjaga utama. */
function Guard({ permission, children }: { permission: string; children: ReactNode }) { const { user } = useAuth(); return can(user, permission) ? <>{children}</> : <Navigate to="/" replace /> }
/** Halaman TV/kiosk berjalan tanpa pengawasan: bila terjadi galat, muat ulang otomatis setelah 15 detik. */
function ScreenBoundary({ children }: { children: ReactNode }) {
  return <ErrorBoundary fallback={() => { window.setTimeout(() => window.location.reload(), 15_000); return <main className="grid min-h-screen place-items-center bg-slate-950 p-6 text-center text-slate-300"><div><p className="text-xl font-bold text-white">Tampilan sedang dimuat ulang…</p><p className="mt-2 text-sm">Terjadi gangguan sesaat. Halaman akan dimuat ulang otomatis.</p></div></main> }}>{children}</ErrorBoundary>
}
function Home() { const { user } = useAuth(); return isOperator(user) ? <Navigate to="/operator" replace /> : <DashboardPage /> }
export function App() {
  return <Routes>
    <Route path="/login" element={<LoginPage />} />
    <Route path="/display" element={<ScreenBoundary><CentralDisplayRealtimePage /></ScreenBoundary>} />
    <Route path="/kiosk" element={<ScreenBoundary><KioskPage /></ScreenBoundary>} />
    <Route path="/register" element={<CustomerRegistrationPage />} />
    <Route path="/tracking" element={<CustomerTrackingPage />} />
    <Route element={<Protected />}>
      <Route index element={<Home />} />
      <Route path="branches" element={<Guard permission="branches.manage"><MasterDataPage config={branchesConfig} /></Guard>} />
      <Route path="units" element={<Guard permission="units.view"><MasterDataPage config={unitsConfig} /></Guard>} />
      <Route path="employees" element={<Guard permission="employees.view"><MasterDataPage config={employeesConfig} /></Guard>} />
      <Route path="counters" element={<Guard permission="counters.view"><MasterDataPage config={countersConfig} /></Guard>} />
      <Route path="services" element={<Guard permission="services.view"><MasterDataPage config={servicesConfig} /></Guard>} />
      <Route path="queues" element={<Guard permission="queues.view"><QueueMonitoringPage /></Guard>} />
      <Route path="supervisor" element={<Guard permission="queues.assign"><SupervisorPage /></Guard>} />
      <Route path="operator" element={<Guard permission="queues.call"><OperatorPage /></Guard>} />
      <Route path="display-devices" element={<Guard permission="displays.manage"><DisplayDevicesPage /></Guard>} />
      <Route path="queue-settings" element={<Guard permission="settings.manage"><QueueSettingsPage /></Guard>} />
      <Route path="reports" element={<Guard permission="reports.view"><ReportsPage /></Guard>} />
      <Route path="profile" element={<ProfilePage />} />
    </Route>
    <Route path="*" element={<Navigate to="/" replace />} />
  </Routes>
}
