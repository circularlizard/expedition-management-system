import React, { useState, useMemo } from 'react';
import {
    computeSignupsDashboardMetrics,
    computeWeeklyCumulativeSeries,
} from './dashboardMetrics';

export interface TableDrilldownFilters {
    typeFilter?: string;
    levelFilter?: string;
    expeditionTypeFilter?: string;
    anomalyFilter?: 'all' | 'no_expedition' | 'no_participant' | 'duplicate' | 'unmatched_osm';
    grouping?: 'none' | 'unit' | 'level' | 'explorer' | 'parent';
}

interface SignupsDashboardProps {
    signups: any[];
    onNavigateToTable: (filters: TableDrilldownFilters) => void;
}

const ProgressBar: React.FC<{ value: number; max: number; variant?: string }> = ({
    value,
    max,
    variant = 'primary',
}) => {
    const pct = max > 0 ? Math.min(100, Math.round((value / max) * 100)) : 0;
    return (
        <svg
            className="ems-dashboard-progress"
            viewBox="0 0 100 6"
            preserveAspectRatio="none"
            aria-hidden="true"
        >
            <rect
                className="ems-dashboard-progress__track"
                x="0"
                y="0"
                width="100"
                height="6"
                rx="3"
            />
            <rect
                className={`ems-dashboard-progress__fill ems-dashboard-progress__fill--${variant}`}
                x="0"
                y="0"
                width={pct}
                height="6"
                rx="3"
            />
        </svg>
    );
};

