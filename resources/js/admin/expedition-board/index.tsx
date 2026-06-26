import React from 'react';
import { createRoot } from 'react-dom/client';
import ExpeditionBoard from './ExpeditionBoard';
import ExplorersPage from './ExplorersPage';

document.addEventListener( 'DOMContentLoaded', () => {
    const rootElement = document.getElementById( 'ems-expedition-board-root' );
    if ( rootElement ) {
        const root = createRoot( rootElement );
        root.render( <ExpeditionBoard /> );
    }

    const explorersRoot = document.getElementById( 'ems-explorers-root' );
    if ( explorersRoot ) {
        const root = createRoot( explorersRoot );
        root.render( <ExplorersPage /> );
    }
} );
