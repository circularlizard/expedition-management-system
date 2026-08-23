import '../../../css/ems-admin.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import SignupsBoard from './SignupsBoard';

document.addEventListener( 'DOMContentLoaded', () => {
    const element = document.getElementById( 'ems-signups-root' );
    if ( element ) {
        const root = createRoot( element );
        root.render( <SignupsBoard /> );
    }
} );
