import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ModuleErrorBoundary } from '../../resources/js/shared/ModuleErrorBoundary';

describe('ModuleErrorBoundary', () => {
    let consoleErrorSpy: any;

    beforeEach(() => {
        consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        consoleErrorSpy.mockRestore();
    });

    it('renders children when healthy', () => {
        render(
            <ModuleErrorBoundary moduleName="HealthyModule">
                <div>Healthy Child Content</div>
            </ModuleErrorBoundary>
        );

        expect(screen.getByText('Healthy Child Content')).toBeInTheDocument();
        expect(screen.queryByText(/encountered an unexpected error/i)).not.toBeInTheDocument();
    });

    it('catches thrown error, renders default error card with moduleName and retry button', () => {
        const BadComponent = () => {
            throw new Error('Test boom');
        };

        render(
            <ModuleErrorBoundary moduleName="Expedition Board">
                <BadComponent />
            </ModuleErrorBoundary>
        );

        expect(screen.getByText('Expedition Board Error')).toBeInTheDocument();
        expect(screen.getByText('This section encountered an unexpected error.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
        expect(consoleErrorSpy).toHaveBeenCalled();
    });

    it('renders fallback when fallback prop is provided and error is thrown', () => {
        const BadComponent = () => {
            throw new Error('Test boom');
        };

        render(
            <ModuleErrorBoundary fallback={<div>Custom Fallback View</div>}>
                <BadComponent />
            </ModuleErrorBoundary>
        );

        expect(screen.getByText('Custom Fallback View')).toBeInTheDocument();
        expect(screen.queryByText('Module Error')).not.toBeInTheDocument();
    });

    it('recovers when retry button is clicked after error condition clears', () => {
        let shouldThrow = true;

        const FlakyComponent = () => {
            if (shouldThrow) {
                throw new Error('Temporary failure');
            }
            return <div>Recovered Child Content</div>;
        };

        render(
            <ModuleErrorBoundary moduleName="Flaky Module">
                <FlakyComponent />
            </ModuleErrorBoundary>
        );

        expect(screen.getByText('Flaky Module Error')).toBeInTheDocument();
        expect(screen.queryByText('Recovered Child Content')).not.toBeInTheDocument();

        // Fix error condition
        shouldThrow = false;

        // Click Retry button
        fireEvent.click(screen.getByRole('button', { name: 'Retry' }));

        expect(screen.getByText('Recovered Child Content')).toBeInTheDocument();
        expect(screen.queryByText('Flaky Module Error')).not.toBeInTheDocument();
    });
});
