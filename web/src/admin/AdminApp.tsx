import './admin.css'
import { QueryClientProvider, type QueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Route, Routes } from 'react-router'
import { createAdminQueryClient } from './api/queryClient'
import { AuthProvider } from './auth/AuthProvider'
import { GuestOnly, RequireAbility, RequireAuth } from './auth/guards'
import { ToastProvider } from './components/toast/ToastProvider'
import { AdminLayout } from './layout/AdminLayout'
import { AuditPage } from './pages/AuditPage'
import { ForgotPasswordPage } from './pages/auth/ForgotPasswordPage'
import { LoginPage } from './pages/auth/LoginPage'
import { ResetPasswordPage } from './pages/auth/ResetPasswordPage'
import { HomePage } from './pages/HomePage'
import { MembersPage } from './pages/MembersPage'
import { NotFoundPage } from './pages/NotFoundPage'
import { QrCodePage } from './pages/QrCodePage'
import { ReportDetailPage } from './pages/reports/ReportDetailPage'
import { ReportsPage } from './pages/reports/ReportsPage'
import { SettingsPage } from './pages/settings/SettingsPage'
import { UsersPage } from './pages/users/UsersPage'
import { VisitorDetailPage } from './pages/visitors/VisitorDetailPage'
import { VisitorsPage } from './pages/visitors/VisitorsPage'

/**
 * Dashboard d'administration, monté sous /admin/* (voir src/main.tsx).
 * Les routes ci-dessous sont relatives à /admin.
 */
export default function AdminApp({ queryClient }: { queryClient?: QueryClient }) {
  const [client] = useState(() => queryClient ?? createAdminQueryClient())
  return (
    <QueryClientProvider client={client}>
      <ToastProvider>
        <AuthProvider>
          <Routes>
            <Route
              path="connexion"
              element={
                <GuestOnly>
                  <LoginPage />
                </GuestOnly>
              }
            />
            <Route path="mot-de-passe-oublie" element={<ForgotPasswordPage />} />
            <Route path="reinitialiser" element={<ResetPasswordPage />} />
            <Route
              element={
                <RequireAuth>
                  <AdminLayout />
                </RequireAuth>
              }
            >
              <Route index element={<HomePage />} />
              <Route
                path="visiteurs"
                element={
                  <RequireAbility ability="visitors.view">
                    <VisitorsPage />
                  </RequireAbility>
                }
              />
              <Route
                path="visiteurs/:id"
                element={
                  <RequireAbility ability="visitors.view">
                    <VisitorDetailPage />
                  </RequireAbility>
                }
              />
              <Route
                path="membres"
                element={
                  <RequireAbility ability="visitors.view">
                    <MembersPage />
                  </RequireAbility>
                }
              />
              <Route
                path="rapports"
                element={
                  <RequireAbility ability="visitors.view">
                    <ReportsPage />
                  </RequireAbility>
                }
              />
              <Route
                path="rapports/:year/:month"
                element={
                  <RequireAbility ability="visitors.view">
                    <ReportDetailPage />
                  </RequireAbility>
                }
              />
              <Route
                path="administrateurs"
                element={
                  <RequireAbility ability="users.manage">
                    <UsersPage />
                  </RequireAbility>
                }
              />
              <Route path="parametres" element={<SettingsPage />} />
              <Route
                path="qrcode"
                element={
                  <RequireAbility ability="visitors.view">
                    <QrCodePage />
                  </RequireAbility>
                }
              />
              <Route
                path="journal"
                element={
                  <RequireAbility ability="audit.view">
                    <AuditPage />
                  </RequireAbility>
                }
              />
              <Route path="*" element={<NotFoundPage />} />
            </Route>
          </Routes>
        </AuthProvider>
      </ToastProvider>
    </QueryClientProvider>
  )
}
