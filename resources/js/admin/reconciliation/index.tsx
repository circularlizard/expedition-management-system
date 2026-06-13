import React from 'react';
import { createRoot } from 'react-dom/client';
import { ReconciliationDashboard } from './ReconciliationDashboard';

const container = document.getElementById( 'ems-reconciliation-root' );
const reconciliationData = window.emsReconciliation;
if ( container && reconciliationData ) {
    createRoot( container ).render( <ReconciliationDashboard data={reconciliationData} /> );
}
