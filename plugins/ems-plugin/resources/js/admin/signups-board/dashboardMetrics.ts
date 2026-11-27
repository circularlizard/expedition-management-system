export interface LevelBreakdown {
    total: number;
    participant: number;
    expedition: number;
    uniqueExplorers: number;
    uniqueParticipant: number;
    uniqueExpedition: number;
    expeditionByType: {
        hillwalking: number;
        biking: number;
        paddling: number;
        unspecified: number;
    };
}

export type AnomalyKey =
    | 'place_without_expedition'
    | 'expedition_without_place'
    | 'level_mismatch'
    | 'duplicate_signup'
    | 'unmatched_osm'
    | 'pending_osm_sync'
    | 'missing_edofe'
    | 'unvalidated_asn';

export const ANOMALY_META: { key: AnomalyKey; label: string }[] = [
    { key: 'place_without_expedition', label: 'Place Without Expedition' },
    { key: 'expedition_without_place', label: 'Expedition Without Place' },
    { key: 'level_mismatch', label: 'Level Mismatch' },
    { key: 'duplicate_signup', label: 'Duplicate' },
    { key: 'unmatched_osm', label: 'Unmatched OSM' },
    { key: 'pending_osm_sync', label: 'Pending OSM Sync' },
    { key: 'missing_edofe', label: 'Missing eDofE' },
    { key: 'unvalidated_asn', label: 'Unvalidated ASN' },
];

const ANOMALY_KEY_SET = new Set<string>(ANOMALY_META.map((a) => a.key));

export interface AnomalyTypeMetric {
    explorersCount: number;
    rowsCount: number;
    byLevel: {
        bronze: number;
        silver: number;
        gold: number;
    };
    signupKeys: Set<string>;
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
        uniqueParticipant: number;
        uniqueExpedition: number;
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
    anomalies: {
        totalExplorers: number;
        byType: Record<AnomalyKey, AnomalyTypeMetric>;
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
        byLevel: {
            bronze: number;
            silver: number;
            gold: number;
        };
        signupKeys: Set<string>;
    };
    unmatchedOsm: {
        rowsCount: number;
        explorersCount: number;
        withCandidatesCount: number;
        noMatchCount: number;
        byLevel: {
            bronze: number;
            silver: number;
            gold: number;
        };
        withCandidatesByLevel: {
            bronze: number;
            silver: number;
            gold: number;
        };
        noMatchByLevel: {
            bronze: number;
            silver: number;
            gold: number;
        };
        signupKeys: Set<string>;
    };
}

export const firstNamesSimilar = (nameA: string, nameB: string): boolean => {
    const na = normalize(nameA);
    const nb = normalize(nameB);
    return na.length >= 3 && nb.length >= 3 && na.substring(0, 3) === nb.substring(0, 3);
};

export const getMatchSuggestions = (signup: any, explorers: any[] = []): any[] => {
    if (!signup || Number(signup.scout_id || 0) !== 0) return [];
    const sf = normalize(signup.explorer_first_name);
    const sl = normalize(signup.explorer_last_name);
    const se = normalize(signup.explorer_email);
    const sp = normalize(signup.parent_email);

    return (explorers || []).filter((e: any) => {
        const ef = normalize(e.first_name);
        const el = normalize(e.last_name);

        // 1. Exact first and last name match
        if (sf && sl && sf === ef && sl === el) return true;

        // 2. Exact explorer email match (if present) against any explorer email
        const explorerEmails = [e.email1, e.email2, e.email].map(normalize).filter(Boolean);
        if (se && explorerEmails.includes(se)) return true;

        // 3. Parent email match (against any parent email) AND first name similarity (sibling guard)
        const parentEmails = [e.p1_email1, e.p1_email2, e.p2_email1, e.p2_email2, e.parent_email].map(normalize).filter(Boolean);
        if (sp && parentEmails.includes(sp) && firstNamesSimilar(signup.explorer_first_name, e.first_name)) {
            return true;
        }

        return false;
    });
};

export const isSignupSyncedWithOsm = (s: any): boolean => {
    if (!s) return false;
    return Number(s.scout_id || 0) !== 0 && s.is_synced_osm !== false && s.is_synced_osm !== 0;
};

