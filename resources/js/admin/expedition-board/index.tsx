import React from 'react';
import { createRoot } from 'react-dom/client';
import ExpeditionBoard from './ExpeditionBoard';

document.addEventListener( 'DOMContentLoaded', () => {
    const rootElement = document.getElementById( 'ems-expedition-board-root' );
    if ( rootElement ) {
        const root = createRoot( rootElement );
        root.render( <ExpeditionBoard /> );
    }
} );
