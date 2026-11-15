import React from 'react';

export interface ModuleErrorBoundaryProps {
    fallback?: React.ReactNode;
    moduleName?: string;
    children: React.ReactNode;
}

export interface ModuleErrorBoundaryState {
    hasError: boolean;
    error: Error | null;
}

export class ModuleErrorBoundary extends React.Component<ModuleErrorBoundaryProps, ModuleErrorBoundaryState> {
    constructor(props: ModuleErrorBoundaryProps) {
        super(props);
        this.state = {
            hasError: false,
            error: null,
        };
    }

    static getDerivedStateFromError(error: Error): ModuleErrorBoundaryState {
        return {
            hasError: true,
            error,
        };
    }

    componentDidCatch(error: Error, errorInfo: React.ErrorInfo): void {
        console.error(
            `[ModuleErrorBoundary${this.props.moduleName ? ` - ${this.props.moduleName}` : ''}] uncaught error:`,
            error,
            errorInfo
        );
    }

    handleReset = (): void => {
        this.setState({ hasError: false, error: null });
    };

    render(): React.ReactNode {
        if (this.state.hasError) {
            if (this.props.fallback) {
                return this.props.fallback;
            }

            return (
                <div className="ems-module-error-boundary bg-red-50 border border-red-200 rounded-lg p-6 my-4 text-center">
                    <h3 className="text-lg font-semibold text-red-800">
                        {this.props.moduleName ? `${this.props.moduleName} Error` : 'Module Error'}
                    </h3>
                    <p className="text-sm text-red-600 mt-2">This section encountered an unexpected error.</p>
                    <button
                        type="button"
                        onClick={this.handleReset}
                        className="mt-4 px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700"
                    >
                        Retry
                    </button>
                </div>
            );
        }

        return this.props.children;
    }
}

export default ModuleErrorBoundary;
