import { createBrowserRouter, RouterProvider } from 'react-router'
import { AuthProvider } from './components/auth/AuthProvider'
import { GuestOnly, RequireAuth } from './components/auth/RouteGuards'
import { AdminLayout } from './layouts/AdminLayout'
import { ComingSoonPage } from './pages/ComingSoonPage'
import { DashboardPage } from './pages/DashboardPage'
import { EventsPage } from './pages/EventsPage'
import { LoginPage } from './pages/LoginPage'
import { NotFoundPage } from './pages/NotFoundPage'

/** Placeholder routes for modules scheduled in later phases. */
const COMING_SOON_ROUTES = [
  {
    path: 'attendees',
    title: 'Attendees',
    plannedFeatures: [
      'Import attendees from Excel/CSV with dynamic column mapping',
      'Search, view and edit attendee records',
      'Automatic opaque QR token per attendee',
      'Printable attendee QR/ID cards',
    ],
  },
  {
    path: 'registration',
    title: 'Registration',
    plannedFeatures: ['Camera-based QR scanner for check-in', 'Duplicate-scan protection', 'Live registration count'],
  },
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
        {
          element: <AdminLayout />,
          children: [
            { index: true, element: <DashboardPage /> },
            { path: 'events', element: <EventsPage /> },
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
