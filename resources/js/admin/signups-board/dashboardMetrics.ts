export interface LevelBreakdown {
    total: number;
    participant: number;
    expedition: number;
}

export interface UnpairedGroupMetric {
    totalExplorers: number;
    totalRows: number;
    byLevel: {
        bronze: number;
        silver: number;
        gold: number;
    };
    signupKeys: Set<string>;
}

export interface DashboardMetrics {
    byType: {
        total: number;
        participant: number;
        expedition: number;
        uniqueExplorers: number;
    };
    byLevel: {
        bronze: LevelBreakdown;
        silver: LevelBreakdown;
        gold: LevelBreakdown;
    };
    byExpeditionType: {
        hillwalking: number;
        biking: number;
        paddling: number;
        unspecified: number;
    };
    participantWithoutExpedition: UnpairedGroupMetric;
    expeditionWithoutParticipant: UnpairedGroupMetric;
    pairedExplorers: {
        totalExplorers: number;
        byLevel: {
            bronze: number;
            silver: number;
            gold: number;
        };
    };
    duplicates: {
        groupsCount: number;
        rowsCount: number;
        participantGroups: number;
        expeditionGroups: number;
        signupKeys: Set<string>;
    };
    unmatchedOsm: {
        rowsCount: number;
        explorersCount: number;
        signupKeys: Set<string>;
    };
}

export interface WeeklyBucket {
    weekNumber: number;
    label: string;
    rangeLabel: string;
    startDate: string;
    endDate: string;
    weeklyCount: number;
    cumulativeCount: number;
}

export interface CumulativeSeriesResult {
    schoolYearStart: number;
    schoolYearLabel: string;
    availableSchoolYears: number[];
    totalMatching: number;
    weeks: WeeklyBucket[];
}

const normalize = (str: string | null | undefined): string => {
    return (str || '').trim().toLowerCase();
};

export const getSignupRecordKey = (s: any): string => {
    return `${s.type || 'unknown'}_${s.id}`;
};

interface ExplorerBucket {
    scoutId: number;
    keys: Set<string>;
    items: any[];
}

export function groupSignupsByExplorerIdentity(signups: any[]): ExplorerBucket[] {
    const groups: ExplorerBucket[] = [];

    signups.forEach((s) => {
        const scoutId = Number(s.scout_id || 0);
        const email = s.explorer_email ? normalize(s.explorer_email) : '';
        const firstName = normalize(s.explorer_first_name);
        const lastName = normalize(s.explorer_last_name);
        const fullName = firstName && lastName ? `${firstName}_${lastName}` : '';
        const parent = s.parent_email ? normalize(s.parent_email) : '';

        let matchedGroup: ExplorerBucket | undefined;

        // 1. Exact scout_id match when linked
        if (scoutId !== 0) {
            matchedGroup = groups.find((g) => g.scoutId === scoutId);
        }

        // 2. Fallback identity match (email, parent+name, or exact full name)
        // Only merge if the group does not have a conflicting non-zero scout_id
        if (!matchedGroup) {
            matchedGroup = groups.find((g) => {
                if (scoutId !== 0 && g.scoutId !== 0 && g.scoutId !== scoutId) {
                    return false;
                }
                const hasEmailMatch = Boolean(email && g.keys.has(`email_${email}`));
                const hasParentNameMatch = Boolean(fullName && parent && g.keys.has(`name_${parent}_${fullName}`));
                const hasFullNameMatch = Boolean(fullName && g.keys.has(`fullname_${fullName}`));
                return hasEmailMatch || hasParentNameMatch || hasFullNameMatch;
            });
        }

        const itemKeys = new Set<string>();
        if (scoutId !== 0) {
            itemKeys.add(`scout_${scoutId}`);
        }
        if (email) {
            itemKeys.add(`email_${email}`);
        }
        if (fullName && parent) {
            itemKeys.add(`name_${parent}_${fullName}`);
        }
        if (fullName) {
            itemKeys.add(`fullname_${fullName}`);
        }
        if (itemKeys.size === 0) {
            itemKeys.add(`row_${getSignupRecordKey(s)}`);
        }

        if (matchedGroup) {
            if (matchedGroup.scoutId === 0 && scoutId !== 0) {
                matchedGroup.scoutId = scoutId;
            }
            itemKeys.forEach((k) => matchedGroup!.keys.add(k));
            matchedGroup.items.push(s);
        } else {
            groups.push({
                scoutId,
                keys: itemKeys,
                items: [s],
            });
        }
    });

    return groups;
}

