import '../../css/ems-admin.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import { LeaderDashboard } from './LeaderDashboard';

const container = document.getElementById('ems-leader-dashboard-root');
if (container) {
    const root = createRoot(container);
    root.render(<LeaderDashboard />);
}
