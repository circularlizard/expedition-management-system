import React, { useState, useEffect, useCallback } from 'react';
import { useBoard } from './useBoard';
import { useOSMEvents } from './useOSMEvents';
import { EventsDashboard } from './EventsDashboard';
import { EventDetailPage } from './EventDetailPage';
import { Expedition } from './types';
import CohortsContinuityView from './CohortsContinuityView';
import StageMatrixView from './StageMatrixView';
import TeamsReadinessView from './TeamsReadinessView';
import { EventForm } from './EventForm';
import { ManualNotifyModal } from './ManualNotifyModal';
import Explorer360Drawer from '../shared/Explorer360Drawer';
import { TeamScopeBar } from './TeamScopeBar';
import { useTeamPlanningData } from './useTeamPlanningData';
import { NotifyModalOptions, toCohortTransportMode } from './cohortTypes';
import { ModuleErrorBoundary } from '../../shared/ModuleErrorBoundary';

type BoardTab = 'dashboard' | 'team-builder' | 'date-grid' | 'readiness' | 'detail' | 'create';

function normalizePlanningTransport(raw?: string): string {
    const n = (raw || '').trim().toLowerCase();
    if (n === 'foot' || n === 'walking' || n === 'hillwalking') return 'hillwalking';
    if (n === 'bike' || n === 'cycling' || n === 'biking') return 'biking';
    if (n === 'canoe' || n === 'paddle' || n === 'paddling') return 'paddling';
    return 'hillwalking';
}

function normalizePlanningLevel(raw?: string): 'bronze' | 'silver' | 'gold' {
    const n = (raw || '').trim().toLowerCase();
    if (n === 'bronze' || n === 'silver' || n === 'gold') return n;
    return 'silver';
}