export function computeSignupsDashboardMetrics(signups: any[]): DashboardMetrics {
    const metrics: DashboardMetrics = {
        byType: {
            total: signups.length,
            participant: 0,
            expedition: 0,
            uniqueExplorers: 0,
        },
        byLevel: {
            bronze: { total: 0, participant: 0, expedition: 0 },
            silver: { total: 0, participant: 0, expedition: 0 },
            gold: { total: 0, participant: 0, expedition: 0 },
        },
        byExpeditionType: {
            hillwalking: 0,
            biking: 0,
            paddling: 0,
            unspecified: 0,
        },
        participantWithoutExpedition: {
            totalExplorers: 0,
            totalRows: 0,
            byLevel: {
                bronze: 0,
                silver: 0,
                gold: 0,
            },
            signupKeys: new Set<string>(),
        },
        expeditionWithoutParticipant: {
            totalExplorers: 0,
            totalRows: 0,
            byLevel: {
                bronze: 0,
                silver: 0,
                gold: 0,
            },
            signupKeys: new Set<string>(),
        },
        pairedExplorers: {
            totalExplorers: 0,
            byLevel: {
                bronze: 0,
                silver: 0,
                gold: 0,
            },
        },
        duplicates: {
            groupsCount: 0,
            rowsCount: 0,
            participantGroups: 0,
            expeditionGroups: 0,
            signupKeys: new Set<string>(),
        },
        unmatchedOsm: {
            rowsCount: 0,
            explorersCount: 0,
            signupKeys: new Set<string>(),
        },
    };

    signups.forEach((s) => {
        const isParticipant = s.type === 'participant';
        const isExpedition = s.type === 'expedition';

        if (isParticipant) {
            metrics.byType.participant++;
        } else if (isExpedition) {
            metrics.byType.expedition++;
        }

        const level = normalize(s.dofe_level);
        if (level === 'bronze' || level === 'silver' || level === 'gold') {
            metrics.byLevel[level].total++;
            if (isParticipant) metrics.byLevel[level].participant++;
            if (isExpedition) metrics.byLevel[level].expedition++;
        }

        if (isExpedition) {
            const expedType = normalize(s.expedition_preferences?.exped_type);
            if (expedType === 'hillwalking') {
                metrics.byExpeditionType.hillwalking++;
            } else if (expedType === 'biking') {
                metrics.byExpeditionType.biking++;
            } else if (expedType === 'paddling') {
                metrics.byExpeditionType.paddling++;
            } else {
                metrics.byExpeditionType.unspecified++;
            }
        }

        const isUnmatched = Number(s.scout_id || 0) === 0 || s.is_synced_osm === false || s.is_synced_osm === 0;
        if (isUnmatched) {
            metrics.unmatchedOsm.rowsCount++;
            metrics.unmatchedOsm.signupKeys.add(getSignupRecordKey(s));
        }
    });

    const explorerGroups = groupSignupsByExplorerIdentity(signups);
    metrics.byType.uniqueExplorers = explorerGroups.length;

    const levels = ['bronze', 'silver', 'gold'] as const;

    explorerGroups.forEach((group) => {
        let hasAnyPlaceWithoutExped = false;
        let hasAnyExpedWithoutPlace = false;
        let hasAnyPaired = false;

        levels.forEach((lvl) => {
            const lvlParticipants = group.items.filter(
                (i) => i.type === 'participant' && normalize(i.dofe_level) === lvl
            );
            const lvlExpeditions = group.items.filter(
                (i) => i.type === 'expedition' && normalize(i.dofe_level) === lvl
            );

            if (lvlParticipants.length > 0 && lvlExpeditions.length === 0) {
                hasAnyPlaceWithoutExped = true;
                metrics.participantWithoutExpedition.byLevel[lvl]++;
                metrics.participantWithoutExpedition.totalRows += lvlParticipants.length;
                lvlParticipants.forEach((item) => {
                    metrics.participantWithoutExpedition.signupKeys.add(getSignupRecordKey(item));
                });
            } else if (lvlExpeditions.length > 0 && lvlParticipants.length === 0) {
                hasAnyExpedWithoutPlace = true;
                metrics.expeditionWithoutParticipant.byLevel[lvl]++;
                metrics.expeditionWithoutParticipant.totalRows += lvlExpeditions.length;
                lvlExpeditions.forEach((item) => {
                    metrics.expeditionWithoutParticipant.signupKeys.add(getSignupRecordKey(item));
                });
            } else if (lvlParticipants.length > 0 && lvlExpeditions.length > 0) {
                hasAnyPaired = true;
                metrics.pairedExplorers.byLevel[lvl]++;
            }
        });

        if (hasAnyPlaceWithoutExped) {
            metrics.participantWithoutExpedition.totalExplorers++;
        }
        if (hasAnyExpedWithoutPlace) {
            metrics.expeditionWithoutParticipant.totalExplorers++;
        }
        if (hasAnyPaired) {
            metrics.pairedExplorers.totalExplorers++;
        }

        // 2. Duplicate Signups (same explorer, same form type, same DofE level)
        const byTypeAndLevel: Record<string, any[]> = {};
        group.items.forEach((item) => {
            const key = `${item.type}_${normalize(item.dofe_level)}`;
            if (!byTypeAndLevel[key]) {
                byTypeAndLevel[key] = [];
            }
            byTypeAndLevel[key].push(item);
        });

        Object.values(byTypeAndLevel).forEach((bucket) => {
            if (bucket.length > 1) {
                metrics.duplicates.groupsCount++;
                metrics.duplicates.rowsCount += bucket.length;
                if (bucket[0].type === 'participant') {
                    metrics.duplicates.participantGroups++;
                } else if (bucket[0].type === 'expedition') {
                    metrics.duplicates.expeditionGroups++;
                }
                bucket.forEach((item) => {
                    metrics.duplicates.signupKeys.add(getSignupRecordKey(item));
                });
            }
        });

        // 3. Unmatched OSM unique explorer count
        const hasUnmatched = group.items.some(
            (s) => Number(s.scout_id || 0) === 0 || s.is_synced_osm === false || s.is_synced_osm === 0
        );
        if (hasUnmatched) {
            metrics.unmatchedOsm.explorersCount++;
        }
    });

    return metrics;
}

