export const normalize = (str: string | null | undefined): string => {
    return (str || '').trim().toLowerCase();
};

export const getUnitDistrictMap = (units: any[] = []): Record<string, string> => {
    const map: Record<string, string> = {};
    units.forEach((u: any) => {
        const dist = (u?.district || '').trim();
        if (dist) {
            if (u.name) map[u.name] = dist;
            if (u.short_code) map[u.short_code] = dist;
            if (u.unit_id !== undefined && u.unit_id !== null) map[String(u.unit_id)] = dist;
        }
    });
    return map;
};

export const getUniqueUnits = (signups: any[] = []): string[] => {
    const set = new Set<string>();
    signups.forEach((s: any) => {
        const unit = (s?.unit_name || 'Unassigned').trim();
        if (unit) set.add(unit);
    });
    return Array.from(set).sort((a, b) => a.localeCompare(b));
};

export const getUniqueDistricts = (
    units: any[] = [],
    signups: any[] = [],
    unitDistrictMap: Record<string, string> = {}
): string[] => {
    const set = new Set<string>();
    units.forEach((u: any) => {
        const dist = (u?.district || '').trim();
        if (dist && dist.toLowerCase() !== 'unassigned') set.add(dist);
    });
    signups.forEach((s: any) => {
        const dist = (s?.district || unitDistrictMap[s?.unit_name] || unitDistrictMap[String(s?.unit_id)] || '').trim();
        if (dist && dist.toLowerCase() !== 'unassigned') set.add(dist);
    });
    return Array.from(set).sort((a, b) => a.localeCompare(b));
};

export const isSectionCompleted = (completions: any, section: string): boolean => {
    if (!completions) return false;
    if (Array.isArray(completions)) {
        return completions.some((s: string) => (s || '').toLowerCase() === section.toLowerCase());
    }
    if (typeof completions === 'object') {
        return completions[section] === 'completed';
    }
    return false;
};

export const getSignupsSortValue = (item: any, key: string): any => {
    if (!item) return '';

    if (key === 'osm_sync') {
        return item.scout_id && item.scout_id !== 0 ? 1 : 0;
    }
    if (key === 'explorer_first_name') {
        return `${item.explorer_first_name || ''} ${item.explorer_last_name || ''}`.trim().toLowerCase();
    }
    if (key === 'participant') {
        return item.participantEntries?.length || 0;
    }
    if (key === 'expedition_signup') {
        return item.expeditionEntries?.length || 0;
    }
    if (key === 'expedition') {
        return (item.expedition_preferences?.exped_type || '').toLowerCase();
    }
    if (key === 'asn_validated') {
        if (!item.additional_support_needs || item.additional_support_needs.trim() === '') return -1;
        if (item.asn_validated === 1) return 2;
        if (item.asn_validated === 0) return 1;
        return 0;
    }
    if (key === 'prior_completions') {
        if (item.dofe_level === 'bronze') return -1;
        const completions = item.dofe_level === 'silver' ? item.bronze_completion : item.silver_completion;
        if (!completions) return 0;
        let count = 0;
        if (isSectionCompleted(completions, 'volunteering')) count++;
        if (isSectionCompleted(completions, 'skills')) count++;
        if (isSectionCompleted(completions, 'physical')) count++;
        if (isSectionCompleted(completions, 'expedition')) count++;
        return count;
    }
    const val = item[key];
    if (typeof val === 'string') {
        return val.toLowerCase();
    }
    return val ?? '';
};

export interface GroupedRowBucket<T = any> {
    title: string | null;
    items: T[];
}

