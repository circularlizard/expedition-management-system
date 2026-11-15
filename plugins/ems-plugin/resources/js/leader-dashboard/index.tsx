import '../../css/ems-admin.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import { LeaderDashboard } from './LeaderDashboard';
import { ModuleErrorBoundary } from '../shared/ModuleErrorBoundary';

const container = document.getElementById('ems-leader-dashboard-root');
if (container) {
    const root = createRoot(container);
    root.render(
        <ModuleErrorBoundary moduleName="Leader Dashboard">
            <LeaderDashboard />
        </ModuleErrorBoundary>
    );
}