export interface WeeklyBucket {
    weekNumber: number;
    label: string;
    rangeLabel: string;
    startDate: string;
    endDate: string;
    weeklyCount: number;
    cumulativeCount: number;
    participantWeeklyCount: number;
    participantCumulativeCount: number;
    expeditionWeeklyCount: number;
    expeditionCumulativeCount: number;
}

export interface CumulativeSeriesResult {
    schoolYearStart: number;
    schoolYearLabel: string;
    availableSchoolYears: number[];
    totalMatching: number;
    totalParticipant: number;
    totalExpedition: number;
    weeks: WeeklyBucket[];
}

export type MatrixLevel = 'bronze' | 'silver' | 'gold';
export type MatrixExpedType = 'hillwalking' | 'biking' | 'paddling';

export interface ExpedTypeCounts {
    hillwalking: number;
    biking: number;
    paddling: number;
    other: number;
    participant: number;
    expedition: number;
    total: number;
}

export interface MatrixRowData {
    unit: string;
    district: string;
    counts: Record<MatrixLevel, ExpedTypeCounts>;
    participantTotal: number;
    expeditionTotal: number;
    total: number;
}

export interface DistrictMatrixGroup {
    district: string;
    units: MatrixRowData[];
    subtotal: {
        counts: Record<MatrixLevel, ExpedTypeCounts>;
        participantTotal: number;
        expeditionTotal: number;
        total: number;
    };
}

export interface ExpeditionMatrixResult {
    groups: DistrictMatrixGroup[];
    grandTotal: {
        counts: Record<MatrixLevel, ExpedTypeCounts>;
        participantTotal: number;
        expeditionTotal: number;
        total: number;
    };
    totalParticipant: number;
    totalExpeditions: number;
    totalSignups: number;
    hasOtherExpedType: boolean;
}

export const createEmptyExpedTypeCounts = (): ExpedTypeCounts => ({
    hillwalking: 0,
    biking: 0,
    paddling: 0,
    other: 0,
    participant: 0,
    expedition: 0,
    total: 0,
});

export const createEmptyLevelMap = (): Record<MatrixLevel, ExpedTypeCounts> => ({
    bronze: createEmptyExpedTypeCounts(),
    silver: createEmptyExpedTypeCounts(),
    gold: createEmptyExpedTypeCounts(),
});

