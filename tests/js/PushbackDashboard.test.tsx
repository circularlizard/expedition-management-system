import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import PushbackDashboard from '../../resources/js/admin/expedition-board/PushbackDashboard';

const mockConfig = {
	root_url: 'https://example.com/wp-json/ems/v1',
	nonce: 'test-nonce',
	sections: {
		'101': { name: 'Explorers Section 1', type: 'explorers' },
		'102': { name: 'Explorers Section 2', type: 'explorers' }
	}
};

(global as any).window.emsExpeditionBoard = mockConfig;

const mockPreviewData = {
	flexi_record: {
		exists: true,
		id: 73848,
		proposed_name: '2026 Expeditions',
		missing_columns: ['PRACTICE ACCEPTED'],
		updates: [
			{
				scout_id: 30001,
				first_name: 'Alice',
				last_name: 'Smith',
				column: 'f_9',
				column_name: 'PRACTICE GROUPS',
				current_value: 'HGP1-1',
				proposed_value: 'HGP1-2'
			}
		]
	},
	events: [
		{
			event_id: 50001,
			proposed_invites: [
				{
					scout_id: 30001,
					first_name: 'Alice',
					last_name: 'Smith'
				}
			]
		}
	],
	errors: ['Testing diagnostic log warning.']
};

describe('PushbackDashboard', () => {
	beforeEach(() => {
		vi.resetAllMocks();
		global.fetch = vi.fn((url: string) => {
			if (url.includes('/admin/sync-status')) {
				return Promise.resolve({
					ok: true,
					json: async () => ({ failed_queue: null, locked: false })
				});
			}
			return Promise.resolve({
				ok: true,
				json: async () => mockPreviewData
			});
		});
	});

	it('renders section selector and fetches preview automatically', async () => {
		render(<PushbackDashboard />);

		expect(screen.getByLabelText('Select Section:')).toBeInTheDocument();
		expect(screen.getByText('Explorers Section 1 (explorers)')).toBeInTheDocument();

		await waitFor(() => {
			expect(screen.getByText(/Flexi-Record: 2026 Expeditions/)).toBeInTheDocument();
			expect(screen.getAllByText(/Alice Smith/)[0]).toBeInTheDocument();
			expect(screen.getByText('HGP1-2')).toBeInTheDocument();
			expect(screen.getByText(/ID: 50001/)).toBeInTheDocument();
			expect(screen.getByText('Testing diagnostic log warning.')).toBeInTheDocument();
		});
	});

	it('shows error notice when preview fetch fails', async () => {
		global.fetch = vi.fn((url: string) => {
			if (url.includes('/admin/sync-status')) {
				return Promise.resolve({
					ok: true,
					json: async () => ({ failed_queue: null, locked: false })
				});
			}
			return Promise.resolve({
				ok: false,
				json: async () => ({ message: 'Preview fetch rejected.' })
			});
		});

		render(<PushbackDashboard />);

		await waitFor(() => {
			expect(screen.getByText('Preview fetch rejected.')).toBeInTheDocument();
		});
	});

	it('triggers refresh fetch when refresh button is clicked', async () => {
		let previewCallCount = 0;
		global.fetch = vi.fn((url: string) => {
			if (url.includes('/admin/sync-status')) {
				return Promise.resolve({
					ok: true,
					json: async () => ({ failed_queue: null, locked: false })
				});
			}
			previewCallCount++;
			const errors = previewCallCount > 1 ? ['Refreshed log error'] : ['Testing diagnostic log warning.'];
			return Promise.resolve({
				ok: true,
				json: async () => ({ ...mockPreviewData, errors })
			});
		});

		render(<PushbackDashboard />);

		await waitFor(() => {
			expect(screen.getByText('Testing diagnostic log warning.')).toBeInTheDocument();
		});

		const refreshButton = screen.getByRole('button', { name: 'Refresh Preview' });
		fireEvent.click(refreshButton);

		await waitFor(() => {
			expect(screen.getByText('Refreshed log error')).toBeInTheDocument();
		});
	});

	it('executes push-back sync and refreshes preview', async () => {
		global.fetch = vi.fn((url: string) => {
			if (url.includes('/admin/sync-status')) {
				return Promise.resolve({
					ok: true,
					json: async () => ({ failed_queue: null, locked: false })
				});
			}
			if (url.includes('/admin/sync-push')) {
				return Promise.resolve({
					ok: true,
					json: async () => ({ success: true, message: 'Sync successful!' })
				});
			}
			return Promise.resolve({
				ok: true,
				json: async () => mockPreviewData
			});
		});

		render(<PushbackDashboard />);

		await waitFor(() => {
			expect(screen.getByRole('button', { name: /Execute Push-back Sync/ })).toBeInTheDocument();
		});

		const syncButton = screen.getByRole('button', { name: /Execute Push-back Sync/ });
		fireEvent.click(syncButton);

		await waitFor(() => {
			expect(screen.getByText('Sync successful!')).toBeInTheDocument();
		});
	});
});
