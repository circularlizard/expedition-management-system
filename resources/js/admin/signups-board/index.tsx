import React from 'react';
import { createRoot } from 'react-dom/client';
import SignupsBoard from './SignupsBoard';

document.addEventListener( 'DOMContentLoaded', () => {
    const rootElement = document.getElementById( 'ems-signups-root' );
    if ( rootElement ) {
        const root = createRoot( rootElement );
        root.render( <SignupsBoard /> );
    }
} );