export function computeExpeditionDistrictUnitMatrix(
    signups: any[],
    unitDistrictMap: Record<string, string> = {}
): ExpeditionMatrixResult {
    const unitMap: Record<string, { unit: string; district: string; counts: Record<MatrixLevel, ExpedTypeCounts>; participantTotal: number; expeditionTotal: number; total: number }> = {};
    let hasOtherExpedType = false;

    signups.forEach((s) => {
        if (s.type !== 'expedition' && s.type !== 'participant') return;

        const rawUnit = (s.unit_name || '').trim();
        const unit = rawUnit || 'Unassigned';
        const rawDistrict = (s.district || unitDistrictMap[unit] || unitDistrictMap[String(s.unit_id)] || '').trim();
        const district = rawDistrict || 'Unassigned';

        const key = `${district}__${unit}`;
        if (!unitMap[key]) {
            unitMap[key] = {
                unit,
                district,
                counts: createEmptyLevelMap(),
                participantTotal: 0,
                expeditionTotal: 0,
                total: 0,
            };
        }

        const rawLevel = normalize(s.dofe_level);
        const level: MatrixLevel | null =
            rawLevel === 'bronze' || rawLevel === 'silver' || rawLevel === 'gold' ? rawLevel : null;

        unitMap[key].total++;

        if (s.type === 'participant') {
            unitMap[key].participantTotal++;
            if (level) {
                unitMap[key].counts[level].participant++;
                unitMap[key].counts[level].total++;
            }
        } else if (s.type === 'expedition') {
            unitMap[key].expeditionTotal++;
            const rawExpedType = normalizeExpeditionType(s.expedition_preferences?.exped_type);
            const expedType: MatrixExpedType | 'other' = rawExpedType || 'other';

            if (expedType === 'other') {
                hasOtherExpedType = true;
            }

            if (level) {
                unitMap[key].counts[level].expedition++;
                unitMap[key].counts[level].total++;
                if (expedType === 'other') {
                    unitMap[key].counts[level].other++;
                } else {
                    unitMap[key].counts[level][expedType]++;
                }
            }
        }
    });

    // Group rows by district
    const districtGroupsMap: Record<string, MatrixRowData[]> = {};
    Object.values(unitMap).forEach((row) => {
        if (!districtGroupsMap[row.district]) {
            districtGroupsMap[row.district] = [];
        }
        districtGroupsMap[row.district].push(row);
    });

    // Sort districts: alphabetical, with Unassigned last
    const sortedDistricts = Object.keys(districtGroupsMap).sort((a, b) => {
        if (a === 'Unassigned') return 1;
        if (b === 'Unassigned') return -1;
        return a.localeCompare(b);
    });

    const grandTotal = {
        counts: createEmptyLevelMap(),
        participantTotal: 0,
        expeditionTotal: 0,
        total: 0,
    };

    const levels: MatrixLevel[] = ['bronze', 'silver', 'gold'];
    const expedTypes: MatrixExpedType[] = ['hillwalking', 'biking', 'paddling'];

    const groups: DistrictMatrixGroup[] = sortedDistricts.map((district) => {
        const units = districtGroupsMap[district].sort((a, b) => {
            if (a.unit === 'Unassigned') return 1;
            if (b.unit === 'Unassigned') return -1;
            return a.unit.localeCompare(b.unit);
        });

        const subtotal = {
            counts: createEmptyLevelMap(),
            participantTotal: 0,
            expeditionTotal: 0,
            total: 0,
        };

        units.forEach((u) => {
            subtotal.total += u.total;
            subtotal.participantTotal += u.participantTotal;
            subtotal.expeditionTotal += u.expeditionTotal;
            levels.forEach((lvl) => {
                subtotal.counts[lvl].total += u.counts[lvl].total;
                subtotal.counts[lvl].participant += u.counts[lvl].participant;
                subtotal.counts[lvl].expedition += u.counts[lvl].expedition;
                subtotal.counts[lvl].other += u.counts[lvl].other;
                expedTypes.forEach((et) => {
                    subtotal.counts[lvl][et] += u.counts[lvl][et];
                });
            });
        });

        // Accumulate grand total
        grandTotal.total += subtotal.total;
        grandTotal.participantTotal += subtotal.participantTotal;
        grandTotal.expeditionTotal += subtotal.expeditionTotal;
        levels.forEach((lvl) => {
            grandTotal.counts[lvl].total += subtotal.counts[lvl].total;
            grandTotal.counts[lvl].participant += subtotal.counts[lvl].participant;
            grandTotal.counts[lvl].expedition += subtotal.counts[lvl].expedition;
            grandTotal.counts[lvl].other += subtotal.counts[lvl].other;
            expedTypes.forEach((et) => {
                grandTotal.counts[lvl][et] += subtotal.counts[lvl][et];
            });
        });

        return {
            district,
            units,
            subtotal,
        };
    });

    return {
        groups,
        grandTotal,
        totalParticipant: grandTotal.participantTotal,
        totalExpeditions: grandTotal.expeditionTotal,
        totalSignups: grandTotal.total,
        hasOtherExpedType,
    };
}

const normalize = (str: string | null | undefined): string => {
    return (str || '').trim().toLowerCase();
};