const ExpeditionBoard: React.FC = () => {
    const config = window.emsExpeditionBoard || { root_url: '/wp-json/ems/v1', nonce: '', units: [] };
    const { data, loading, error, refetch } = useBoard();
    const { events: osmEvents, loading: osmEventsLoading } = useOSMEvents();

    const [activeTab, setActiveTab] = useState<BoardTab>('dashboard');
    const [selectedEvent, setSelectedEvent] = useState<Expedition | null>(null);
    const [initialEdit, setInitialEdit] = useState(false);

    const [planningLevel, setPlanningLevel] = useState<'bronze' | 'silver' | 'gold'>('silver');
    const [planningTransport, setPlanningTransport] = useState<string>('hillwalking');
    const [notifyModalOpts, setNotifyModalOpts] = useState<NotifyModalOptions | null>(null);
    const [drawerTarget, setDrawerTarget] = useState<{ scoutId?: number; signupId?: number } | null>(null);

    const isPlanningTab = activeTab === 'team-builder' || activeTab === 'date-grid';
    const planningData = useTeamPlanningData(planningLevel, planningTransport, isPlanningTab);

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const tab = params.get('tab');
        if (tab === 'planning') {
            setActiveTab('team-builder');
        } else if (tab && ['dashboard', 'team-builder', 'date-grid', 'readiness', 'create'].includes(tab)) {
            setActiveTab(tab as BoardTab);
        }
        const lvl = params.get('level');
        if (lvl) setPlanningLevel(normalizePlanningLevel(lvl));
        const tr = params.get('transport');
        if (tr) setPlanningTransport(normalizePlanningTransport(tr));

        const eventId = params.get('event_id');
        if (eventId && data?.seasons) {
            for (const season of data.seasons) {
                const event = season.events.find(e => e.ID === Number(eventId));
                if (event) {
                    setSelectedEvent(event);
                    setInitialEdit(false);
                    setActiveTab('detail');
                    break;
                }
            }
        }
    }, [data]);

    const handleSelectEvent = (event: Expedition) => {
        setSelectedEvent(event);
        setInitialEdit(false);
        setActiveTab('detail');
    };

    const handleEditEvent = (event: Expedition) => {
        setSelectedEvent(event);
        setInitialEdit(true);
        setActiveTab('detail');
    };

    const handleOpenEventById = useCallback(async (eventId: number) => {
        if (!eventId || !data?.seasons) return;
        for (const season of data.seasons) {
            const found = season.events.find(e => e.ID === eventId);
            if (found) {
                setSelectedEvent(found);
                setInitialEdit(false);
                setActiveTab('detail');
                return;
            }
        }
        try {
            const res = await fetch(`${config.root_url}/events/${eventId}`, {
                headers: { 'X-WP-Nonce': config.nonce },
            });
            if (res.ok) {
                const ev = await res.json();
                if (ev?.ID) {
                    setSelectedEvent(ev);
                    setInitialEdit(false);
                    setActiveTab('detail');
                }
            }
        } catch {
            // Ignore fetch failure on cross-link fallback
        }
    }, [data, config.root_url, config.nonce]);

    const handleOpenTeamBuilder = useCallback(
        (opts?: { level?: string; transport?: string; view?: 'team-builder' | 'date-grid' }) => {
            if (opts?.level) setPlanningLevel(normalizePlanningLevel(opts.level));
            if (opts?.transport) setPlanningTransport(normalizePlanningTransport(opts.transport));
            setActiveTab(opts?.view ?? 'team-builder');
        },
        []
    );

    const handleBack = () => {
        setSelectedEvent(null);
        setInitialEdit(false);
        setActiveTab('dashboard');
    };

    const handleEventUpdated = (updated: Expedition) => {
        setSelectedEvent(updated);
        refetch();
    };

    if (loading) return <p>Loading board…</p>;
    if (error) return <div className="notice notice-error"><p>{error}</p></div>;
    if (!data || !Array.isArray(data.seasons)) {
        return (
            <div className="notice notice-error">
                <p>The expedition board could not be displayed: the server returned an unexpected response shape (no <code>seasons</code> data). Please reload, and if the problem persists check the REST endpoint <code>ems/v1/expedition-board</code>.</p>
            </div>
        );
    }

    const units: any[] = config.units || [];

    return (
        <div className="ems-board">
            <div className="ems-board-header">
                <span className="ems-board-synced">
                    Last synced with OSM: {data.last_sync ? new Date(data.last_sync).toLocaleString() : 'Never'}
                </span>
            </div>

            {activeTab !== 'detail' && activeTab !== 'create' && (
                <nav className="nav-tab-wrapper">
                    <button
                        className={`nav-tab ${activeTab === 'dashboard' ? 'nav-tab-active' : ''}`}
                        onClick={() => setActiveTab('dashboard')}
                    >
                        Events Dashboard
                    </button>
                    <button
                        className={`nav-tab ${activeTab === 'team-builder' ? 'nav-tab-active' : ''}`}
                        onClick={() => setActiveTab('team-builder')}
                    >
                        Team Builder
                    </button>
                    <button
                        className={`nav-tab ${activeTab === 'date-grid' ? 'nav-tab-active' : ''}`}
                        onClick={() => setActiveTab('date-grid')}
                    >
                        Availability &amp; Date Grid
                    </button>
                    <button
                        className={`nav-tab ${activeTab === 'readiness' ? 'nav-tab-active' : ''}`}
                        onClick={() => setActiveTab('readiness')}
                    >
                        Teams &amp; Readiness
                    </button>
                </nav>
            )}

            <div className={`tab-content ${(activeTab === 'detail' || activeTab === 'create') ? '' : 'ems-mt-20'}`}>
                {activeTab === 'dashboard' && (
                    <ModuleErrorBoundary moduleName="Events Dashboard">
                        <EventsDashboard
                            onSelectEvent={handleSelectEvent}
                            onEditEvent={handleEditEvent}
                            osmEvents={osmEvents}
                            osmEventsLoading={osmEventsLoading}
                            onCreateEvent={() => setActiveTab('create')}
                        />
                    </ModuleErrorBoundary>
                )}
                {activeTab === 'detail' && selectedEvent && (
                    <ModuleErrorBoundary moduleName="Event Details">
                        <EventDetailPage
                            event={selectedEvent}
                            onBack={handleBack}
                            osmEvents={osmEvents}
                            allEvents={data.seasons[0]?.events ?? []}
                            onEventUpdated={handleEventUpdated}
                            initialEdit={initialEdit}
                            onOpenTeamBuilder={handleOpenTeamBuilder}
                        />
                    </ModuleErrorBoundary>
                )}
                {activeTab === 'create' && (
                    <ModuleErrorBoundary moduleName="Create Event">
                        <div className="ems-event-detail">
                            <div className="ems-event-detail__toolbar">
                                <button id="ems-back-to-events" type="button" className="button-link ems-event-detail__back" onClick={handleBack}>← Back to Events</button>
                            </div>
                            <div className="ems-event-detail__header">
                                <div>
                                    <h1 className="ems-event-detail__title">Create Event</h1>
                                </div>
                            </div>
                            <div className="ems-event-detail__content">
                                <div className="ems-panel">
                                    <EventForm
                                        seasonId={0}
                                        osmEvents={osmEvents}
                                        onSaved={() => {
                                            handleBack();
                                            refetch();
                                        }}
                                        onCancel={handleBack}
                                    />
                                </div>
                            </div>
                        </div>
                    </ModuleErrorBoundary>
                )}
                {isPlanningTab && (
                    <div className="ems-planning-matrix">
                        {planningData.feedback && (
                            <div className={`notice ${planningData.feedback.ok ? 'notice-success' : 'notice-error'} is-dismissible ems-mb-16`}>
                                <p>{planningData.feedback.msg}</p>
                                <button type="button" className="notice-dismiss" onClick={() => planningData.setFeedback(null)}>
                                    <span className="screen-reader-text">Dismiss</span>
                                </button>
                            </div>
                        )}

                        {planningData.error && (
                            <div className="notice notice-error ems-mb-16">
                                <p>{planningData.error}</p>
                            </div>
                        )}

                        <TeamScopeBar
                            level={planningLevel}
                            transport={planningTransport}
                            pendingNotifyCount={planningData.pendingNotifyCount}
                            onChangeLevel={setPlanningLevel}
                            onChangeTransport={setPlanningTransport}
                            onOpenNotify={() =>
                                setNotifyModalOpts({
                                    scope: 'all',
                                    level: planningLevel,
                                    transport: planningTransport,
                                })
                            }
                        />

                        {planningData.loading ? (
                            <div className="ems-planning-spinner">
                                <span className="ems-spinner" aria-label="Loading team planning board…" />
                            </div>
                        ) : activeTab === 'team-builder' ? (
                            <ModuleErrorBoundary moduleName="Team Builder">
                                <CohortsContinuityView
                                    level={planningLevel}
                                    transport={planningTransport}
                                    rootUrl={config.root_url}
                                    nonce={config.nonce}
                                    units={units}
                                    cohorts={planningData.cohorts}
                                    practiceEvents={planningData.practiceEvents}
                                    qualifierEvents={planningData.qualifierEvents}
                                    practiceExplorers={planningData.practiceExplorers}
                                    qualifierExplorers={planningData.qualifierExplorers}
                                    onRefreshAll={planningData.fetchAllData}
                                    onFeedback={planningData.setFeedback}
                                    onOpenNotifyModal={(opts) => setNotifyModalOpts(opts)}
                                    onOpenEvent={handleOpenEventById}
                                />
                            </ModuleErrorBoundary>
                        ) : (
                            <ModuleErrorBoundary moduleName="Availability & Date Grid">
                                <StageMatrixView
                                    level={planningLevel}
                                    transport={planningTransport}
                                    rootUrl={config.root_url}
                                    nonce={config.nonce}
                                    units={units}
                                    cohorts={planningData.cohorts}
                                    practiceEvents={planningData.practiceEvents}
                                    qualifierEvents={planningData.qualifierEvents}
                                    practiceExplorers={planningData.practiceExplorers}
                                    qualifierExplorers={planningData.qualifierExplorers}
                                    onRefreshAll={planningData.fetchAllData}
                                    onFeedback={planningData.setFeedback}
                                    onOpenEvent={handleOpenEventById}
                                />
                            </ModuleErrorBoundary>
                        )}

                        {notifyModalOpts && (
                            <ModuleErrorBoundary moduleName="Place Offer Notifications">
                                <ManualNotifyModal
                                    isOpen={true}
                                    onClose={() => setNotifyModalOpts(null)}
                                    initialScope={notifyModalOpts.scope}
                                    cohortId={notifyModalOpts.cohortId ?? null}
                                    cohortName={notifyModalOpts.cohortName ?? null}
                                    dofeLevel={notifyModalOpts.level ?? planningLevel}
                                    transportMode={toCohortTransportMode(notifyModalOpts.transport ?? planningTransport)}
                                    onInspectExplorer={(target) => setDrawerTarget(target)}
                                    onDispatched={(res) => {
                                        const count = typeof res?.dispatched_count === 'number' ? res.dispatched_count : undefined;
                                        planningData.setFeedback({
                                            ok: true,
                                            msg: count !== undefined
                                                ? `Dispatched ${count} Place Offer email${count === 1 ? '' : 's'}.`
                                                : 'Place Offer emails dispatched.',
                                        });
                                        planningData.fetchAllData();
                                    }}
                                />
                            </ModuleErrorBoundary>
                        )}

                        <Explorer360Drawer
                            isOpen={drawerTarget !== null}
                            scoutId={drawerTarget?.scoutId && drawerTarget.scoutId > 0 ? drawerTarget.scoutId : undefined}
                            signupType={drawerTarget?.signupId ? 'expedition' : undefined}
                            signupId={drawerTarget?.signupId}
                            rootUrl={config.root_url}
                            nonce={config.nonce}
                            initialTab="teams"
                            onClose={() => setDrawerTarget(null)}
                            onUpdated={planningData.fetchAllData}
                        />
                    </div>
                )}
                {activeTab === 'readiness' && (
                    <ModuleErrorBoundary moduleName="Teams & Readiness">
                        <TeamsReadinessView
                            onOpenEvent={handleOpenEventById}
                            onOpenTeamBuilder={handleOpenTeamBuilder}
                        />
                    </ModuleErrorBoundary>
                )}
            </div>
        </div>
    );
};

export default ExpeditionBoard;
