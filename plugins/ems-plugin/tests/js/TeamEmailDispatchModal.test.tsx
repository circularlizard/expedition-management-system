import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import {
  TeamEmailDispatchModal,
  formatEventSubtitle,
} from '../../resources/js/shared/TeamEmailDispatchModal';

describe('TeamEmailDispatchModal', () => {
  it('deduplicates event code/title and formats UK date in subtitle', () => {
    expect(formatEventSubtitle('H-SP1', 'H-SP1', '2027-05-28')).toBe('H-SP1 · 28 May 2027');
    expect(formatEventSubtitle('H-SP1', 'Silver Practice 1', '2027-05-28')).toBe(
      'H-SP1 — Silver Practice 1 · 28 May 2027'
    );
  });

  it('renders compliance_reminder mode with clickable explorer, email/Parent CC, gap pills, and confirmation gate', () => {
    const onInspectExplorer = vi.fn();
    const onDispatch = vi.fn();

    render(
      <TeamEmailDispatchModal
        isOpen={true}
        onClose={vi.fn()}
        mode="compliance_reminder"
        title="Send Reminder — Alpha Team"
        subtitle={formatEventSubtitle('H-SP1', 'H-SP1', '2027-05-28')}
        recipients={[
          {
            expedition_signup_id: 501,
            scout_id: 1001,
            explorer_name: 'Alice MacLeod',
            explorer_email: 'alice@example.com',
            parent_email: 'parent.alice@example.com',
            has_valid_email: true,
            notification_status: 'notified',
            gaps: ['First Aid missing/expired', 'Training: not started'],
          },
        ]}
        warnings={[
          {
            code: 'insufficient_first_aid',
            severity: 'error',
            message: 'Team has fewer than 2 valid First Aiders.',
          },
        ]}
        sampleEmail={{
          subject: 'Readiness Reminder',
          html: '<p>Please complete your training.</p>',
        }}
        selectedIds={[501]}
        onToggleRecipient={vi.fn()}
        onToggleSelectAll={vi.fn()}
        onInspectExplorer={onInspectExplorer}
        onDispatch={onDispatch}
      />
    );

    expect(screen.getByText('H-SP1 · 28 May 2027')).toBeInTheDocument();
    expect(screen.getByText('alice@example.com')).toBeInTheDocument();
    expect(screen.getByText('Parent CC: parent.alice@example.com')).toBeInTheDocument();
    expect(screen.getByText('First Aid missing/expired')).toBeInTheDocument();
    expect(screen.getByText('Training: not started')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Alice MacLeod' }));
    expect(onInspectExplorer).toHaveBeenCalledWith({ scoutId: 1001, signupId: 501 });

    const dispatchBtn = screen.getByRole('button', { name: /Dispatch Emails \(1\)/i });
    expect(dispatchBtn).toBeDisabled();

    fireEvent.click(
      screen.getByRole('checkbox', {
        name: /I confirm I have reviewed the recipients and warnings/i,
      })
    );
    expect(dispatchBtn).not.toBeDisabled();

    fireEvent.click(dispatchBtn);
    expect(onDispatch).toHaveBeenCalled();
  });
});
