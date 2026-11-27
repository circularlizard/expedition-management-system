import React, { useState, useEffect } from 'react';

export interface PreviewTarget {
    type: 'user' | 'explorer';
    id?: number;
    scout_id?: number;
    name?: string;
    accessType?: string;
}

interface ParentTarget {
    id: number;
    name: string;
    email: string;
    description: string;
    scout_ids: number[];
}

interface MemberTarget {
    id: number;
    name: string;
    email: string;
    scout_ids: number[];
}

interface ExplorerTarget {
    scout_id: number;
    first_name: string;
    last_name: string;
    patrol?: string;
    unit_name?: string;
}

interface PreviewTargetsData {
    parents: ParentTarget[];
    members: MemberTarget[];
    explorers: ExplorerTarget[];
}

interface AdminPreviewBarProps {
    isAdmin: boolean;
    activeTarget: PreviewTarget | null;
    onSelectTarget: (target: PreviewTarget | null) => void;
    rootUrl: string;
    nonce: string;
}

export const AdminPreviewBar: React.FC<AdminPreviewBarProps> = ({
    isAdmin,
    activeTarget,
    onSelectTarget,
    rootUrl,
    nonce,
}) => {
    // Strictly isolate non-admin roles: return null immediately if not an admin.
    if (!isAdmin) {
        return null;
    }

    const [targets, setTargets] = useState<PreviewTargetsData | null>(null);
    const [loading, setLoading] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');

    useEffect(() => {
        let isMounted = true;
        setLoading(true);

        fetch(`${rootUrl}/portal/admin/preview-targets`, {
            headers: { 'X-WP-Nonce': nonce },
        })
            .then((res) => (res.ok ? res.json() : Promise.reject()))
            .then((data: PreviewTargetsData) => {
                if (isMounted) {
                    setTargets(data);
                    setLoading(false);
                }
            })
            .catch(() => {
                if (isMounted) {
                    setLoading(false);
                }
            });

        return () => {
            isMounted = false;
        };
    }, [rootUrl, nonce]);

    const handleSelectChange = (value: string) => {
        if (!value) {
            onSelectTarget(null);
            return;
        }

        const parts = value.split(':');
        const type = parts[0] as 'user' | 'explorer';
        const idOrScout = parseInt(parts[1], 10);
        const accessType = parts[2] || (type === 'user' ? 'parent' : 'member');

        if (type === 'user') {
            const foundParent = targets?.parents.find((p) => p.id === idOrScout);
            const foundMember = targets?.members.find((m) => m.id === idOrScout);
            const name = foundParent?.name || foundMember?.name || `User #${idOrScout}`;

            onSelectTarget({
                type: 'user',
                id: idOrScout,
                name,
                accessType: foundParent ? 'parent' : 'member',
            });
        } else {
            const foundExplorer = targets?.explorers.find((e) => e.scout_id === idOrScout);
            const name = foundExplorer
                ? `${foundExplorer.first_name} ${foundExplorer.last_name}`
                : `Explorer #${idOrScout}`;

            onSelectTarget({
                type: 'explorer',
                scout_id: idOrScout,
                name,
                accessType: 'member',
            });
        }
    };

    const currentValue = activeTarget
        ? activeTarget.type === 'user'
            ? `user:${activeTarget.id}:${activeTarget.accessType || 'parent'}`
            : `explorer:${activeTarget.scout_id}:member`
        : '';

    const term = searchTerm.toLowerCase().trim();

    const filteredParents = (targets?.parents || []).filter(
        (p) => !term || p.name.toLowerCase().includes(term) || p.description.toLowerCase().includes(term)
    );

    const filteredMembers = (targets?.members || []).filter(
        (m) => !term || m.name.toLowerCase().includes(term) || m.email.toLowerCase().includes(term)
    );

    const filteredExplorers = (targets?.explorers || []).filter(
        (e) =>
            !term ||
            e.first_name.toLowerCase().includes(term) ||
            e.last_name.toLowerCase().includes(term) ||
            (e.unit_name && e.unit_name.toLowerCase().includes(term)) ||
            (e.patrol && e.patrol.toLowerCase().includes(term)) ||
            String(e.scout_id).includes(term)
    );

    return (
        <div className="ems-portal-admin-bar" role="region" aria-label="Admin Portal Preview">
            <div className="ems-portal-admin-bar__title">
                <span className="ems-portal-admin-bar__badge">ADMIN PREVIEW</span>
                <span>Testing Portal Persona</span>
            </div>

            <div className="ems-portal-admin-bar__controls">
                <input
                    type="text"
                    aria-label="Filter preview targets"
                    placeholder="Search name, scout ID, unit..."
                    value={searchTerm}
                    onChange={(e) => setSearchTerm(e.target.value)}
                    className="ems-portal-admin-bar__search"
                />

                <select
                    aria-label="Select preview persona"
                    className="ems-portal-admin-bar__select"
                    value={currentValue}
                    onChange={(e) => handleSelectChange(e.target.value)}
                    disabled={loading}
                >
                    <option value="">
                        {loading ? 'Loading preview users...' : '-- Choose User / Explorer to Preview --'}
                    </option>

                    {filteredParents.length > 0 && (
                        <optgroup label="Parents">
                            {filteredParents.map((p) => (
                                <option key={`p-${p.id}`} value={`user:${p.id}:parent`}>
                                    Parent: {p.name} ({p.description})
                                </option>
                            ))}
                        </optgroup>
                    )}

                    {filteredMembers.length > 0 && (
                        <optgroup label="Registered Members">
                            {filteredMembers.map((m) => (
                                <option key={`m-${m.id}`} value={`user:${m.id}:member`}>
                                    Member: {m.name}
                                </option>
                            ))}
                        </optgroup>
                    )}

                    {filteredExplorers.length > 0 && (
                        <optgroup label="All Synced Explorers">
                            {filteredExplorers.map((e) => {
                                const unitLabel = e.unit_name || e.patrol;
                                return (
                                    <option key={`e-${e.scout_id}`} value={`explorer:${e.scout_id}:member`}>
                                        Explorer: {e.first_name} {e.last_name} (#{e.scout_id}
                                        {unitLabel ? ` • ${unitLabel}` : ''})
                                    </option>
                                );
                            })}
                        </optgroup>
                    )}
                </select>

                {activeTarget && (
                    <button
                        type="button"
                        className="button button-secondary ems-portal-admin-bar__btn-reset"
                        onClick={() => onSelectTarget(null)}
                    >
                        Exit Preview
                    </button>
                )}
            </div>
        </div>
    );
};
