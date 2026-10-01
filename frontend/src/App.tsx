import { createBrowserRouter, RouterProvider } from 'react-router'
import { AuthProvider } from './components/auth/AuthProvider'
import { GuestOnly, RequireAuth } from './components/auth/RouteGuards'
import { AdminLayout } from './layouts/AdminLayout'
import { AttendeeImportPage } from './pages/AttendeeImportPage'
import { AttendeesPage } from './pages/AttendeesPage'
import { ComingSoonPage } from './pages/ComingSoonPage'
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

/** Placeholder routes for modules scheduled in later phases. */
const COMING_SOON_ROUTES = [
  {
    path: 'settings',
    title: 'Settings',
    plannedFeatures: ['User and role management', 'Event-level configuration'],
  },
]

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
        { path: '/print/qr', element: <QrPrintPage /> },
        {
          element: <AdminLayout />,
          children: [
            { index: true, element: <DashboardPage /> },
            { path: 'events', element: <EventsPage /> },
            { path: 'attendees', element: <AttendeesPage /> },
            { path: 'attendees/import', element: <AttendeeImportPage /> },
            { path: 'qr-codes', element: <QrGeneratorPage /> },
            { path: 'registration', element: <RegistrationPage /> },
            { path: 'minor-randomizer', element: <RandomizerPage key="minor" type="minor" /> },
            { path: 'major-eligibility', element: <MajorEligibilityPage /> },
            { path: 'major-eligibility/import', element: <MajorImportPage /> },
            { path: 'major-randomizer', element: <RandomizerPage key="major" type="major" /> },
            { path: 'major-qr', element: <MajorQrPage /> },
            { path: 'reports', element: <ReportsPage /> },
            ...COMING_SOON_ROUTES.map(({ path, title, plannedFeatures }) => ({
              path,
              element: <ComingSoonPage title={title} plannedFeatures={plannedFeatures} />,
            })),
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
