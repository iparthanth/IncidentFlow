import { Navigate, Route, Routes } from 'react-router-dom';
import { useAuth } from '@/auth/useAuth';
import { AppLayout } from '@/components/AppLayout';
import { ErrorBoundary } from '@/components/ErrorBoundary';
import { FullPageSpinner } from '@/components/FullPageSpinner';
import { LoginPage } from '@/pages/LoginPage';
import { RegisterPage } from '@/pages/RegisterPage';
import { IncidentsPage } from '@/pages/IncidentsPage';
import { IncidentDetailPage } from '@/pages/IncidentDetailPage';
import { ServicesPage } from '@/pages/ServicesPage';
import { MetricsPage } from '@/pages/MetricsPage';
import { PostmortemsPage } from '@/pages/PostmortemsPage';
import { AdminPage } from '@/pages/AdminPage';
import { NotFoundPage } from '@/pages/NotFoundPage';

export function App() {
  const { status } = useAuth();

  // The session is restored from the refresh cookie on load. Rendering routes
  // before that finishes would flash the login screen at an authenticated user
  // on every refresh.
  if (status === 'loading') {
    return <FullPageSpinner label="Restoring your session…" />;
  }

  if (status === 'anonymous') {
    return (
      <ErrorBoundary>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/register" element={<RegisterPage />} />
          <Route path="*" element={<Navigate to="/login" replace />} />
        </Routes>
      </ErrorBoundary>
    );
  }

  return (
    <ErrorBoundary>
      <Routes>
        <Route element={<AppLayout />}>
          <Route index element={<Navigate to="/incidents" replace />} />
          <Route path="/incidents" element={<IncidentsPage />} />
          <Route path="/incidents/:id" element={<IncidentDetailPage />} />
          <Route path="/services" element={<ServicesPage />} />
          <Route path="/metrics" element={<MetricsPage />} />
          <Route path="/postmortems" element={<PostmortemsPage />} />
          <Route path="/admin" element={<AdminPage />} />
          <Route path="/login" element={<Navigate to="/incidents" replace />} />
          <Route path="*" element={<NotFoundPage />} />
        </Route>
      </Routes>
    </ErrorBoundary>
  );
}
