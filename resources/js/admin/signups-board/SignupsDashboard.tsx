import React, { useState, useMemo } from 'react';
import {
    computeSignupsDashboardMetrics,
    computeWeeklyCumulativeSeries,
} from './dashboardMetrics';

export interface TableDrilldownFilters {
    typeFilter?: string;
    levelFilter?: string;
    expeditionTypeFilter?: string;
    anomalyFilter?: 'all' | 'no_expedition' | 'duplicate' | 'unmatched_osm';
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
    const [chartTypeFilter, setChartTypeFilter] = useState<string>('all');
    const [chartLevelFilter, setChartLevelFilter] = useState<string>('all');
    const [selectedSchoolYear, setSelectedSchoolYear] = useState<number | undefined>(undefined);
    const [hoveredWeekIdx, setHoveredWeekIdx] = useState<number | null>(null);

    const metrics = useMemo(() => computeSignupsDashboardMetrics(signups), [signups]);

    const cumulativeSeries = useMemo(
        () =>
            computeWeeklyCumulativeSeries(signups, {
                typeFilter: chartTypeFilter,
                levelFilter: chartLevelFilter,
                schoolYearStart: selectedSchoolYear,
            }),
        [signups, chartTypeFilter, chartLevelFilter, selectedSchoolYear]
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
        ...weeks.map((w) => w.cumulativeCount)
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

    const linePoints = weeks
        .map((w, idx) => `${getX(idx).toFixed(1)},${getY(w.cumulativeCount).toFixed(1)}`)
        .join(' ');

    const areaPath =
        weeks.length > 0
            ? `M ${getX(0).toFixed(1)} ${(padTop + plotHeight).toFixed(1)} ` +
              weeks
                  .map((w, idx) => `L ${getX(idx).toFixed(1)} ${getY(w.cumulativeCount).toFixed(1)}`)
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
                {/* 1. Participant Place Only (No Expedition) */}
                <div
                    className="ems-overview-card ems-dashboard-action-card ems-cursor-pointer"
                    data-testid="card-no-expedition"
                    onClick={() =>
                        onNavigateToTable({
                            typeFilter: 'participant',
                            anomalyFilter: 'no_expedition',
                        })
                    }
                >
                    <div className="ems-overview-card__title">
                        <span>⛺</span> Participant Place Only (No Expedition)
                    </div>
                    <div className="ems-dashboard-hero-row">
                        <div>
                            <div
                                className="ems-dashboard-hero-number"
                                data-testid="metric-no-expedition-total"
                            >
                                {metrics.participantWithoutExpedition.totalExplorers}
                            </div>
                            <div className="ems-meta-text">
                                Explorers signed up for a place without an expedition form
                            </div>
                        </div>
                        <span className="ems-table__link ems-small-text">Filter Table →</span>
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
                            School year {cumulativeSeries.schoolYearLabel} · Cumulative total:{' '}
                            <strong data-testid="chart-cumulative-total">
                                {cumulativeSeries.totalMatching}
                            </strong>
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
                                htmlFor="chart-type-filter"
                                className="ems-toolbar__label"
                            >
                                Chart Signup Type:
                            </label>
                            <select
                                id="chart-type-filter"
                                aria-label="Chart Signup Type"
                                className="ems-select-sm"
                                value={chartTypeFilter}
                                onChange={(e) => setChartTypeFilter(e.target.value)}
                            >
                                <option value="all">All Types</option>
                                <option value="participant">Participant Place</option>
                                <option value="expedition">Expedition Preference</option>
                            </select>
                        </div>

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
                            <strong>+{activeHoverWeek.weeklyCount}</strong> new in week
                        </span>{' '}
                        ·{' '}
                        <span>
                            <strong>{activeHoverWeek.cumulativeCount}</strong> cumulative signups
                        </span>
                    </div>
                )}

                {/* Native SVG Cumulative Chart */}
                <div className="ems-dashboard-chart-wrap">
                    <svg
                        className="ems-dashboard-chart-svg"
                        viewBox={`0 0 ${svgWidth} ${svgHeight}`}
                        role="img"
                        aria-label="Cumulative signups by week chart"
                    >
                        <defs>
                            <linearGradient id="emsCumulativeAreaGrad" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stopColor="#15803D" stopOpacity="0.28" />
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

                        {/* Weekly New Signups Bars (subtle background bars) */}
                        {weeks.map((w, idx) => {
                            if (w.weeklyCount <= 0) return null;
                            const x = getX(idx);
                            const barWidth = Math.max(4, Math.min(14, plotWidth / (weeks.length * 1.6)));
                            const barTop = getY(w.weeklyCount);
                            const barH = padTop + plotHeight - barTop;
                            return (
                                <rect
                                    key={`bar_${w.weekNumber}`}
                                    x={x - barWidth / 2}
                                    y={barTop}
                                    width={barWidth}
                                    height={barH}
                                    rx="2"
                                    fill="#cbd5e1"
                                    opacity="0.55"
                                />
                            );
                        })}

                        {/* Cumulative Area & Line */}
                        {areaPath && <path d={areaPath} fill="url(#emsCumulativeAreaGrad)" />}
                        {linePoints && (
                            <polyline
                                fill="none"
                                stroke="#15803D"
                                strokeWidth="2.5"
                                points={linePoints}
                            />
                        )}

                        {/* Data Points & X-Axis Labels */}
                        {weeks.map((w, idx) => {
                            const x = getX(idx);
                            const y = getY(w.cumulativeCount);
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
                                        cy={y}
                                        r={isHovered ? 5.5 : 3.5}
                                        fill={isHovered ? '#15803D' : '#ffffff'}
                                        stroke="#15803D"
                                        strokeWidth="2"
                                    >
                                        <title>
                                            {`Week ${w.weekNumber} (${w.rangeLabel}): +${w.weeklyCount} new, ${w.cumulativeCount} cumulative`}
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
