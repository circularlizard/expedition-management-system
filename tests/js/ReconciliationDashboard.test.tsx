import React from 'react';
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ReconciliationDashboard } from '../../resources/js/admin/reconciliation/ReconciliationDashboard';
import type { ReconciliationData } from '../../resources/js/admin/reconciliation/types';

const mockData: ReconciliationData = {
    matched: [
        { email: 'john.explorer@example.com', first_name: 'John', last_name: 'Explorer', member_id: 1001, gf_id: '1' },
    ],
    only_in_osm: [
        { email: 'jane.scout@example.com', first_name: 'Jane', last_name: 'Scout', member_id: 1002 },
    ],
    only_in_gf: [
        { email: 'sam.wanderer@example.com', first_name: 'Sam', last_name: 'Wanderer', gf_id: '2' },
    ],
};

const emptyData: ReconciliationData = {
    matched: [],
    only_in_osm: [],
    only_in_gf: [],
};

describe('ReconciliationDashboard', () => {
    it('renders the matched section heading with count', () => {
        render(<ReconciliationDashboard data={mockData} />);
        expect(screen.getByText('Matched (1)')).toBeInTheDocument();
    });

    it('renders the OSM-only section heading with count', () => {
        render(<ReconciliationDashboard data={mockData} />);
        expect(screen.getByText('In OSM only (1)')).toBeInTheDocument();
    });

    it('renders the GF-only section heading with count', () => {
        render(<ReconciliationDashboard data={mockData} />);
        expect(screen.getByText('In Gravity Forms only (1)')).toBeInTheDocument();
    });

    it('renders matched explorer name', () => {
        render(<ReconciliationDashboard data={mockData} />);
        expect(screen.getByText('John Explorer')).toBeInTheDocument();
    });

    it('renders OSM-only member name', () => {
        render(<ReconciliationDashboard data={mockData} />);
        expect(screen.getByText('Jane Scout')).toBeInTheDocument();
    });

    it('renders GF-only entry name', () => {
        render(<ReconciliationDashboard data={mockData} />);
        expect(screen.getByText('Sam Wanderer')).toBeInTheDocument();
    });

    it('shows empty state message when matched list is empty', () => {
        render(<ReconciliationDashboard data={emptyData} />);
        expect(screen.getAllByText('No matched entries.').length).toBeGreaterThan(0);
    });

    it('renders zero counts in headings for empty data', () => {
        render(<ReconciliationDashboard data={emptyData} />);
        expect(screen.getByText('Matched (0)')).toBeInTheDocument();
        expect(screen.getByText('In OSM only (0)')).toBeInTheDocument();
        expect(screen.getByText('In Gravity Forms only (0)')).toBeInTheDocument();
    });
});