export const SignupsDashboard: React.FC<SignupsDashboardProps> = ({
    signups,
    onNavigateToTable,
}) => {
    const [chartLevelFilter, setChartLevelFilter] = useState<string>('all');
    const [selectedSchoolYear, setSelectedSchoolYear] = useState<number | undefined>(undefined);
    const [hoveredWeekIdx, setHoveredWeekIdx] = useState<number | null>(null);

    const metrics = useMemo(() => computeSignupsDashboardMetrics(signups), [signups]);

    const cumulativeSeries = useMemo(
        () =>
            computeWeeklyCumulativeSeries(signups, {
                levelFilter: chartLevelFilter,
                schoolYearStart: selectedSchoolYear,
            }),
        [signups, chartLevelFilter, selectedSchoolYear]
    );

    const totalSignups = metrics.byType.total;
    const totalExpedSignups = metrics.byType.expedition;

    // SVG Chart dimensions and coordinate helpers
    const svgWidth = 860;
    const svgHeight = 260;
    const padLeft = 46;
    const padRight = 24;
    const padTop = 20;
    const padBottom = 42;
    const plotWidth = svgWidth - padLeft - padRight;
    const plotHeight = svgHeight - padTop - padBottom;

    const weeks = cumulativeSeries.weeks;
    const maxCumulative = Math.max(
        5,
        ...weeks.map((w) => Math.max(w.participantCumulativeCount, w.expeditionCumulativeCount))
    );

    const yTicks = useMemo(() => {
        const step = Math.max(1, Math.ceil(maxCumulative / 4));
        const ticks: number[] = [];
        for (let v = 0; v <= step * 4; v += step) {
            ticks.push(v);
        }
        return ticks;
    }, [maxCumulative]);

    const yMaxTick = yTicks[yTicks.length - 1] || 5;

    const getX = (idx: number): number => {
        if (weeks.length <= 1) return padLeft + plotWidth / 2;
        return padLeft + (idx / (weeks.length - 1)) * plotWidth;
    };

    const getY = (val: number): number => {
        return padTop + plotHeight - (val / yMaxTick) * plotHeight;
    };

    const participantLinePoints = weeks
        .map((w, idx) => `${getX(idx).toFixed(1)},${getY(w.participantCumulativeCount).toFixed(1)}`)
        .join(' ');

    const participantAreaPath =
        weeks.length > 0
            ? `M ${getX(0).toFixed(1)} ${(padTop + plotHeight).toFixed(1)} ` +
              weeks
                  .map((w, idx) => `L ${getX(idx).toFixed(1)} ${getY(w.participantCumulativeCount).toFixed(1)}`)
                  .join(' ') +
              ` L ${getX(weeks.length - 1).toFixed(1)} ${(padTop + plotHeight).toFixed(1)} Z`
            : '';

    const expeditionLinePoints = weeks
        .map((w, idx) => `${getX(idx).toFixed(1)},${getY(w.expeditionCumulativeCount).toFixed(1)}`)
        .join(' ');

    const expeditionAreaPath =
        weeks.length > 0
            ? `M ${getX(0).toFixed(1)} ${(padTop + plotHeight).toFixed(1)} ` +
              weeks
                  .map((w, idx) => `L ${getX(idx).toFixed(1)} ${getY(w.expeditionCumulativeCount).toFixed(1)}`)
                  .join(' ') +
              ` L ${getX(weeks.length - 1).toFixed(1)} ${(padTop + plotHeight).toFixed(1)} Z`
            : '';

    const labelStep = Math.max(1, Math.ceil(weeks.length / 10));
    const activeHoverWeek =
        hoveredWeekIdx !== null && weeks[hoveredWeekIdx]
            ? weeks[hoveredWeekIdx]
            : weeks.length > 0
            ? weeks[weeks.length - 1]
            : null;

    return (
        <div className="ems-signups-dashboard">
            {/* Row 1: Breakdown by Signup Type, Level, and Expedition Type */}
            <div className="ems-dashboard-grid-3">
                {/* 1. Breakdown by Signup Type */}
                <div className="ems-overview-card">
                    <div className="ems-overview-card__title">
                        <span>📋</span> Signups by Type
                    </div>
                    <div className="ems-dashboard-hero-row">
                        <div>
                            <div className="ems-dashboard-hero-number" data-testid="metric-type-total">
                                {totalSignups}
                            </div>
                            <div className="ems-meta-text">
                                Total signups ({metrics.byType.uniqueExplorers} unique explorers)
                            </div>
                        </div>
                        <button
                            type="button"
                            className="button button-small"
                            onClick={() => onNavigateToTable({ typeFilter: 'all', anomalyFilter: 'all' })}
                        >
                            View All →
                        </button>
                    </div>

                    <div className="ems-dashboard-stat-list">
                        <div
                            className="ems-dashboard-stat-item ems-cursor-pointer"
                            onClick={() =>
                                onNavigateToTable({ typeFilter: 'participant', anomalyFilter: 'all' })
                            }
                        >
                            <div className="ems-flex-between">
                                <span className="ems-font-semibold">
                                    <span className="ems-status-badge ems-status-badge--participant">
                                        Place
                                    </span>{' '}
                                    Participant Place
                                </span>
                                <strong data-testid="metric-type-participant">
                                    {metrics.byType.participant}
                                </strong>
                            </div>
                            <ProgressBar
                                value={metrics.byType.participant}
                                max={totalSignups}
                                variant="blue"
                            />
                        </div>

                        <div
                            className="ems-dashboard-stat-item ems-cursor-pointer"
                            onClick={() =>
                                onNavigateToTable({ typeFilter: 'expedition', anomalyFilter: 'all' })
                            }
                        >
                            <div className="ems-flex-between">
                                <span className="ems-font-semibold">
                                    <span className="ems-status-badge ems-status-badge--expedition">
                                        Exped
                                    </span>{' '}
                                    Expedition Preference
                                </span>
                                <strong data-testid="metric-type-expedition">
                                    {metrics.byType.expedition}
                                </strong>
                            </div>
                            <ProgressBar
                                value={metrics.byType.expedition}
                                max={totalSignups}
                                variant="green"
                            />
                        </div>
                    </div>
                </div>

                {/* 2. Breakdown by DofE Level */}
                <div className="ems-overview-card">
                    <div className="ems-overview-card__title">
                        <span>🏅</span> Signups by DofE Level
                    </div>
                    <div className="ems-dashboard-stat-list">
                        {(['bronze', 'silver', 'gold'] as const).map((lvl) => {
                            const data = metrics.byLevel[lvl];
                            const label = lvl.charAt(0).toUpperCase() + lvl.slice(1);
                            return (
                                <div
                                    key={lvl}
                                    className="ems-dashboard-stat-item ems-cursor-pointer"
                                    onClick={() =>
                                        onNavigateToTable({ levelFilter: lvl, anomalyFilter: 'all' })
                                    }
                                >
                                    <div className="ems-flex-between">
                                        <div className="ems-flex-center ems-gap-8">
                                            <span className={`ems-pill ems-pill--${lvl}`}>
                                                {label}
                                            </span>
                                            <span className="ems-meta-text ems-small-text">
                                                {data.participant} Place · {data.expedition} Exped
                                            </span>
                                        </div>
                                        <strong data-testid={`metric-level-${lvl}`}>
                                            {data.total}
                                        </strong>
                                    </div>
                                    <ProgressBar
                                        value={data.total}
                                        max={totalSignups}
                                        variant={lvl}
                                    />
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* 3. Breakdown by Expedition Type */}
                <div className="ems-overview-card">
                    <div className="ems-overview-card__title">
                        <span>🧭</span> Signups by Expedition Type
                    </div>
                    <div className="ems-dashboard-stat-list">
                        <div
                            className="ems-dashboard-stat-item ems-cursor-pointer"
                            onClick={() =>
                                onNavigateToTable({
                                    typeFilter: 'expedition',
                                    expeditionTypeFilter: 'hillwalking',
                                    anomalyFilter: 'all',
                                })
                            }
                        >
                            <div className="ems-flex-between">
                                <span className="ems-font-semibold">🥾 Hillwalking</span>
                                <strong data-testid="metric-exped-hillwalking">
                                    {metrics.byExpeditionType.hillwalking}
                                </strong>
                            </div>
                            <ProgressBar
                                value={metrics.byExpeditionType.hillwalking}
                                max={totalExpedSignups}
                                variant="green"
                            />
                        </div>

                        <div
                            className="ems-dashboard-stat-item ems-cursor-pointer"
                            onClick={() =>
                                onNavigateToTable({
                                    typeFilter: 'expedition',
                                    expeditionTypeFilter: 'biking',
                                    anomalyFilter: 'all',
                                })
                            }
                        >
                            <div className="ems-flex-between">
                                <span className="ems-font-semibold">🚲 Biking</span>
                                <strong data-testid="metric-exped-biking">
                                    {metrics.byExpeditionType.biking}
                                </strong>
                            </div>
                            <ProgressBar
                                value={metrics.byExpeditionType.biking}
                                max={totalExpedSignups}
                                variant="blue"
                            />
                        </div>

                        <div
                            className="ems-dashboard-stat-item ems-cursor-pointer"
                            onClick={() =>
                                onNavigateToTable({
                                    typeFilter: 'expedition',
                                    expeditionTypeFilter: 'paddling',
                                    anomalyFilter: 'all',
                                })
                            }
                        >
                            <div className="ems-flex-between">
                                <span className="ems-font-semibold">🛶 Paddling</span>
                                <strong data-testid="metric-exped-paddling">
                                    {metrics.byExpeditionType.paddling}
                                </strong>
                            </div>
                            <ProgressBar
                                value={metrics.byExpeditionType.paddling}
                                max={totalExpedSignups}
                                variant="amber"
                            />
                        </div>
                    </div>
                </div>
            </div>

            {/* Row 2: Follow-Up & Data Integrity Cards */}
            <div className="ems-dashboard-grid-3">
                {/* 1. Unpaired Signups: Place Only vs Expedition Only */}
                <div className="ems-overview-card ems-dashboard-action-card">
                    <div className="ems-overview-card__title">
                        <span>⛺</span> Unpaired Signups (Place vs Expedition)
                    </div>

                    {/* Case A: Participant Place Only (No Expedition) */}
                    <div
                        className="ems-dashboard-stat-item ems-cursor-pointer"
                        data-testid="card-no-expedition"
                        onClick={() =>
                            onNavigateToTable({
                                typeFilter: 'participant',
                                anomalyFilter: 'no_expedition',
                            })
                        }
                    >
                        <div className="ems-flex-between">
                            <div>
                                <div className="ems-font-semibold">
                                    Participant Place Only (No Expedition)
                                </div>
                                <div className="ems-meta-text ems-small-text">
                                    Signed up for a place without an expedition form
                                </div>
                            </div>
                            <div className="ems-flex-center ems-gap-8">
                                <span
                                    className="ems-dashboard-subhero-number"
                                    data-testid="metric-no-expedition-total"
                                >
                                    {metrics.participantWithoutExpedition.totalExplorers}
                                </span>
                                <span className="ems-table__link ems-small-text">Filter →</span>
                            </div>
                        </div>
                        <div className="ems-dashboard-pill-group">
                            <button
                                type="button"
                                className="ems-dashboard-mini-pill ems-pill--bronze"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    onNavigateToTable({
                                        typeFilter: 'participant',
                                        levelFilter: 'bronze',
                                        anomalyFilter: 'no_expedition',
                                    });
                                }}
                            >
                                Bronze:{' '}
                                <strong data-testid="metric-no-expedition-bronze">
                                    {metrics.participantWithoutExpedition.byLevel.bronze}
                                </strong>
                            </button>
                            <button
                                type="button"
                                className="ems-dashboard-mini-pill ems-pill--silver"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    onNavigateToTable({
                                        typeFilter: 'participant',
                                        levelFilter: 'silver',
                                        anomalyFilter: 'no_expedition',
                                    });
                                }}
                            >
                                Silver:{' '}
                                <strong data-testid="metric-no-expedition-silver">
                                    {metrics.participantWithoutExpedition.byLevel.silver}
                                </strong>
                            </button>
                            <button
                                type="button"
                                className="ems-dashboard-mini-pill ems-pill--gold"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    onNavigateToTable({
                                        typeFilter: 'participant',
                                        levelFilter: 'gold',
                                        anomalyFilter: 'no_expedition',
                                    });
                                }}
                            >
                                Gold:{' '}
                                <strong data-testid="metric-no-expedition-gold">
                                    {metrics.participantWithoutExpedition.byLevel.gold}
                                </strong>
                            </button>
                        </div>
                    </div>

                    {/* Case B: Expedition Only (No Participant Place) */}
                    <div
                        className="ems-dashboard-stat-item ems-cursor-pointer"
                        data-testid="card-no-participant"
                        onClick={() =>
                            onNavigateToTable({
                                typeFilter: 'expedition',
                                anomalyFilter: 'no_participant',
                            })
                        }
                    >
                        <div className="ems-flex-between">
                            <div>
                                <div className="ems-font-semibold">
                                    Expedition Only (No Participant Place)
                                </div>
                                <div className="ems-meta-text ems-small-text">
                                    Signed up for an expedition without a participant place form
                                </div>
                            </div>
                            <div className="ems-flex-center ems-gap-8">
                                <span
                                    className="ems-dashboard-subhero-number"
                                    data-testid="metric-no-participant-total"
                                >
                                    {metrics.expeditionWithoutParticipant.totalExplorers}
                                </span>
                                <span className="ems-table__link ems-small-text">Filter →</span>
                            </div>
                        </div>
                        <div className="ems-dashboard-pill-group">
                            <button
                                type="button"
                                className="ems-dashboard-mini-pill ems-pill--bronze"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    onNavigateToTable({
                                        typeFilter: 'expedition',
                                        levelFilter: 'bronze',
                                        anomalyFilter: 'no_participant',
                                    });
                                }}
                            >
                                Bronze:{' '}
                                <strong data-testid="metric-no-participant-bronze">
                                    {metrics.expeditionWithoutParticipant.byLevel.bronze}
                                </strong>
                            </button>
                            <button
                                type="button"
                                className="ems-dashboard-mini-pill ems-pill--silver"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    onNavigateToTable({
                                        typeFilter: 'expedition',
                                        levelFilter: 'silver',
                                        anomalyFilter: 'no_participant',
                                    });
                                }}
                            >
                                Silver:{' '}
                                <strong data-testid="metric-no-participant-silver">
                                    {metrics.expeditionWithoutParticipant.byLevel.silver}
                                </strong>
                            </button>
                            <button
                                type="button"
                                className="ems-dashboard-mini-pill ems-pill--gold"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    onNavigateToTable({
                                        typeFilter: 'expedition',
                                        levelFilter: 'gold',
                                        anomalyFilter: 'no_participant',
                                    });
                                }}
                            >
                                Gold:{' '}
                                <strong data-testid="metric-no-participant-gold">
                                    {metrics.expeditionWithoutParticipant.byLevel.gold}
                                </strong>
                            </button>
                        </div>
                    </div>

                    <div className="ems-meta-text ems-small-text">
                        Complete pairs (both Place &amp; Exped):{' '}
                        <strong>{metrics.pairedExplorers.totalExplorers}</strong> (Bronze:{' '}
                        {metrics.pairedExplorers.byLevel.bronze}, Silver:{' '}
                        {metrics.pairedExplorers.byLevel.silver}, Gold:{' '}
                        {metrics.pairedExplorers.byLevel.gold})
                    </div>
                </div>

                {/* 2. Duplicate Signups Summary */}
                <div
                    className="ems-overview-card ems-dashboard-action-card ems-cursor-pointer"
                    data-testid="card-duplicates"
                    onClick={() =>
                        onNavigateToTable({
                            typeFilter: 'all',
                            anomalyFilter: 'duplicate',
                            grouping: 'explorer',
                        })
                    }
                >
                    <div className="ems-overview-card__title">
                        <span>📑</span> Duplicate Signups
                    </div>
                    <div className="ems-dashboard-hero-row">
                        <div>
                            <div className="ems-flex-center ems-gap-8">
                                <span
                                    className="ems-dashboard-hero-number"
                                    data-testid="metric-duplicates-groups"
                                >
                                    {metrics.duplicates.groupsCount}
                                </span>
                                <span className="ems-badge ems-badge--warning">
                                    <span data-testid="metric-duplicates-rows">
                                        {metrics.duplicates.rowsCount}
                                    </span>{' '}
                                    records
                                </span>
                            </div>
                            <div className="ems-meta-text">
                                Explorers with multiple active signups for the same form &amp; level
                            </div>
                        </div>
                        <span className="ems-table__link ems-small-text">Review →</span>
                    </div>
                    <div className="ems-dashboard-pill-group">
                        <span className="ems-event-detail__stat-pill">
                            Place Duplicates: <strong>{metrics.duplicates.participantGroups}</strong>
                        </span>
                        <span className="ems-event-detail__stat-pill">
                            Exped Duplicates: <strong>{metrics.duplicates.expeditionGroups}</strong>
                        </span>
                    </div>
                </div>

                {/* 3. No Synced OSM Record */}
                <div
                    className="ems-overview-card ems-dashboard-action-card ems-cursor-pointer"
                    data-testid="card-unmatched-osm"
                    onClick={() =>
                        onNavigateToTable({
                            typeFilter: 'all',
                            anomalyFilter: 'unmatched_osm',
                        })
                    }
                >
                    <div className="ems-overview-card__title">
                        <span>⚠️</span> No Synced OSM Record
                    </div>
                    <div className="ems-dashboard-hero-row">
                        <div>
                            <div
                                className="ems-dashboard-hero-number"
                                data-testid="metric-unmatched-osm"
                            >
                                {metrics.unmatchedOsm.rowsCount}
                            </div>
                            <div className="ems-meta-text">
                                Unmatched signups across {metrics.unmatchedOsm.explorersCount} explorers
                            </div>
                        </div>
                        <span className="ems-table__link ems-small-text">Reconcile →</span>
                    </div>
                    <div className="ems-dashboard-pill-group">
                        <span className="ems-badge ems-badge--allocated">
                            Requires OSM profile linkage before slot/team allocation
                        </span>
                    </div>
                </div>
            </div>

            {/* Row 3: Cumulative Signups by Week Chart */}
            <div className="ems-overview-card">
                <div className="ems-dashboard-chart-header">
                    <div>
                        <h3 className="ems-m-0 ems-font-semibold">
                            📈 Cumulative Signups by Week
                        </h3>
                        <div className="ems-meta-text ems-mt-4">
                            School year {cumulativeSeries.schoolYearLabel}
                        </div>
                        <div className="ems-flex-center ems-gap-16 ems-mt-8 ems-wrap">
                            <span className="ems-flex-center ems-gap-6 ems-small-text">
                                <span className="ems-status-badge ems-status-badge--participant">
                                    Place
                                </span>
                                Participant Place:{' '}
                                <strong data-testid="chart-cumulative-participant">
                                    {cumulativeSeries.totalParticipant}
                                </strong>
                            </span>
                            <span className="ems-flex-center ems-gap-6 ems-small-text">
                                <span className="ems-status-badge ems-status-badge--expedition">
                                    Exped
                                </span>
                                Expedition Preference:{' '}
                                <strong data-testid="chart-cumulative-expedition">
                                    {cumulativeSeries.totalExpedition}
                                </strong>
                            </span>
                        </div>
                    </div>

                    <div className="ems-flex-center ems-gap-12 ems-wrap">
                        {cumulativeSeries.availableSchoolYears.length > 1 && (
                            <div className="ems-flex-center ems-gap-6">
                                <label
                                    htmlFor="chart-year-filter"
                                    className="ems-toolbar__label"
                                >
                                    School Year:
                                </label>
                                <select
                                    id="chart-year-filter"
                                    aria-label="School Year"
                                    className="ems-select-sm"
                                    value={cumulativeSeries.schoolYearStart}
                                    onChange={(e) =>
                                        setSelectedSchoolYear(parseInt(e.target.value, 10))
                                    }
                                >
                                    {cumulativeSeries.availableSchoolYears.map((yr) => (
                                        <option key={yr} value={yr}>
                                            {yr}/{String(yr + 1).slice(-2)} (from 1 Aug {yr})
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}

                        <div className="ems-flex-center ems-gap-6">
                            <label
                                htmlFor="chart-level-filter"
                                className="ems-toolbar__label"
                            >
                                Chart Level:
                            </label>
                            <select
                                id="chart-level-filter"
                                aria-label="Chart Level"
                                className="ems-select-sm"
                                value={chartLevelFilter}
                                onChange={(e) => setChartLevelFilter(e.target.value)}
                            >
                                <option value="all">All Levels</option>
                                <option value="bronze">Bronze</option>
                                <option value="silver">Silver</option>
                                <option value="gold">Gold</option>
                            </select>
                        </div>
                    </div>
                </div>

                {/* Hover / Active Week Summary Bar */}
                {activeHoverWeek && (
                    <div className="ems-dashboard-chart-Callout">
                        <span className="ems-font-semibold">
                            Week {activeHoverWeek.weekNumber} ({activeHoverWeek.rangeLabel}):
                        </span>{' '}
                        <span>
                            Participant Place:{' '}
                            <strong>{activeHoverWeek.participantCumulativeCount}</strong> cumulative (
                            +{activeHoverWeek.participantWeeklyCount} new)
                        </span>{' '}
                        ·{' '}
                        <span>
                            Expedition Preference:{' '}
                            <strong>{activeHoverWeek.expeditionCumulativeCount}</strong> cumulative (
                            +{activeHoverWeek.expeditionWeeklyCount} new)
                        </span>
                    </div>
                )}

                {/* Native SVG Cumulative Chart (2 Lines) */}
                <div className="ems-dashboard-chart-wrap">
                    <svg
                        className="ems-dashboard-chart-svg"
                        viewBox={`0 0 ${svgWidth} ${svgHeight}`}
                        role="img"
                        aria-label="Cumulative signups by week chart"
                    >
                        <defs>
                            <linearGradient id="emsParticipantAreaGrad" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stopColor="#2271b1" stopOpacity="0.22" />
                                <stop offset="100%" stopColor="#2271b1" stopOpacity="0.02" />
                            </linearGradient>
                            <linearGradient id="emsExpeditionAreaGrad" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stopColor="#15803D" stopOpacity="0.22" />
                                <stop offset="100%" stopColor="#15803D" stopOpacity="0.02" />
                            </linearGradient>
                        </defs>

                        {/* Horizontal Gridlines & Y-Axis Labels */}
                        {yTicks.map((tickVal) => {
                            const y = getY(tickVal);
                            return (
                                <g key={`ytick_${tickVal}`}>
                                    <line
                                        x1={padLeft}
                                        y1={y}
                                        x2={svgWidth - padRight}
                                        y2={y}
                                        stroke="#e7e5e4"
                                        strokeDasharray={tickVal === 0 ? undefined : '4 4'}
                                        strokeWidth="1"
                                    />
                                    <text
                                        x={padLeft - 8}
                                        y={y + 4}
                                        textAnchor="end"
                                        fontSize="11"
                                        fill="#646970"
                                    >
                                        {tickVal}
                                    </text>
                                </g>
                            );
                        })}

                        {/* Participant Place Cumulative Area & Line (Blue) */}
                        {participantAreaPath && (
                            <path d={participantAreaPath} fill="url(#emsParticipantAreaGrad)" />
                        )}
                        {participantLinePoints && (
                            <polyline
                                data-testid="chart-line-participant"
                                fill="none"
                                stroke="#2271b1"
                                strokeWidth="2.5"
                                points={participantLinePoints}
                            />
                        )}

                        {/* Expedition Preference Cumulative Area & Line (Green) */}
                        {expeditionAreaPath && (
                            <path d={expeditionAreaPath} fill="url(#emsExpeditionAreaGrad)" />
                        )}
                        {expeditionLinePoints && (
                            <polyline
                                data-testid="chart-line-expedition"
                                fill="none"
                                stroke="#15803D"
                                strokeWidth="2.5"
                                points={expeditionLinePoints}
                            />
                        )}

                        {/* Data Points & X-Axis Labels */}
                        {weeks.map((w, idx) => {
                            const x = getX(idx);
                            const yParticipant = getY(w.participantCumulativeCount);
                            const yExpedition = getY(w.expeditionCumulativeCount);
                            const isHovered = hoveredWeekIdx === idx;
                            const showLabel = idx === 0 || idx === weeks.length - 1 || idx % labelStep === 0;

                            return (
                                <g
                                    key={`pt_${w.weekNumber}`}
                                    className="ems-cursor-pointer"
                                    onMouseEnter={() => setHoveredWeekIdx(idx)}
                                    onMouseLeave={() => setHoveredWeekIdx(null)}
                                >
                                    <circle
                                        cx={x}
                                        cy={yParticipant}
                                        r={isHovered ? 5 : 3.2}
                                        fill={isHovered ? '#2271b1' : '#ffffff'}
                                        stroke="#2271b1"
                                        strokeWidth="2"
                                    >
                                        <title>
                                            {`Week ${w.weekNumber} (${w.rangeLabel}) — Participant Place: +${w.participantWeeklyCount} new, ${w.participantCumulativeCount} cumulative`}
                                        </title>
                                    </circle>
                                    <circle
                                        cx={x}
                                        cy={yExpedition}
                                        r={isHovered ? 5 : 3.2}
                                        fill={isHovered ? '#15803D' : '#ffffff'}
                                        stroke="#15803D"
                                        strokeWidth="2"
                                    >
                                        <title>
                                            {`Week ${w.weekNumber} (${w.rangeLabel}) — Expedition Preference: +${w.expeditionWeeklyCount} new, ${w.expeditionCumulativeCount} cumulative`}
                                        </title>
                                    </circle>
                                    {showLabel && (
                                        <text
                                            x={x}
                                            y={padTop + plotHeight + 22}
                                            textAnchor="middle"
                                            fontSize="10"
                                            fill="#646970"
                                        >
                                            {w.label}
                                        </text>
                                    )}
                                </g>
                            );
                        })}
                    </svg>
                </div>
            </div>
        </div>
    );
};

export default SignupsDashboard;
