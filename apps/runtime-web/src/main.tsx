import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import '@fontsource/kanit/400.css';
import '@fontsource/kanit/600.css';
import '@fontsource/kanit/700.css';
import '@fontsource/sarabun/400.css';
import '@fontsource/sarabun/600.css';
import '@fontsource/prompt/400.css';
import '@fontsource/prompt/700.css';
import '@fontsource/noto-sans-thai/400.css';
import '@fontsource/noto-sans-thai/700.css';
import './runtime.css';
import { App } from './App.tsx';
import { readBootstrap } from './site.ts';

const site = readBootstrap();
const root = document.getElementById('root');
if (site && root) {
  createRoot(root).render(<StrictMode><App site={site} /></StrictMode>);
}