export function parseSignupDate(createdAt: string | null | undefined): Date | null {
    if (!createdAt) return null;
    const isoStr = createdAt.includes('T') ? createdAt : createdAt.replace(' ', 'T');
    const parsed = new Date(isoStr);
    if (isNaN(parsed.getTime())) return null;
    return parsed;
}

export function getSchoolYearStart(date: Date): number {
    // August is month index 7 (0-indexed)
    return date.getMonth() >= 7 ? date.getFullYear() : date.getFullYear() - 1;
}

export function formatShortDate(date: Date): string {
    return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

export function computeWeeklyCumulativeSeries(
    signups: any[],
    options: {
        typeFilter?: string;
        levelFilter?: string;
        schoolYearStart?: number;
    } = {}
): CumulativeSeriesResult {
    const typeFilter = options.typeFilter || 'all';
    const levelFilter = options.levelFilter || 'all';

    const yearsSet = new Set<number>();
    signups.forEach((s) => {
        const dt = parseSignupDate(s.created_at);
        if (dt) {
            yearsSet.add(getSchoolYearStart(dt));
        }
    });

    const availableSchoolYears = Array.from(yearsSet).sort((a, b) => b - a);
    const defaultYear =
        availableSchoolYears.length > 0
            ? availableSchoolYears[0]
            : getSchoolYearStart(new Date());

    const activeYear =
        options.schoolYearStart && yearsSet.has(options.schoolYearStart)
            ? options.schoolYearStart
            : options.schoolYearStart ?? defaultYear;

    if (!availableSchoolYears.includes(activeYear)) {
        availableSchoolYears.push(activeYear);
        availableSchoolYears.sort((a, b) => b - a);
    }

    const startOfYear = new Date(activeYear, 7, 1, 0, 0, 0, 0); // 1 Aug YYYY
    const endOfYear = new Date(activeYear + 1, 6, 31, 23, 59, 59, 999); // 31 Jul YYYY+1

    // Filter signups matching school year, signup type, and level
    const matchingDates: Date[] = [];
    signups.forEach((s) => {
        if (typeFilter !== 'all' && s.type !== typeFilter) return;
        if (levelFilter !== 'all' && normalize(s.dofe_level) !== normalize(levelFilter)) return;

        const dt = parseSignupDate(s.created_at);
        if (!dt) return;
        if (dt >= startOfYear && dt <= endOfYear) {
            matchingDates.push(dt);
        }
    });

    // Also determine the latest date across all signups in this school year so the x-axis stays stable when filtering
    let maxDateInYear = new Date(startOfYear.getTime() + 11 * 7 * 24 * 60 * 60 * 1000);
    signups.forEach((s) => {
        const dt = parseSignupDate(s.created_at);
        if (dt && dt >= startOfYear && dt <= endOfYear && dt > maxDateInYear) {
            maxDateInYear = dt;
        }
    });

    const msPerDay = 24 * 60 * 60 * 1000;
    const msPerWeek = 7 * msPerDay;
    const maxWeekIndex = Math.min(
        51,
        Math.max(11, Math.floor((maxDateInYear.getTime() - startOfYear.getTime()) / msPerWeek))
    );

    const weeklyCounts = new Array(maxWeekIndex + 1).fill(0);
    matchingDates.forEach((dt) => {
        const rawWeek = Math.floor((dt.getTime() - startOfYear.getTime()) / msPerWeek);
        const weekIdx = Math.min(maxWeekIndex, Math.max(0, rawWeek));
        weeklyCounts[weekIdx]++;
    });

    let cumulative = 0;
    const weeks: WeeklyBucket[] = weeklyCounts.map((count, idx) => {
        cumulative += count;
        const wStart = new Date(startOfYear.getTime() + idx * msPerWeek);
        const wEndRaw = new Date(startOfYear.getTime() + (idx + 1) * msPerWeek - 1);
        const wEnd = wEndRaw > endOfYear ? endOfYear : wEndRaw;

        const startLabel = formatShortDate(wStart);
        const endLabel = formatShortDate(wEnd);

        return {
            weekNumber: idx + 1,
            label: startLabel,
            rangeLabel: `${startLabel} – ${endLabel}`,
            startDate: wStart.toISOString().substring(0, 10),
            endDate: wEnd.toISOString().substring(0, 10),
            weeklyCount: count,
            cumulativeCount: cumulative,
        };
    });

    const nextYearShort = String(activeYear + 1).slice(-2);
    return {
        schoolYearStart: activeYear,
        schoolYearLabel: `${activeYear}/${nextYearShort} (from 1 Aug ${activeYear})`,
        availableSchoolYears,
        totalMatching: cumulative,
        weeks,
    };
}
