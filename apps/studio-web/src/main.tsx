import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import '@fontsource/kanit/300.css';
import '@fontsource/kanit/400.css';
import '@fontsource/kanit/500.css';
import '@fontsource/kanit/600.css';
import '@fontsource/kanit/700.css';
import '@fontsource/sarabun/400.css';
import '@fontsource/sarabun/600.css';
import '@fontsource/prompt/400.css';
import '@fontsource/prompt/700.css';
import '@fontsource/noto-sans-thai/400.css';
import '@fontsource/noto-sans-thai/700.css';
import './styles.css';
import App from './App.tsx';
import { Toaster } from './components/ui/Toast.tsx';
import { applyStoredTheme } from './components/ui/misc.tsx';
import { ApiError } from './lib/api.ts';

applyStoredTheme();

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: (count, error) => !(error instanceof ApiError && error.status >= 400 && error.status < 500) && count < 2,
      refetchOnWindowFocus: false,
    },
  },
});

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <App />
      <Toaster />
    </QueryClientProvider>
  </StrictMode>,
);
