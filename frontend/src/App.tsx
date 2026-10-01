import { createBrowserRouter, RouterProvider } from 'react-router'
import { AuthProvider } from './components/auth/AuthProvider'
import { GuestOnly, RequireAuth, RequireRole } from './components/auth/RouteGuards'
import { AdminLayout } from './layouts/AdminLayout'
import { RANDOMIZER_ROLES, SCANNER_ROLES, STAFF_ROLES } from './layouts/navigation'
import { AttendeeImportPage } from './pages/AttendeeImportPage'
import { AttendeesPage } from './pages/AttendeesPage'
import { DashboardPage } from './pages/DashboardPage'
import { EventsPage } from './pages/EventsPage'
import { LoginPage } from './pages/LoginPage'
import { MajorEligibilityPage } from './pages/MajorEligibilityPage'
import { MajorFormRedirectPage } from './pages/MajorFormRedirectPage'
import { MajorImportPage } from './pages/MajorImportPage'
import { MajorQrPage } from './pages/MajorQrPage'
import { NotFoundPage } from './pages/NotFoundPage'
import { QrGeneratorPage } from './pages/QrGeneratorPage'
import { QrPrintPage } from './pages/QrPrintPage'
import { RandomizerPage } from './pages/RandomizerPage'
import { RegistrationPage } from './pages/RegistrationPage'
import { ReportsPage } from './pages/ReportsPage'
import { SettingsPage } from './pages/SettingsPage'

const router = createBrowserRouter(
  [
    // Public: target of the Major QR shown on the LED screen.
    { path: '/major-form', element: <MajorFormRedirectPage /> },
    {
      element: <GuestOnly />,
      children: [{ path: '/login', element: <LoginPage /> }],
    },
    {
      element: <RequireAuth />,
      children: [
        // Print sheet: full page, no sidebar/top bar.
        {
          element: <RequireRole roles={['admin', 'registration_staff']} />,
          children: [{ path: '/print/qr', element: <QrPrintPage /> }],
        },
        {
          element: <AdminLayout />,
          children: [
            {
              element: <RequireRole roles={STAFF_ROLES} />,
              children: [
                { index: true, element: <DashboardPage /> },
                { path: 'events', element: <EventsPage /> },
                { path: 'attendees', element: <AttendeesPage /> },
                { path: 'attendees/import', element: <AttendeeImportPage /> },
              ],
            },
            {
              element: <RequireRole roles={['admin', 'registration_staff']} />,
              children: [{ path: 'qr-codes', element: <QrGeneratorPage /> }],
            },
            {
              element: <RequireRole roles={SCANNER_ROLES} />,
              children: [{ path: 'registration', element: <RegistrationPage /> }],
            },
            {
              element: <RequireRole roles={RANDOMIZER_ROLES} />,
              children: [
                { path: 'minor-randomizer', element: <RandomizerPage key="minor" type="minor" /> },
                { path: 'major-eligibility', element: <MajorEligibilityPage /> },
                { path: 'major-eligibility/import', element: <MajorImportPage /> },
                { path: 'major-randomizer', element: <RandomizerPage key="major" type="major" /> },
                { path: 'major-qr', element: <MajorQrPage /> },
              ],
            },
            {
              element: <RequireRole roles={['admin']} />,
              children: [
                { path: 'reports', element: <ReportsPage /> },
                { path: 'settings', element: <SettingsPage /> },
              ],
            },
            { path: '*', element: <NotFoundPage /> },
          ],
        },
      ],
    },
  ],
  { basename: import.meta.env.BASE_URL.replace(/\/+$/, '') || '/' },
)

export function App() {
  return (
    <AuthProvider>
      <RouterProvider router={router} />
    </AuthProvider>
  )
}
