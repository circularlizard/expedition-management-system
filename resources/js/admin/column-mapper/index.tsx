import React from 'react';
import { createRoot } from 'react-dom/client';
import ColumnMapper from './ColumnMapper';

document.addEventListener( 'DOMContentLoaded', () => {
    const rootElement = document.getElementById( 'ems-column-mapper-root' );
    if ( rootElement ) {
        const root = createRoot( rootElement );
        root.render( <ColumnMapper /> );
    }
} );
