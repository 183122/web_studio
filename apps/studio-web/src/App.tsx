import { lazy, Suspense } from 'react';
import { createBrowserRouter, Navigate, RouterProvider } from 'react-router';
import { GuestOnly, RequireAuth } from './lib/auth.tsx';
import { FullPageSpinner } from './components/ui/misc.tsx';
import Login from './pages/auth/Login.tsx';
import Register from './pages/auth/Register.tsx';
import { ForgotPassword, ResetPassword, VerifyEmailResult } from './pages/auth/PasswordPages.tsx';
import Dashboard from './pages/Dashboard.tsx';

const Editor = lazy(() => import('./editor/Editor.tsx'));
const Preview = lazy(() => import('./pages/Preview.tsx'));
const DataAdmin = lazy(() => import('./pages/DataAdmin.tsx'));
const Team = lazy(() => import('./pages/Team.tsx'));
const AcceptInvite = lazy(() => import('./pages/AcceptInvite.tsx'));
const AdminConsole = lazy(() => import('./pages/AdminConsole.tsx'));
const ReportAbuse = lazy(() => import('./pages/ReportAbuse.tsx'));
const Billing = lazy(() => import('./pages/Billing.tsx'));
const TestCheckout = lazy(() => import('./pages/TestCheckout.tsx'));
const Integrations = lazy(() => import('./pages/Integrations.tsx'));
const AdminSettings = lazy(() => import('./pages/AdminSettings.tsx'));
const SharePreview = lazy(() => import('./pages/SharePreview.tsx'));

const router = createBrowserRouter([
  { path: '/', element: <Navigate to="/app" replace /> },
  {
    element: <GuestOnly />,
    children: [
      { path: '/login', element: <Login /> },
      { path: '/register', element: <Register /> },
      { path: '/forgot-password', element: <ForgotPassword /> },
    ],
  },
  { path: '/reset-password', element: <ResetPassword /> },
  { path: '/verify-email', element: <VerifyEmailResult /> },
  { path: '/report-abuse', element: <Suspense fallback={<FullPageSpinner />}><ReportAbuse /></Suspense> },
  { path: '/share/:token', element: <Suspense fallback={<FullPageSpinner />}><SharePreview /></Suspense> },
  {
    element: <RequireAuth />,
    children: [
      { path: '/app', element: <Dashboard /> },
      { path: '/app/projects/:projectId', element: <Suspense fallback={<FullPageSpinner />}><Editor /></Suspense> },
      { path: '/app/projects/:projectId/data', element: <Suspense fallback={<FullPageSpinner />}><DataAdmin /></Suspense> },
      { path: '/app/billing', element: <Suspense fallback={<FullPageSpinner />}><Billing /></Suspense> },
      { path: '/billing/test-checkout/:ref', element: <Suspense fallback={<FullPageSpinner />}><TestCheckout /></Suspense> },
      { path: '/app/integrations', element: <Suspense fallback={<FullPageSpinner />}><Integrations /></Suspense> },
      { path: '/app/team', element: <Suspense fallback={<FullPageSpinner />}><Team /></Suspense> },
      { path: '/invite/:token', element: <Suspense fallback={<FullPageSpinner />}><AcceptInvite /></Suspense> },
      { path: '/app/admin', element: <Suspense fallback={<FullPageSpinner />}><AdminConsole /></Suspense> },
      { path: '/app/admin/settings', element: <Suspense fallback={<FullPageSpinner />}><AdminSettings /></Suspense> },
      { path: '/app/projects/:projectId/preview', element: <Suspense fallback={<FullPageSpinner />}><Preview /></Suspense> },
    ],
  },
  { path: '*', element: <div className="p-10 text-center"><p className="text-4xl font-semibold">404</p><p className="mt-2 text-slate-500">ไม่พบหน้าที่ต้องการ</p><a className="mt-4 inline-block text-brand-700 underline" href="/app">กลับหน้าหลัก</a></div> },
]);

export default function App() {
  return <RouterProvider router={router} />;
}
