import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { QueryClientProvider } from '@tanstack/react-query';
import { AuthProvider } from '@/auth/AuthProvider';
import { createQueryClient } from '@/lib/query-client';
import { App } from './App';
import './index.css';

const container = document.getElementById('root');

if (!container) {
  throw new Error('The #root element is missing from index.html.');
}

const queryClient = createQueryClient();

createRoot(container).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        {/* AuthProvider sits inside the router because switching organizations
            triggers navigation, and inside the query client because restoring a
            session invalidates cached data. */}
        <AuthProvider>
          <App />
        </AuthProvider>
      </BrowserRouter>
    </QueryClientProvider>
  </StrictMode>,
);