export const normalizeExpeditionType = (
    raw: string | null | undefined
): 'hillwalking' | 'biking' | 'paddling' | '' => {
    const val = normalize(raw);
    if (!val) return '';
    if (val === 'hillwalking' || val.includes('hillwalk')) return 'hillwalking';
    if (val === 'biking' || val.includes('bik')) return 'biking';
    if (
        val === 'paddling' ||
        val.includes('paddl') ||
        val.includes('register interest') ||
        val.includes('register_interest')
    ) {
        return 'paddling';
    }
    return '';
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

const buildAnomalyIdentityKey = (item: any): string => {
    const scoutId = Number(item?.scout_id || 0);
    if (scoutId > 0) {
        return `scout:${scoutId}`;
    }
    const first = normalize(item?.explorer_first_name);
    const last = normalize(item?.explorer_last_name);
    const parent = normalize(item?.parent_email);
    return `name:${first}|${last}|${parent}`;
};

/**
 * Mirrors server-side SignupAnomalyService::computeAnomalies() as a fallback
 * when signup rows do not carry precomputed `anomalies` from the REST API.
 */
export function computeSignupAnomaliesByKey(signups: any[]): Map<string, AnomalyKey[]> {
    const byKey = new Map<string, AnomalyKey[]>();
    const all: any[] = [];

    (signups || []).forEach((row) => {
        if (!row) return;
        const stage = String(row.kanban_stage || '');
        if (row.type === 'participant') {
            if (stage === 'cancelled') return;
            all.push(row);
        } else if (row.type === 'expedition') {
            if ((row.signup_status || 'submitted') === 'archived') return;
            if (stage === 'cancelled' || stage === 'withdrawn') return;
            all.push(row);
        }
    });

    const buckets = new Map<string, any[]>();
    const dupMap = new Map<string, string[]>();

    all.forEach((item) => {
        const identity = buildAnomalyIdentityKey(item);
        const list = buckets.get(identity) || [];
        list.push(item);
        buckets.set(identity, list);

        if ((item.signup_status || 'submitted') !== 'archived') {
            const type = String(item.type || '');
            const level = normalize(item.dofe_level);
            const dupKey = `${identity}|${type}|${level}`;
            const dupList = dupMap.get(dupKey) || [];
            dupList.push(`${type}_${Number(item.id || 0)}`);
            dupMap.set(dupKey, dupList);
        }
    });

    buckets.forEach((items) => {
        const pLevels = new Set<string>();
        const eLevels = new Set<string>();

        items.forEach((item) => {
            const lvl = normalize(item.dofe_level);
            if (item.type === 'participant') {
                pLevels.add(lvl);
            } else if (item.type === 'expedition') {
                eLevels.add(lvl);
            }
        });

        const hasP = pLevels.size > 0;
        const hasE = eLevels.size > 0;
        const levelMismatch =
            hasP &&
            hasE &&
            (pLevels.size !== eLevels.size || Array.from(pLevels).some((l) => !eLevels.has(l)));

        items.forEach((item) => {
            if ((item.signup_status || 'submitted') === 'archived') return;

            const type = String(item.type || '');
            const lvl = normalize(item.dofe_level);
            const flags: AnomalyKey[] = [];

            if (type === 'participant' && !eLevels.has(lvl)) {
                flags.push('place_without_expedition');
            }
            if (type === 'expedition' && !pLevels.has(lvl) && !item.participant_place_allocated) {
                flags.push('expedition_without_place');
            }
            if (levelMismatch) {
                flags.push('level_mismatch');
            }

            const dupKey = `${buildAnomalyIdentityKey(item)}|${type}|${lvl}`;
            if ((dupMap.get(dupKey)?.length || 0) > 1) {
                flags.push('duplicate_signup');
            }

            if (type === 'expedition') {
                const scoutId = Number(item.scout_id || 0);
                if (scoutId === 0) {
                    flags.push('unmatched_osm');
                } else if (!item.is_synced_osm || item.is_synced_osm === 0) {
                    flags.push('pending_osm_sync');
                }

                const asn = String(item.additional_support_needs || '').trim();
                const asnValidated = item.asn_validated;
                if (asn !== '' && (asnValidated === null || asnValidated === undefined || Number(asnValidated) === 0)) {
                    flags.push('unvalidated_asn');
                }
            }

            if (String(item.dofe_number || '').trim() === '') {
                flags.push('missing_edofe');
            }

            byKey.set(getSignupRecordKey(item), flags);
        });
    });

    return byKey;
}

export function getEffectiveSignupAnomalies(
    item: any,
    fallbackByKey: Map<string, AnomalyKey[]>
): AnomalyKey[] {
    if (Array.isArray(item?.anomalies)) {
        return item.anomalies.filter((a: any): a is AnomalyKey => ANOMALY_KEY_SET.has(String(a)));
    }
    return fallbackByKey.get(getSignupRecordKey(item)) || [];
}

const createEmptyAnomalyTypeMetric = (): AnomalyTypeMetric => ({
    explorersCount: 0,
    rowsCount: 0,
    byLevel: {
        bronze: 0,
        silver: 0,
        gold: 0,
    },
    signupKeys: new Set<string>(),
});

export function computeSignupsDashboardMetrics(signups: any[], explorers: any[] = []): DashboardMetrics {
    const fallbackAnomalies = computeSignupAnomaliesByKey(signups);
    const metrics: DashboardMetrics = {
        byType: {
            total: signups.length,
            participant: 0,
            expedition: 0,
            uniqueExplorers: 0,
            uniqueParticipant: 0,
            uniqueExpedition: 0,
        },
        byLevel: {
            bronze: { total: 0, participant: 0, expedition: 0, uniqueExplorers: 0, uniqueParticipant: 0, uniqueExpedition: 0, expeditionByType: { hillwalking: 0, biking: 0, paddling: 0, unspecified: 0 } },
            silver: { total: 0, participant: 0, expedition: 0, uniqueExplorers: 0, uniqueParticipant: 0, uniqueExpedition: 0, expeditionByType: { hillwalking: 0, biking: 0, paddling: 0, unspecified: 0 } },
            gold: { total: 0, participant: 0, expedition: 0, uniqueExplorers: 0, uniqueParticipant: 0, uniqueExpedition: 0, expeditionByType: { hillwalking: 0, biking: 0, paddling: 0, unspecified: 0 } },
        },
        byExpeditionType: {
            hillwalking: 0,
            biking: 0,
            paddling: 0,
            unspecified: 0,
        },
        anomalies: {
            totalExplorers: 0,
            byType: {
                place_without_expedition: createEmptyAnomalyTypeMetric(),
                expedition_without_place: createEmptyAnomalyTypeMetric(),
                level_mismatch: createEmptyAnomalyTypeMetric(),
                duplicate_signup: createEmptyAnomalyTypeMetric(),
                unmatched_osm: createEmptyAnomalyTypeMetric(),
                pending_osm_sync: createEmptyAnomalyTypeMetric(),
                missing_edofe: createEmptyAnomalyTypeMetric(),
                unvalidated_asn: createEmptyAnomalyTypeMetric(),
            },
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
            byLevel: {
                bronze: 0,
                silver: 0,
                gold: 0,
            },
            signupKeys: new Set<string>(),
        },
        unmatchedOsm: {
            rowsCount: 0,
            explorersCount: 0,
            withCandidatesCount: 0,
            noMatchCount: 0,
            byLevel: {
                bronze: 0,
                silver: 0,
                gold: 0,
            },
            withCandidatesByLevel: {
                bronze: 0,
                silver: 0,
                gold: 0,
            },
            noMatchByLevel: {
                bronze: 0,
                silver: 0,
                gold: 0,
            },
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
    });

    const explorerGroups = groupSignupsByExplorerIdentity(signups);
    metrics.byType.uniqueExplorers = explorerGroups.length;

    const levels = ['bronze', 'silver', 'gold'] as const;

    explorerGroups.forEach((group) => {
        const hasAnyParticipant = group.items.some((i) => i.type === 'participant');
        const hasAnyExpedition = group.items.some((i) => i.type === 'expedition');
        if (hasAnyParticipant) metrics.byType.uniqueParticipant++;
        if (hasAnyExpedition) metrics.byType.uniqueExpedition++;

        const expedTypesForExplorer = new Set<'hillwalking' | 'biking' | 'paddling' | 'unspecified'>();
        group.items.forEach((i) => {
            if (i.type !== 'expedition') return;
            const expedType = normalizeExpeditionType(i.expedition_preferences?.exped_type);
            expedTypesForExplorer.add(expedType || 'unspecified');
        });
        expedTypesForExplorer.forEach((et) => {
            metrics.byExpeditionType[et]++;
        });

        // Unified anomaly aggregation matching SignupAnomalyService & Anomalies & Chasers tab
        const activeItems = group.items.filter((i) => {
            if ((i.signup_status || 'submitted') === 'archived') return false;
            const stage = String(i.kanban_stage || '');
            if (stage === 'cancelled') return false;
            if (i.type === 'expedition' && stage === 'withdrawn') return false;
            return true;
        });

        const explorerAnomalies = new Set<AnomalyKey>();
        const levelAnomalies: Record<'bronze' | 'silver' | 'gold', Set<AnomalyKey>> = {
            bronze: new Set<AnomalyKey>(),
            silver: new Set<AnomalyKey>(),
            gold: new Set<AnomalyKey>(),
        };

        activeItems.forEach((item) => {
            const flags = getEffectiveSignupAnomalies(item, fallbackAnomalies);
            const itemKey = getSignupRecordKey(item);
            const itemLvl = normalize(item.dofe_level);

            flags.forEach((flag) => {
                explorerAnomalies.add(flag);
                metrics.anomalies.byType[flag].rowsCount++;
                metrics.anomalies.byType[flag].signupKeys.add(itemKey);
                if (itemLvl === 'bronze' || itemLvl === 'silver' || itemLvl === 'gold') {
                    levelAnomalies[itemLvl].add(flag);
                }
            });
        });

        if (explorerAnomalies.size > 0) {
            metrics.anomalies.totalExplorers++;
            explorerAnomalies.forEach((flag) => {
                metrics.anomalies.byType[flag].explorersCount++;
            });
        }

        levels.forEach((lvl) => {
            levelAnomalies[lvl].forEach((flag) => {
                metrics.anomalies.byType[flag].byLevel[lvl]++;
            });
        });

        let hasAnyPaired = false;

        levels.forEach((lvl) => {
            const lvlParticipants = group.items.filter(
                (i) => i.type === 'participant' && normalize(i.dofe_level) === lvl
            );
            const lvlExpeditions = group.items.filter(
                (i) => i.type === 'expedition' && normalize(i.dofe_level) === lvl
            );

            if (lvlParticipants.length > 0 || lvlExpeditions.length > 0) {
                metrics.byLevel[lvl].uniqueExplorers++;
            }
            if (lvlParticipants.length > 0) {
                metrics.byLevel[lvl].uniqueParticipant++;
            }
            if (lvlExpeditions.length > 0) {
                metrics.byLevel[lvl].uniqueExpedition++;
                const lvlExpedTypes = new Set<'hillwalking' | 'biking' | 'paddling' | 'unspecified'>();
                lvlExpeditions.forEach((exp) => {
                    const et = normalizeExpeditionType(exp.expedition_preferences?.exped_type);
                    lvlExpedTypes.add(et || 'unspecified');
                });
                lvlExpedTypes.forEach((et) => {
                    metrics.byLevel[lvl].expeditionByType[et]++;
                });
            }

            if (lvlParticipants.length > 0 && lvlExpeditions.length > 0) {
                hasAnyPaired = true;
                metrics.pairedExplorers.byLevel[lvl]++;
            }
        });

        if (hasAnyPaired) {
            metrics.pairedExplorers.totalExplorers++;
        }

        // Duplicate Signups (legacy breakdown kept in sync with duplicate_signup)
        const byTypeAndLevel: Record<string, any[]> = {};
        activeItems.forEach((item) => {
            const key = `${item.type}_${normalize(item.dofe_level)}`;
            if (!byTypeAndLevel[key]) {
                byTypeAndLevel[key] = [];
            }
            byTypeAndLevel[key].push(item);
        });

        Object.values(byTypeAndLevel).forEach((bucket) => {
            if (bucket.length > 1) {
                metrics.duplicates.groupsCount++;
                if (bucket[0].type === 'participant') {
                    metrics.duplicates.participantGroups++;
                } else if (bucket[0].type === 'expedition') {
                    metrics.duplicates.expeditionGroups++;
                }
            }
        });

        // Unmatched OSM candidate vs no-match breakdown (expedition only, matching SignupAnomalyService)
        const unmatchedItems = activeItems.filter((item) =>
            getEffectiveSignupAnomalies(item, fallbackAnomalies).includes('unmatched_osm')
        );
        if (unmatchedItems.length > 0) {
            const hasCandidate = unmatchedItems.some(
                (s) => getMatchSuggestions(s, explorers).length > 0
            );
            if (hasCandidate) {
                metrics.unmatchedOsm.withCandidatesCount++;
            } else {
                metrics.unmatchedOsm.noMatchCount++;
            }
        }
        levels.forEach((lvl) => {
            const lvlUnmatchedItems = unmatchedItems.filter(
                (s) => normalize(s.dofe_level) === lvl
            );
            if (lvlUnmatchedItems.length > 0) {
                const hasLvlCandidate = lvlUnmatchedItems.some(
                    (s) => getMatchSuggestions(s, explorers).length > 0
                );
                if (hasLvlCandidate) {
                    metrics.unmatchedOsm.withCandidatesByLevel[lvl]++;
                } else {
                    metrics.unmatchedOsm.noMatchByLevel[lvl]++;
                }
            }
        });
    });

    // Keep legacy top-level fields synced with unified SignupAnomalyService counts
    const pWithoutE = metrics.anomalies.byType.place_without_expedition;
    metrics.participantWithoutExpedition.totalExplorers = pWithoutE.explorersCount;
    metrics.participantWithoutExpedition.totalRows = pWithoutE.rowsCount;
    metrics.participantWithoutExpedition.byLevel = { ...pWithoutE.byLevel };
    metrics.participantWithoutExpedition.signupKeys = pWithoutE.signupKeys;

    const eWithoutP = metrics.anomalies.byType.expedition_without_place;
    metrics.expeditionWithoutParticipant.totalExplorers = eWithoutP.explorersCount;
    metrics.expeditionWithoutParticipant.totalRows = eWithoutP.rowsCount;
    metrics.expeditionWithoutParticipant.byLevel = { ...eWithoutP.byLevel };
    metrics.expeditionWithoutParticipant.signupKeys = eWithoutP.signupKeys;

    const dup = metrics.anomalies.byType.duplicate_signup;
    metrics.duplicates.rowsCount = dup.rowsCount;
    metrics.duplicates.byLevel = { ...dup.byLevel };
    metrics.duplicates.signupKeys = dup.signupKeys;

    const osm = metrics.anomalies.byType.unmatched_osm;
    metrics.unmatchedOsm.rowsCount = osm.rowsCount;
    metrics.unmatchedOsm.explorersCount = osm.explorersCount;
    metrics.unmatchedOsm.byLevel = { ...osm.byLevel };
    metrics.unmatchedOsm.signupKeys = osm.signupKeys;

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
        levelFilter?: string;
        schoolYearStart?: number;
    } = {}
): CumulativeSeriesResult {
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

    // Filter signups matching school year and level
    const matchingItems: { dt: Date; type: string }[] = [];
    signups.forEach((s) => {
        if (levelFilter !== 'all' && normalize(s.dofe_level) !== normalize(levelFilter)) return;

        const dt = parseSignupDate(s.created_at);
        if (!dt) return;
        if (dt >= startOfYear && dt <= endOfYear) {
            matchingItems.push({ dt, type: s.type });
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

    const participantWeeklyCounts = new Array(maxWeekIndex + 1).fill(0);
    const expeditionWeeklyCounts = new Array(maxWeekIndex + 1).fill(0);

    matchingItems.forEach(({ dt, type }) => {
        const rawWeek = Math.floor((dt.getTime() - startOfYear.getTime()) / msPerWeek);
        const weekIdx = Math.min(maxWeekIndex, Math.max(0, rawWeek));
        if (type === 'participant') {
            participantWeeklyCounts[weekIdx]++;
        } else if (type === 'expedition') {
            expeditionWeeklyCounts[weekIdx]++;
        }
    });

    let participantCumulative = 0;
    let expeditionCumulative = 0;

    const weeks: WeeklyBucket[] = participantWeeklyCounts.map((pCount, idx) => {
        const eCount = expeditionWeeklyCounts[idx];
        participantCumulative += pCount;
        expeditionCumulative += eCount;

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
            weeklyCount: pCount + eCount,
            cumulativeCount: participantCumulative + expeditionCumulative,
            participantWeeklyCount: pCount,
            participantCumulativeCount: participantCumulative,
            expeditionWeeklyCount: eCount,
            expeditionCumulativeCount: expeditionCumulative,
        };
    });

    const nextYearShort = String(activeYear + 1).slice(-2);
    return {
        schoolYearStart: activeYear,
        schoolYearLabel: `${activeYear}/${nextYearShort} (from 1 Aug ${activeYear})`,
        availableSchoolYears,
        totalMatching: participantCumulative + expeditionCumulative,
        totalParticipant: participantCumulative,
        totalExpedition: expeditionCumulative,
        weeks,
    };
}