export const partitionGroupedRows = <T extends Record<string, any>>(
    sortedRows: T[],
    grouping: 'none' | 'level' | 'expedition' | 'unit' | 'esu' | 'explorer' | 'parent' | string
): GroupedRowBucket<T>[] => {
    if (grouping === 'none') {
        return [{ title: null, items: sortedRows }];
    }

    if (grouping === 'level') {
        const levels = ['bronze', 'silver', 'gold'];
        return levels
            .map((lvl) => ({
                title: `${lvl.charAt(0).toUpperCase() + lvl.slice(1)} Level`,
                items: sortedRows.filter((s) => normalize(s.dofe_level) === lvl),
            }))
            .filter((g) => g.items.length > 0);
    }

    if (grouping === 'expedition') {
        const types = ['Hillwalking', 'Biking', 'Paddling'];
        const groups: GroupedRowBucket<T>[] = types
            .map((t) => ({
                title: t,
                items: sortedRows.filter((s) => normalize(s.expedition_preferences?.exped_type) === normalize(t)),
            }))
            .filter((g) => g.items.length > 0);

        const unassigned = sortedRows.filter(
            (s) => !types.some((t) => normalize(s.expedition_preferences?.exped_type) === normalize(t))
        );
        if (unassigned.length > 0) {
            groups.push({ title: 'No Preference / Participant Place', items: unassigned });
        }
        return groups;
    }

    if (grouping === 'unit' || grouping === 'esu') {
        const units = Array.from(new Set(sortedRows.map((s) => (s.unit_name || 'Unassigned').trim()))).sort((a, b) => {
            if (a === 'Unassigned') return 1;
            if (b === 'Unassigned') return -1;
            return a.localeCompare(b);
        });
        return units
            .map((u) => ({
                title: u,
                items: sortedRows.filter((s) => (s.unit_name || 'Unassigned').trim() === u),
            }))
            .filter((g) => g.items.length > 0);
    }

    if (grouping === 'explorer') {
        const groupsMap: Record<string, { title: string; items: T[] }> = {};
        sortedRows.forEach((row) => {
            const key = row.scout_id && row.scout_id !== 0
                ? `scout_${row.scout_id}`
                : `guest_${normalize(row.explorer_first_name)}_${normalize(row.explorer_last_name)}_${normalize(row.parent_email)}`;
            const title = row.scout_id && row.scout_id !== 0
                ? `${row.explorer_first_name} ${row.explorer_last_name} (Scout ID: ${row.scout_id})`
                : `${row.explorer_first_name} ${row.explorer_last_name} (Guest)`;
            if (!groupsMap[key]) {
                groupsMap[key] = { title, items: [] };
            }
            groupsMap[key].items.push(row);
        });

        return Object.keys(groupsMap)
            .sort((a, b) => groupsMap[a].title.localeCompare(groupsMap[b].title))
            .map((key) => groupsMap[key]);
    }

    if (grouping === 'parent') {
        const groupsMap: Record<string, { title: string; items: T[] }> = {};
        sortedRows.forEach((s) => {
            const parentUserId = Number(s.parent_user_id || 0);
            let key = '';
            let title = '';
            if (parentUserId !== 0) {
                key = `user_${parentUserId}`;
                title = `Parent ID: ${parentUserId} (${s.parent_email || 'No email'})`;
            } else {
                const email = normalize(s.parent_email || 'unknown');
                key = `email_${email}`;
                title = `Parent: ${s.parent_email || 'No Email'}`;
            }

            if (!groupsMap[key]) {
                groupsMap[key] = { title, items: [] };
            }
            groupsMap[key].items.push(s);
        });

        return Object.keys(groupsMap)
            .sort((a, b) => groupsMap[a].title.localeCompare(groupsMap[b].title))
            .map((key) => groupsMap[key]);
    }

    return [{ title: null, items: sortedRows }];
};

export const paginateGroupedRows = <T = any>(
    groupedRows: GroupedRowBucket<T>[],
    currentPage: number,
    itemsPerPage: number
): GroupedRowBucket<T>[] => {
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = currentPage * itemsPerPage;

    if (groupedRows.length === 1 && groupedRows[0].title === null) {
        return [{ title: null, items: groupedRows[0].items.slice(startIndex, endIndex) }];
    }

    const result: GroupedRowBucket<T>[] = [];
    let offset = 0;

    for (const group of groupedRows) {
        const groupStart = offset;
        const groupEnd = offset + group.items.length;
        offset = groupEnd;

        if (groupEnd <= startIndex || groupStart >= endIndex) {
            continue;
        }

        const sliceStart = Math.max(0, startIndex - groupStart);
        const sliceEnd = Math.min(group.items.length, endIndex - groupStart);
        if (sliceEnd > sliceStart) {
            result.push({
                title: group.title,
                items: group.items.slice(sliceStart, sliceEnd),
            });
        }
    }

    return result;
};
