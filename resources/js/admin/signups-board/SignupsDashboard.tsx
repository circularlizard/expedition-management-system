import React, { useState, useMemo } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faClipboardList,
    faAward,
    faCompass,
    faPersonHiking,
    faBicycle,
    faWater,
    faCampground,
    faFileLines,
    faTriangleExclamation,
    faChartLine
} from '@fortawesome/free-solid-svg-icons';
import {
    computeSignupsDashboardMetrics,
    computeWeeklyCumulativeSeries,
    computeExpeditionDistrictUnitMatrix,
} from './dashboardMetrics';
import { getUnitDistrictMap } from './tableUtils';

export interface TableDrilldownFilters {
    typeFilter?: string;
    levelFilter?: string;
    expeditionTypeFilter?: string;
    unitFilter?: string;
    districtFilter?: string;
    anomalyFilter?: 'all' | 'no_expedition' | 'no_participant' | 'duplicate' | 'unmatched_osm';
    grouping?: 'none' | 'unit' | 'level' | 'explorer' | 'parent';
}

interface SignupsDashboardProps {
    signups: any[];
    units?: any[];
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
    units,
    onNavigateToTable,
}) => {
    const [chartLevelFilter, setChartLevelFilter] = useState<string>('all');
    const [selectedSchoolYear, setSelectedSchoolYear] = useState<number | undefined>(undefined);
    const [hoveredWeekIdx, setHoveredWeekIdx] = useState<number | null>(null);
    const [matrixDistrictFilter, setMatrixDistrictFilter] = useState<string>('all');
    const [matrixSearchQuery, setMatrixSearchQuery] = useState<string>('');
    const [matrixTypeFilter, setMatrixTypeFilter] = useState<'all' | 'expedition' | 'participant'>('all');

    const renderCountBtn = (
        count: number,
        drilldown: TableDrilldownFilters,
        _testid?: string,
        isBold = false
    ) => {
        if (count <= 0) {
            return <span className="ems-matrix-muted-zero">0</span>;
        }
        return (
            <button
                type="button"
                className={`ems-matrix-btn-cell ${isBold ? 'ems-font-bold' : ''}`}
                onClick={() => onNavigateToTable(drilldown)}
            >
                {count}
            </button>
        );
    };

    const allUnits = units && units.length > 0 ? units : (typeof window !== 'undefined' && window.emsSignupsBoard?.units) ? window.emsSignupsBoard.units : [];
    const unitDistrictMap = useMemo(() => getUnitDistrictMap(allUnits), [allUnits]);

    const metrics = useMemo(() => computeSignupsDashboardMetrics(signups), [signups]);
    const matrixData = useMemo(() => computeExpeditionDistrictUnitMatrix(signups, unitDistrictMap), [signups, unitDistrictMap]);

    const filteredMatrixGroups = useMemo(() => {
        let groups = matrixData.groups;
        if (matrixDistrictFilter !== 'all') {
            groups = groups.filter((g) => g.district.toLowerCase() === matrixDistrictFilter.toLowerCase());
        }
        if (matrixSearchQuery.trim()) {
            const q = matrixSearchQuery.trim().toLowerCase();
            groups = groups
                .map((g) => ({
                    ...g,
                    units: g.units.filter((u) => u.unit.toLowerCase().includes(q) || g.district.toLowerCase().includes(q)),
                }))
                .filter((g) => g.units.length > 0);
        }
        return groups;
    }, [matrixData.groups, matrixDistrictFilter, matrixSearchQuery]);

    const availableMatrixDistricts = useMemo(() => {
        return matrixData.groups.map((g) => g.district);
    }, [matrixData.groups]);

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
                        <span><FontAwesomeIcon icon={faClipboardList} /></span> Signups by Type
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
                        <span><FontAwesomeIcon icon={faAward} /></span> Signups by DofE Level
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
                        <span><FontAwesomeIcon icon={faCompass} /></span> Signups by Expedition Type
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
                                <span className="ems-font-semibold">
                                    <FontAwesomeIcon icon={faPersonHiking} className="ems-pill-icon" /> Hillwalking
                                </span>
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
                                <span className="ems-font-semibold">
                                    <FontAwesomeIcon icon={faBicycle} className="ems-pill-icon" /> Biking
                                </span>
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
                                <span className="ems-font-semibold">
                                    <FontAwesomeIcon icon={faWater} className="ems-pill-icon" /> Paddling
                                </span>
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
                        <span><FontAwesomeIcon icon={faCampground} /></span> Unpaired Signups (Place vs Expedition)
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
                        <span><FontAwesomeIcon icon={faFileLines} /></span> Duplicate Signups
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
                        <span><FontAwesomeIcon icon={faTriangleExclamation} /></span> No Synced OSM Record
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
                            <FontAwesomeIcon icon={faChartLine} className="ems-btn-icon" /> Cumulative Signups by Week
                        </h3>
                        <div className="ems-meta-text ems-mt-4">
                            School year {cumulativeSeries.schoolYearLabel}
                        </div>
                    </div>

                    <div className="ems-flex-center ems-gap-12 ems-wrap">
                        {/* Chart Legend */}
                        <div
                            className="ems-dashboard-chart-legend"
                            data-testid="chart-legend"
                            aria-label="Chart Legend"
                        >
                            <div
                                className="ems-dashboard-chart-legend__item"
                                data-testid="chart-legend-participant"
                            >
                                <svg
                                    className="ems-dashboard-chart-legend__swatch"
                                    viewBox="0 0 28 12"
                                    aria-hidden="true"
                                >
                                    <line
                                        x1="2"
                                        y1="6"
                                        x2="26"
                                        y2="6"
                                        stroke="#2271b1"
                                        strokeWidth="2.5"
                                    />
                                    <circle
                                        cx="14"
                                        cy="6"
                                        r="3.5"
                                        fill="#ffffff"
                                        stroke="#2271b1"
                                        strokeWidth="2"
                                    />
                                </svg>
                                <span>
                                    Participant Place (
                                    <strong data-testid="chart-cumulative-participant">
                                        {cumulativeSeries.totalParticipant}
                                    </strong>
                                    )
                                </span>
                            </div>

                            <div
                                className="ems-dashboard-chart-legend__item"
                                data-testid="chart-legend-expedition"
                            >
                                <svg
                                    className="ems-dashboard-chart-legend__swatch"
                                    viewBox="0 0 28 12"
                                    aria-hidden="true"
                                >
                                    <line
                                        x1="2"
                                        y1="6"
                                        x2="26"
                                        y2="6"
                                        stroke="#15803D"
                                        strokeWidth="2.5"
                                    />
                                    <circle
                                        cx="14"
                                        cy="6"
                                        r="3.5"
                                        fill="#ffffff"
                                        stroke="#15803D"
                                        strokeWidth="2"
                                    />
                                </svg>
                                <span>
                                    Expedition Preference (
                                    <strong data-testid="chart-cumulative-expedition">
                                        {cumulativeSeries.totalExpedition}
                                    </strong>
                                    )
                                </span>
                            </div>
                        </div>

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

            {/* Row 4: Unit Summary Matrix Table */}
            <div className="ems-overview-card" data-testid="expedition-signups-matrix-card">
                <div className="ems-dashboard-chart-header">
                    <div>
                        <div className="ems-overview-card__title">
                            <span><FontAwesomeIcon icon={faCompass} /></span> Unit Summary
                        </div>
                        <div className="ems-meta-text">
                            Breakdown of explorer registrations across scouting districts and explorer units split by DofE level and expedition mode.
                        </div>
                    </div>

                    <div className="ems-matrix-toolbar-controls">
                        <div className="ems-flex-center ems-gap-6">
                            <span className="ems-toolbar__label">View:</span>
                            <label className={`ems-filter-pill ${matrixTypeFilter === 'all' ? 'ems-filter-pill--active' : ''}`}>
                                <input
                                    type="radio"
                                    name="adminMatrixTypeFilter"
                                    value="all"
                                    checked={matrixTypeFilter === 'all'}
                                    onChange={() => setMatrixTypeFilter('all')}
                                />
                                All Signups
                            </label>
                            <label className={`ems-filter-pill ${matrixTypeFilter === 'expedition' ? 'ems-filter-pill--active' : ''}`}>
                                <input
                                    type="radio"
                                    name="adminMatrixTypeFilter"
                                    value="expedition"
                                    checked={matrixTypeFilter === 'expedition'}
                                    onChange={() => setMatrixTypeFilter('expedition')}
                                />
                                Expedition Preferences
                            </label>
                            <label className={`ems-filter-pill ${matrixTypeFilter === 'participant' ? 'ems-filter-pill--active' : ''}`}>
                                <input
                                    type="radio"
                                    name="adminMatrixTypeFilter"
                                    value="participant"
                                    checked={matrixTypeFilter === 'participant'}
                                    onChange={() => setMatrixTypeFilter('participant')}
                                />
                                Participant Places
                            </label>
                        </div>

                        {availableMatrixDistricts.length > 1 && (
                            <div className="ems-flex-center ems-gap-6">
                                <label htmlFor="matrix-district-filter" className="ems-toolbar__label">
                                    District:
                                </label>
                                <select
                                    id="matrix-district-filter"
                                    aria-label="Filter matrix by district"
                                    className="ems-select-sm"
                                    value={matrixDistrictFilter}
                                    onChange={(e) => setMatrixDistrictFilter(e.target.value)}
                                >
                                    <option value="all">All Districts ({availableMatrixDistricts.length})</option>
                                    {availableMatrixDistricts.map((d) => (
                                        <option key={d} value={d}>
                                            {d}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}

                        <div className="ems-flex-center ems-gap-6">
                            <label htmlFor="matrix-unit-search" className="ems-toolbar__label">
                                Search:
                            </label>
                            <input
                                id="matrix-unit-search"
                                type="text"
                                aria-label="Search units in matrix"
                                placeholder="Search units..."
                                className="ems-input-sm"
                                value={matrixSearchQuery}
                                onChange={(e) => setMatrixSearchQuery(e.target.value)}
                            />
                        </div>
                    </div>
                </div>

                {(matrixTypeFilter === 'expedition'
                    ? matrixData.totalExpeditions === 0
                    : matrixTypeFilter === 'participant'
                    ? matrixData.totalParticipant === 0
                    : matrixData.totalSignups === 0) ? (
                    <div className="ems-empty-state ems-mt-16">
                        <FontAwesomeIcon icon={faCampground} className="ems-empty-state__icon" />
                        <p className="ems-empty-state__text">No signups found.</p>
                    </div>
                ) : (
                    <div className="ems-matrix-table-wrap">
                        <table
                            className="ems-matrix-table"
                            data-testid="expedition-district-unit-matrix"
                            aria-label="Expedition signups by district and unit"
                        >
                            <thead>
                                {matrixTypeFilter === 'expedition' ? (
                                    <>
                                        <tr>
                                            <th rowSpan={2} scope="col">District</th>
                                            <th rowSpan={2} scope="col">Unit</th>
                                            <th colSpan={3} scope="colgroup" className="ems-matrix-th-level ems-matrix-th-bronze">
                                                <FontAwesomeIcon icon={faAward} /> Bronze
                                            </th>
                                            <th colSpan={3} scope="colgroup" className="ems-matrix-th-level ems-matrix-th-silver">
                                                <FontAwesomeIcon icon={faAward} /> Silver
                                            </th>
                                            <th colSpan={3} scope="colgroup" className="ems-matrix-th-level ems-matrix-th-gold">
                                                <FontAwesomeIcon icon={faAward} /> Gold
                                            </th>
                                            <th rowSpan={2} scope="col" className="ems-matrix-th-level">Total</th>
                                        </tr>
                                        <tr>
                                            <th scope="col" className="ems-matrix-th-sub"><FontAwesomeIcon icon={faPersonHiking} /> HW</th>
                                            <th scope="col" className="ems-matrix-th-sub"><FontAwesomeIcon icon={faBicycle} /> Bike</th>
                                            <th scope="col" className="ems-matrix-th-sub"><FontAwesomeIcon icon={faWater} /> Paddle</th>
                                            <th scope="col" className="ems-matrix-th-sub"><FontAwesomeIcon icon={faPersonHiking} /> HW</th>
                                            <th scope="col" className="ems-matrix-th-sub"><FontAwesomeIcon icon={faBicycle} /> Bike</th>
                                            <th scope="col" className="ems-matrix-th-sub"><FontAwesomeIcon icon={faWater} /> Paddle</th>
                                            <th scope="col" className="ems-matrix-th-sub"><FontAwesomeIcon icon={faPersonHiking} /> HW</th>
                                            <th scope="col" className="ems-matrix-th-sub"><FontAwesomeIcon icon={faBicycle} /> Bike</th>
                                            <th scope="col" className="ems-matrix-th-sub"><FontAwesomeIcon icon={faWater} /> Paddle</th>
                                        </tr>
                                    </>
                                ) : matrixTypeFilter === 'participant' ? (
                                    <tr>
                                        <th scope="col">District</th>
                                        <th scope="col">Unit</th>
                                        <th scope="col" className="ems-matrix-th-level ems-matrix-th-bronze">
                                            <FontAwesomeIcon icon={faAward} /> Bronze
                                        </th>
                                        <th scope="col" className="ems-matrix-th-level ems-matrix-th-silver">
                                            <FontAwesomeIcon icon={faAward} /> Silver
                                        </th>
                                        <th scope="col" className="ems-matrix-th-level ems-matrix-th-gold">
                                            <FontAwesomeIcon icon={faAward} /> Gold
                                        </th>
                                        <th scope="col" className="ems-matrix-th-level">Total</th>
                                    </tr>
                                ) : (
                                    <>
                                        <tr>
                                            <th rowSpan={2} scope="col">District</th>
                                            <th rowSpan={2} scope="col">Unit</th>
                                            <th colSpan={2} scope="colgroup" className="ems-matrix-th-level ems-matrix-th-bronze">
                                                <FontAwesomeIcon icon={faAward} /> Bronze
                                            </th>
                                            <th colSpan={2} scope="colgroup" className="ems-matrix-th-level ems-matrix-th-silver">
                                                <FontAwesomeIcon icon={faAward} /> Silver
                                            </th>
                                            <th colSpan={2} scope="colgroup" className="ems-matrix-th-level ems-matrix-th-gold">
                                                <FontAwesomeIcon icon={faAward} /> Gold
                                            </th>
                                            <th rowSpan={2} scope="col" className="ems-matrix-th-level">Total</th>
                                        </tr>
                                        <tr>
                                            <th scope="col" className="ems-matrix-th-sub">Places</th>
                                            <th scope="col" className="ems-matrix-th-sub">Exped</th>
                                            <th scope="col" className="ems-matrix-th-sub">Places</th>
                                            <th scope="col" className="ems-matrix-th-sub">Exped</th>
                                            <th scope="col" className="ems-matrix-th-sub">Places</th>
                                            <th scope="col" className="ems-matrix-th-sub">Exped</th>
                                        </tr>
                                    </>
                                )}
                            </thead>
                            <tbody>
                                {filteredMatrixGroups.map((group) => (
                                    <React.Fragment key={group.district}>
                                        {/* District Subtotal (Positioned ABOVE the unit rows) */}
                                        <tr
                                            key={`subtotal_${group.district}`}
                                            className="ems-matrix-row-subtotal ems-table-group-header"
                                            data-testid={`matrix-subtotal-${group.district}`}
                                        >
                                            <td colSpan={2} className="ems-matrix-col-subtotal-label">
                                                <div className="ems-table-group-header__content">
                                                    <span className="ems-table-group-header__title">{group.district} Subtotal</span>
                                                    <span className="ems-table-group-header__badge">
                                                        {group.units.length} {group.units.length === 1 ? 'unit' : 'units'}
                                                    </span>
                                                </div>
                                            </td>
                                            {matrixTypeFilter === 'expedition' ? (
                                                <>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-bronze-hillwalking">
                                                        {renderCountBtn(group.subtotal.counts.bronze.hillwalking, { typeFilter: 'expedition', levelFilter: 'bronze', expeditionTypeFilter: 'hillwalking', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-bronze-biking">
                                                        {renderCountBtn(group.subtotal.counts.bronze.biking, { typeFilter: 'expedition', levelFilter: 'bronze', expeditionTypeFilter: 'biking', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-bronze-paddling">
                                                        {renderCountBtn(group.subtotal.counts.bronze.paddling, { typeFilter: 'expedition', levelFilter: 'bronze', expeditionTypeFilter: 'paddling', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>

                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-silver-hillwalking">
                                                        {renderCountBtn(group.subtotal.counts.silver.hillwalking, { typeFilter: 'expedition', levelFilter: 'silver', expeditionTypeFilter: 'hillwalking', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-silver-biking">
                                                        {renderCountBtn(group.subtotal.counts.silver.biking, { typeFilter: 'expedition', levelFilter: 'silver', expeditionTypeFilter: 'biking', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-silver-paddling">
                                                        {renderCountBtn(group.subtotal.counts.silver.paddling, { typeFilter: 'expedition', levelFilter: 'silver', expeditionTypeFilter: 'paddling', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>

                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-gold-hillwalking">
                                                        {renderCountBtn(group.subtotal.counts.gold.hillwalking, { typeFilter: 'expedition', levelFilter: 'gold', expeditionTypeFilter: 'hillwalking', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-gold-biking">
                                                        {renderCountBtn(group.subtotal.counts.gold.biking, { typeFilter: 'expedition', levelFilter: 'gold', expeditionTypeFilter: 'biking', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-gold-paddling">
                                                        {renderCountBtn(group.subtotal.counts.gold.paddling, { typeFilter: 'expedition', levelFilter: 'gold', expeditionTypeFilter: 'paddling', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>

                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-total">
                                                        {renderCountBtn(group.subtotal.expeditionTotal, { typeFilter: 'expedition', districtFilter: group.district, anomalyFilter: 'all' }, undefined, true)}
                                                    </td>
                                                </>
                                            ) : matrixTypeFilter === 'participant' ? (
                                                <>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-bronze">
                                                        {renderCountBtn(group.subtotal.counts.bronze.participant, { typeFilter: 'participant', levelFilter: 'bronze', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-silver">
                                                        {renderCountBtn(group.subtotal.counts.silver.participant, { typeFilter: 'participant', levelFilter: 'silver', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-gold">
                                                        {renderCountBtn(group.subtotal.counts.gold.participant, { typeFilter: 'participant', levelFilter: 'gold', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-total">
                                                        {renderCountBtn(group.subtotal.participantTotal, { typeFilter: 'participant', districtFilter: group.district, anomalyFilter: 'all' }, undefined, true)}
                                                    </td>
                                                </>
                                            ) : (
                                                <>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-bronze-participant">
                                                        {renderCountBtn(group.subtotal.counts.bronze.participant, { typeFilter: 'participant', levelFilter: 'bronze', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-bronze-expedition">
                                                        {renderCountBtn(group.subtotal.counts.bronze.expedition, { typeFilter: 'expedition', levelFilter: 'bronze', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-silver-participant">
                                                        {renderCountBtn(group.subtotal.counts.silver.participant, { typeFilter: 'participant', levelFilter: 'silver', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-silver-expedition">
                                                        {renderCountBtn(group.subtotal.counts.silver.expedition, { typeFilter: 'expedition', levelFilter: 'silver', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-gold-participant">
                                                        {renderCountBtn(group.subtotal.counts.gold.participant, { typeFilter: 'participant', levelFilter: 'gold', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-gold-expedition">
                                                        {renderCountBtn(group.subtotal.counts.gold.expedition, { typeFilter: 'expedition', levelFilter: 'gold', districtFilter: group.district, anomalyFilter: 'all' })}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="subtotal-total">
                                                        {renderCountBtn(group.subtotal.total, { typeFilter: 'all', districtFilter: group.district, anomalyFilter: 'all' }, undefined, true)}
                                                    </td>
                                                </>
                                            )}
                                        </tr>

                                        {/* Unit Rows */}
                                        {group.units.map((row) => (
                                            <tr key={`${group.district}_${row.unit}`} data-testid={`matrix-row-${row.unit}`}>
                                                <th scope="row" className="ems-matrix-col-district">{group.district}</th>
                                                <td className="ems-matrix-col-unit">{row.unit}</td>

                                                {matrixTypeFilter === 'expedition' ? (
                                                    <>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-bronze-hillwalking">
                                                            {renderCountBtn(row.counts.bronze.hillwalking, { typeFilter: 'expedition', levelFilter: 'bronze', expeditionTypeFilter: 'hillwalking', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-bronze-biking">
                                                            {renderCountBtn(row.counts.bronze.biking, { typeFilter: 'expedition', levelFilter: 'bronze', expeditionTypeFilter: 'biking', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-bronze-paddling">
                                                            {renderCountBtn(row.counts.bronze.paddling, { typeFilter: 'expedition', levelFilter: 'bronze', expeditionTypeFilter: 'paddling', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>

                                                        <td className="ems-matrix-cell-number" data-testid="cell-silver-hillwalking">
                                                            {renderCountBtn(row.counts.silver.hillwalking, { typeFilter: 'expedition', levelFilter: 'silver', expeditionTypeFilter: 'hillwalking', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-silver-biking">
                                                            {renderCountBtn(row.counts.silver.biking, { typeFilter: 'expedition', levelFilter: 'silver', expeditionTypeFilter: 'biking', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-silver-paddling">
                                                            {renderCountBtn(row.counts.silver.paddling, { typeFilter: 'expedition', levelFilter: 'silver', expeditionTypeFilter: 'paddling', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>

                                                        <td className="ems-matrix-cell-number" data-testid="cell-gold-hillwalking">
                                                            {renderCountBtn(row.counts.gold.hillwalking, { typeFilter: 'expedition', levelFilter: 'gold', expeditionTypeFilter: 'hillwalking', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-gold-biking">
                                                            {renderCountBtn(row.counts.gold.biking, { typeFilter: 'expedition', levelFilter: 'gold', expeditionTypeFilter: 'biking', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-gold-paddling">
                                                            {renderCountBtn(row.counts.gold.paddling, { typeFilter: 'expedition', levelFilter: 'gold', expeditionTypeFilter: 'paddling', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>

                                                        <td className="ems-matrix-cell-number" data-testid="cell-total">
                                                            {renderCountBtn(row.expeditionTotal, { typeFilter: 'expedition', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' }, undefined, true)}
                                                        </td>
                                                    </>
                                                ) : matrixTypeFilter === 'participant' ? (
                                                    <>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-bronze">
                                                            {renderCountBtn(row.counts.bronze.participant, { typeFilter: 'participant', levelFilter: 'bronze', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-silver">
                                                            {renderCountBtn(row.counts.silver.participant, { typeFilter: 'participant', levelFilter: 'silver', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-gold">
                                                            {renderCountBtn(row.counts.gold.participant, { typeFilter: 'participant', levelFilter: 'gold', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-total">
                                                            {renderCountBtn(row.participantTotal, { typeFilter: 'participant', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' }, undefined, true)}
                                                        </td>
                                                    </>
                                                ) : (
                                                    <>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-bronze-participant">
                                                            {renderCountBtn(row.counts.bronze.participant, { typeFilter: 'participant', levelFilter: 'bronze', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-bronze-expedition">
                                                            {renderCountBtn(row.counts.bronze.expedition, { typeFilter: 'expedition', levelFilter: 'bronze', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-silver-participant">
                                                            {renderCountBtn(row.counts.silver.participant, { typeFilter: 'participant', levelFilter: 'silver', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-silver-expedition">
                                                            {renderCountBtn(row.counts.silver.expedition, { typeFilter: 'expedition', levelFilter: 'silver', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-gold-participant">
                                                            {renderCountBtn(row.counts.gold.participant, { typeFilter: 'participant', levelFilter: 'gold', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-gold-expedition">
                                                            {renderCountBtn(row.counts.gold.expedition, { typeFilter: 'expedition', levelFilter: 'gold', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' })}
                                                        </td>
                                                        <td className="ems-matrix-cell-number" data-testid="cell-total">
                                                            {renderCountBtn(row.total, { typeFilter: 'all', unitFilter: row.unit, districtFilter: group.district, anomalyFilter: 'all' }, undefined, true)}
                                                        </td>
                                                    </>
                                                )}
                                            </tr>
                                        ))}
                                    </React.Fragment>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="ems-matrix-row-grand-total" data-testid="matrix-grand-total">
                                    <td colSpan={2} className="ems-matrix-col-subtotal-label">
                                        <strong>Total</strong>
                                    </td>
                                    {matrixTypeFilter === 'expedition' ? (
                                        <>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-bronze-hillwalking">
                                                {renderCountBtn(matrixData.grandTotal.counts.bronze.hillwalking, { typeFilter: 'expedition', levelFilter: 'bronze', expeditionTypeFilter: 'hillwalking', anomalyFilter: 'all' })}
                                            </td>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-bronze-biking">
                                                {renderCountBtn(matrixData.grandTotal.counts.bronze.biking, { typeFilter: 'expedition', levelFilter: 'bronze', expeditionTypeFilter: 'biking', anomalyFilter: 'all' })}
                                            </td>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-bronze-paddling">
                                                {renderCountBtn(matrixData.grandTotal.counts.bronze.paddling, { typeFilter: 'expedition', levelFilter: 'bronze', expeditionTypeFilter: 'paddling', anomalyFilter: 'all' })}
                                            </td>

                                            <td className="ems-matrix-cell-number" data-testid="grand-total-silver-hillwalking">
                                                {renderCountBtn(matrixData.grandTotal.counts.silver.hillwalking, { typeFilter: 'expedition', levelFilter: 'silver', expeditionTypeFilter: 'hillwalking', anomalyFilter: 'all' })}
                                            </td>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-silver-biking">
                                                {renderCountBtn(matrixData.grandTotal.counts.silver.biking, { typeFilter: 'expedition', levelFilter: 'silver', expeditionTypeFilter: 'biking', anomalyFilter: 'all' })}
                                            </td>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-silver-paddling">
                                                {renderCountBtn(matrixData.grandTotal.counts.silver.paddling, { typeFilter: 'expedition', levelFilter: 'silver', expeditionTypeFilter: 'paddling', anomalyFilter: 'all' })}
                                            </td>

                                            <td className="ems-matrix-cell-number" data-testid="grand-total-gold-hillwalking">
                                                {renderCountBtn(matrixData.grandTotal.counts.gold.hillwalking, { typeFilter: 'expedition', levelFilter: 'gold', expeditionTypeFilter: 'hillwalking', anomalyFilter: 'all' })}
                                            </td>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-gold-biking">
                                                {renderCountBtn(matrixData.grandTotal.counts.gold.biking, { typeFilter: 'expedition', levelFilter: 'gold', expeditionTypeFilter: 'biking', anomalyFilter: 'all' })}
                                            </td>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-gold-paddling">
                                                {renderCountBtn(matrixData.grandTotal.counts.gold.paddling, { typeFilter: 'expedition', levelFilter: 'gold', expeditionTypeFilter: 'paddling', anomalyFilter: 'all' })}
                                            </td>

                                            <td className="ems-matrix-cell-number" data-testid="grand-total-total">
                                                {renderCountBtn(matrixData.grandTotal.expeditionTotal, { typeFilter: 'expedition', anomalyFilter: 'all' }, undefined, true)}
                                            </td>
                                        </>
                                    ) : matrixTypeFilter === 'participant' ? (
                                        <>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-bronze">
                                                {renderCountBtn(matrixData.grandTotal.counts.bronze.participant, { typeFilter: 'participant', levelFilter: 'bronze', anomalyFilter: 'all' })}
                                            </td>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-silver">
                                                {renderCountBtn(matrixData.grandTotal.counts.silver.participant, { typeFilter: 'participant', levelFilter: 'silver', anomalyFilter: 'all' })}
                                            </td>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-gold">
                                                {renderCountBtn(matrixData.grandTotal.counts.gold.participant, { typeFilter: 'participant', levelFilter: 'gold', anomalyFilter: 'all' })}
                                            </td>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-total">
                                                {renderCountBtn(matrixData.grandTotal.participantTotal, { typeFilter: 'participant', anomalyFilter: 'all' }, undefined, true)}
                                            </td>
                                        </>
                                    ) : (
                                        <>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-bronze-participant">
                                                {renderCountBtn(matrixData.grandTotal.counts.bronze.participant, { typeFilter: 'participant', levelFilter: 'bronze', anomalyFilter: 'all' })}
                                            </td>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-bronze-expedition">
                                                {renderCountBtn(matrixData.grandTotal.counts.bronze.expedition, { typeFilter: 'expedition', levelFilter: 'bronze', anomalyFilter: 'all' })}
                                            </td>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-silver-participant">
                                                {renderCountBtn(matrixData.grandTotal.counts.silver.participant, { typeFilter: 'participant', levelFilter: 'silver', anomalyFilter: 'all' })}
                                            </td>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-silver-expedition">
                                                {renderCountBtn(matrixData.grandTotal.counts.silver.expedition, { typeFilter: 'expedition', levelFilter: 'silver', anomalyFilter: 'all' })}
                                            </td>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-gold-participant">
                                                {renderCountBtn(matrixData.grandTotal.counts.gold.participant, { typeFilter: 'participant', levelFilter: 'gold', anomalyFilter: 'all' })}
                                            </td>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-gold-expedition">
                                                {renderCountBtn(matrixData.grandTotal.counts.gold.expedition, { typeFilter: 'expedition', levelFilter: 'gold', anomalyFilter: 'all' })}
                                            </td>
                                            <td className="ems-matrix-cell-number" data-testid="grand-total-total">
                                                {renderCountBtn(matrixData.grandTotal.total, { typeFilter: 'all', anomalyFilter: 'all' }, undefined, true)}
                                            </td>
                                        </>
                                    )}
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
};

export default SignupsDashboard;
