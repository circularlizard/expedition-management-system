import React from 'react';
import { createRoot } from 'react-dom/client';
import { ReconciliationDashboard } from './ReconciliationDashboard';
import type { ReconciliationData } from './types';

declare const emsReconciliation: ReconciliationData;

const container = document.getElementById( 'ems-reconciliation-root' );
if ( container ) {
    createRoot( container ).render( <ReconciliationDashboard data={emsReconciliation} /> );
}
