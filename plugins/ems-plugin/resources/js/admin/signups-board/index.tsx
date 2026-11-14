import '../../../css/ems-admin.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import SignupsBoard from './SignupsBoard';
import { ModuleErrorBoundary } from '../../shared/ModuleErrorBoundary';

document.addEventListener( 'DOMContentLoaded', () => {
    const element = document.getElementById( 'ems-signups-root' );
    if ( element ) {
        const root = createRoot( element );
        root.render(
            <ModuleErrorBoundary moduleName="Signups Board">
                <SignupsBoard />
            </ModuleErrorBoundary>
        );
    }
} );
