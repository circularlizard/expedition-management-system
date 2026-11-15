import React from 'react';
import { createRoot } from 'react-dom/client';
import EmailTemplatesApp from './EmailTemplatesApp';

const rootEl = document.getElementById('ems-email-templates-root');
if (rootEl) {
    createRoot(rootEl).render(<EmailTemplatesApp />);
}
