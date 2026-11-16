import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { ManualNotifyModal } from '../../resources/js/admin/expedition-board/ManualNotifyModal';

const mockPreviewResponse = {
  scope: 'cohort',
  total_count: 2,
  warnings: [
    {
      code: 'unmatched_osm',
      severity: 'warning',
      message: '1 explorer is unmatched in OSM (scout_id = 0).',
      count: 1,
    },
    {
      code: 'insufficient_first_aid',
      severity: 'warning',
      message: 'Cohort has fewer than 2 valid First Aiders.',
    },
  ],
  sample_email: {
    subject: 'Your DofE Expedition Place Offer — Alpha Team',
    html: '<p>Hello Alice, you have been offered a place on H-SP1.</p>',
  },
  recipients: [
    {
      expedition_signup_id: 501,
      scout_id: 1001,
      explorer_name: 'Alice MacLeod',
      explorer_email: 'alice@example.com',
      parent_email: 'parent.alice@example.com',
      has_valid_email: true,
      notification_status: 'draft',
      availability_override: 'matched',
      cohort_id: 11,
      cohort_name: 'Cohort Alpha',
      team_code: 'H-SP1-1',
      dofe_level: 'silver',
      transport_mode: 'foot',
      warnings: [],
    },
    {
      expedition_signup_id: 502,
      scout_id: 0,
      explorer_name: 'Ben Fraser',
      explorer_email: 'ben@example.com',
      parent_email: 'parent.ben@example.com',
      has_valid_email: true,
      notification_status: 're_notify_needed',
      availability_override: 'date_conflict_pending',
      cohort_id: 11,
      cohort_name: 'Cohort Alpha',
      team_code: 'H-SP1-1',
      dofe_level: 'silver',
      transport_mode: 'foot',
      warnings: ['unmatched_osm', 'date_conflict_pending'],
    },
  ],
};

describe('ManualNotifyModal — Two-Step Confirmation Gate', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    (window as any).emsExpeditionBoard = {
      root_url: 'https://example.com/wp-json/ems/v1',
      nonce: 'test-nonce',
    };
  });

  it('fetches preview, renders warnings/recipients/email preview, and gates dispatch behind explicit confirmation checkbox', async () => {
    const onDispatched = vi.fn();
    const fetchMock = vi.fn(async (url: string, _init?: RequestInit) => {
      if (url.includes('/expeditions/notifications/preview')) {
        return { ok: true, json: async () => mockPreviewResponse };
      }
      if (url.includes('/expeditions/notifications/dispatch')) {
        return { ok: true, json: async () => ({ sent_count: 2, skipped_count: 0 }) };
      }
      return { ok: false, status: 404, json: async () => ({}) };
    });

    global.fetch = fetchMock as unknown as typeof fetch;

    render(
      <ManualNotifyModal
        isOpen={true}
        onClose={vi.fn()}
        initialScope="cohort"
        cohortId={11}
        cohortName="Cohort Alpha"
        dofeLevel="silver"
        transportMode="foot"
        onDispatched={onDispatched}
      />
    );

    // Preview loads warnings and recipients
    expect(await screen.findByText('Alice MacLeod')).toBeInTheDocument();
    expect(screen.getByText('Ben Fraser')).toBeInTheDocument();
    expect(screen.getByText(/parent\.alice@example\.com/i)).toBeInTheDocument();
    expect(screen.getByText(/1 explorer is unmatched in OSM/i)).toBeInTheDocument();
    expect(screen.getByText(/fewer than 2 valid First Aiders/i)).toBeInTheDocument();

    // Expandable preview email template
    const previewToggle = screen.getByRole('button', { name: /Preview Email Template/i });
    fireEvent.click(previewToggle);
    expect(await screen.findByText(/Your DofE Expedition Place Offer — Alpha Team/i)).toBeInTheDocument();

    // Dispatch button must be disabled before confirmation checkbox is checked
    const dispatchBtn = screen.getByRole('button', { name: /Dispatch Place Offers/i });
    expect(dispatchBtn).toBeDisabled();

    // Check explicit confirmation checkbox
    const confirmCheckbox = screen.getByRole('checkbox', {
      name: /I confirm I have reviewed the recipients and safety warnings/i,
    });
    fireEvent.click(confirmCheckbox);
    expect(dispatchBtn).not.toBeDisabled();

    // Click Dispatch Place Offers
    fireEvent.click(dispatchBtn);

    await waitFor(() => {
      const dispatchCall = fetchMock.mock.calls.find(([callUrl]) =>
        callUrl.includes('/expeditions/notifications/dispatch')
      );
      expect(dispatchCall).toBeTruthy();
      const body = JSON.parse(String(dispatchCall![1]?.body));
      expect(body).toEqual({
        confirm_dispatch: true,
        expedition_signup_ids: [501, 502],
        cohort_id: 11,
      });
    });

    expect(onDispatched).toHaveBeenCalledWith(expect.objectContaining({ dispatched_count: 2 }));
  });
});
