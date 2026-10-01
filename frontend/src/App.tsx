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
import { NotFoundPage } from './pages/NotFoundPage'
import { QrGeneratorPage } from './pages/QrGeneratorPage'
import { QrPrintPage } from './pages/QrPrintPage'
import { RegistrationPage } from './pages/RegistrationPage'

/** Placeholder routes for modules scheduled in later phases. */
const COMING_SOON_ROUTES = [
  {
    path: 'minor-randomizer',
    title: 'Minor Randomizer',
    plannedFeatures: ['Draw winners from registered attendees', 'Fullscreen event mode', 'Winner history and re-draw rules'],
  },
  {
    path: 'major-randomizer',
    title: 'Major Randomizer',
    plannedFeatures: ['Import Google Form / Google Sheet responses', 'Draw major prize winners', 'Fullscreen event mode'],
  },
  {
    path: 'reports',
    title: 'Reports',
    plannedFeatures: ['Registration and attendance reports', 'Winner reports', 'Audit log viewer and exports'],
  },
  {
    path: 'settings',
    title: 'Settings',
    plannedFeatures: ['User and role management', 'Event-level configuration'],
  },
]

const router = createBrowserRouter(
  [
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
