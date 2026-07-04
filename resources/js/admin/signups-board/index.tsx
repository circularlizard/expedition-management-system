import '../../../css/ems-admin.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import SignupsBoard from './SignupsBoard';

document.addEventListener( 'DOMContentLoaded', () => {
    const participantElement = document.getElementById( 'ems-participant-signups-root' );
    if ( participantElement ) {
        const root = createRoot( participantElement );
        root.render( <SignupsBoard type="participant" /> );
    }

    const expeditionElement = document.getElementById( 'ems-expedition-signups-root' );
    if ( expeditionElement ) {
        const root = createRoot( expeditionElement );
        root.render( <SignupsBoard type="expedition" /> );
    }
} );
