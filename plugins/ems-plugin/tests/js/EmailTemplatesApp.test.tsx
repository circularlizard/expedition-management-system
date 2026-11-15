import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import EmailTemplatesApp from '../../resources/js/admin/email-templates/EmailTemplatesApp';

const mockTemplates = [
    {
        slug: 'leader_invite',
        label: 'Unit Leader Invitation',
        category: 'Unit Leaders',
        description: 'Sent when inviting a unit leader.',
        subject: '[{{site_name}}] Your Unit Leader Dashboard Access',
        reply_to: '',
        body: '<p>Hello {{unit_name}}</p>',
        default_subject: '[{{site_name}}] Your Unit Leader Dashboard Access',
        default_body: '<p>Hello {{unit_name}}</p>',
        is_customized: false,
        tokens: [
            { token: '{{unit_name}}', label: 'Assigned Units', description: 'Formatted list of assigned unit names' },
            { token: '{{site_name}}', label: 'Site Name', description: 'WordPress site title' },
        ],
    },
    {
        slug: 'osm_share_request',
        label: 'OSM Share Request',
        category: 'Signups & Chasers',
        description: 'Sent to unit leaders requesting OSM record sharing.',
        subject: 'OSM Share Request for {{unit_name}}',
        reply_to: 'dofe@example.com',
        body: '<p>Missing: {{explorer_list_osm}}</p>',
        default_subject: 'OSM Share Request for {{unit_name}}',
        default_body: '<p>Missing: {{explorer_list_osm}}</p>',
        is_customized: true,
        tokens: [
            { token: '{{unit_name}}', label: 'Unit Name', description: 'Explorer Unit name' },
            {
                token: '{{explorer_list_osm}}',
                label: 'Unsynced Explorers List',
                description: 'HTML list of unit explorers not yet synced with OSM',
                supports_levels: true,
            },
        ],
    },
];

describe('EmailTemplatesApp', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        (window as any).emsEmailTemplates = {
            root_url: '/wp-json/ems/v1',
            nonce: 'test-nonce',
            current_user_email: 'admin@example.com',
        };
    });

    it('renders category-grouped templates, switches selection, inserts shortcodes, saves, resets, and sends test email', async () => {
        const fetchMock = vi.fn().mockImplementation((url: string, init?: RequestInit) => {
            if (url.endsWith('/email-templates') && (!init || !init.method || init.method === 'GET')) {
                return Promise.resolve({ ok: true, json: async () => mockTemplates });
            }
            if (url.endsWith('/email-templates/osm_share_request') && init?.method === 'PUT') {
                const payload = JSON.parse(String(init.body));
                return Promise.resolve({
                    ok: true,
                    json: async () => ({ ...mockTemplates[1], ...payload, is_customized: true }),
                });
            }
            if (url.endsWith('/email-templates/osm_share_request/reset') && init?.method === 'POST') {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({ ...mockTemplates[1], subject: 'Default OSM Subject', is_customized: false }),
                });
            }
            if (url.endsWith('/email-templates/osm_share_request/test') && init?.method === 'POST') {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({ sent: true, to: 'admin@example.com', subject: 'Test' }),
                });
            }
            return Promise.resolve({ ok: false, json: async () => ({ message: 'Unexpected call' }) });
        });
        global.fetch = fetchMock as any;

        render(<EmailTemplatesApp />);

        await waitFor(() => {
            expect(screen.getByText('Unit Leaders')).toBeInTheDocument();
            expect(screen.getByText('Signups & Chasers')).toBeInTheDocument();
        });

        // Switch to OSM Share Request
        fireEvent.click(screen.getByRole('button', { name: /OSM Share Request/i }));
        expect(screen.getByLabelText('Subject')).toHaveValue('OSM Share Request for {{unit_name}}');
        expect(screen.getByLabelText('Email HTML Body')).toHaveValue('<p>Missing: {{explorer_list_osm}}</p>');

        // Insert shortcode from the RTE shortcode picker dropdown
        fireEvent.change(screen.getByLabelText('Insert Shortcode'), {
            target: { value: '{{explorer_list_osm}}' },
        });
        expect((screen.getByLabelText('Email HTML Body') as HTMLTextAreaElement).value).toContain('{{explorer_list_osm}}');

        // Edit subject and Save
        fireEvent.change(screen.getByLabelText('Subject'), { target: { value: 'Updated OSM Subject' } });
        fireEvent.click(screen.getByRole('button', { name: /Save Template/i }));
        await waitFor(() => {
            expect(screen.getByText(/Saved "OSM Share Request" template\./i)).toBeInTheDocument();
        });

        // Send Test Email
        fireEvent.click(screen.getByRole('button', { name: /Send Test Email/i }));
        await waitFor(() => {
            expect(screen.getByText(/Test email sent to admin@example\.com\./i)).toBeInTheDocument();
        });

        // Reset to Default
        fireEvent.click(screen.getByRole('button', { name: /Reset to Default/i }));
        await waitFor(() => {
            expect(screen.getByLabelText('Subject')).toHaveValue('Default OSM Subject');
        });
    });
});
