import React from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
  faPersonHiking,
  faBicycle,
  faWater,
  faEnvelope,
  faWandMagicSparkles,
} from '@fortawesome/free-solid-svg-icons';

export type PlanningLevel = 'bronze' | 'silver' | 'gold';

const ALL_LEVELS: PlanningLevel[] = ['bronze', 'silver', 'gold'];

export interface TeamScopeBarProps {
  levels: PlanningLevel[];
  transport: string;
  pendingNotifyCount: number;
  onChangeLevels: (levels: PlanningLevel[]) => void;
  onChangeTransport: (transport: string) => void;
  onOpenNotify: () => void;
  onOpenAutoPropose?: () => void;
}

const ALLOWED_TRANSPORTS = [
  { key: 'hillwalking', label: 'Hillwalking', icon: faPersonHiking },
  { key: 'biking', label: 'Biking', icon: faBicycle },
  { key: 'paddling', label: 'Paddling', icon: faWater },
];

export const TeamScopeBar: React.FC<TeamScopeBarProps> = ({
  levels,
  transport,
  pendingNotifyCount,
  onChangeLevels,
  onChangeTransport,
  onOpenNotify,
  onOpenAutoPropose,
}) => {
  const allSelected = ALL_LEVELS.every((l) => levels.includes(l));

  const toggleLevel = (target: PlanningLevel) => {
    const isSelected = levels.includes(target);
    if (isSelected) {
      if (levels.length <= 1) return; // Keep at least 1 level selected
      onChangeLevels(levels.filter((l) => l !== target));
      return;
    }
    onChangeLevels(ALL_LEVELS.filter((l) => l === target || levels.includes(l)));
  };

  return (
    <div
      className="ems-planning-scope-bar"
      role="region"
      aria-label="Team Scope Filters"
      style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '12px' }}
    >
      <div style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: '16px' }}>
        <div className="ems-planning-scope-group">
          <span className="ems-planning-scope-label">Level:</span>
          <div className="ems-btn-group" role="group" aria-label="Level Selector">
            {ALL_LEVELS.map((l) => {
              const active = levels.includes(l);
              return (
                <button
                  key={l}
                  type="button"
                  aria-pressed={active}
                  className={`ems-scope-btn ${active ? 'ems-scope-btn--active' : ''}`}
                  onClick={() => toggleLevel(l)}
                >
                  {l.charAt(0).toUpperCase() + l.slice(1)}
                </button>
              );
            })}
            <button
              type="button"
              aria-pressed={allSelected}
              className={`ems-scope-btn ${allSelected ? 'ems-scope-btn--active' : ''}`}
              onClick={() => onChangeLevels([...ALL_LEVELS])}
            >
              All
            </button>
          </div>
        </div>

      <div className="ems-planning-scope-group">
        <span className="ems-planning-scope-label">Mode:</span>
        <div className="ems-btn-group" role="group" aria-label="Transport Mode Selector">
          {ALLOWED_TRANSPORTS.map((t) => (
            <button
              key={t.key}
              type="button"
              className={`ems-scope-btn ${transport === t.key ? 'ems-scope-btn--active' : ''}`}
              onClick={() => onChangeTransport(t.key)}
            >
              <FontAwesomeIcon icon={t.icon} className="ems-scope-btn-icon" /> {t.label}
            </button>
          ))}
        </div>
      </div>
    </div>

    <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
      {onOpenAutoPropose && (
        <button type="button" className="button button-secondary" onClick={onOpenAutoPropose}>
          <FontAwesomeIcon icon={faWandMagicSparkles} /> Auto-Propose Teams
        </button>
      )}
      <button type="button" className="button button-primary" onClick={onOpenNotify}>
        <FontAwesomeIcon icon={faEnvelope} /> Notify Teams…
        {pendingNotifyCount > 0 && (
          <span
            className="ems-badge"
            style={{
              marginLeft: '6px',
              background: '#ffffff',
              color: '#1e40af',
              fontWeight: 700,
              padding: '1px 6px',
              borderRadius: '10px',
              fontSize: '11px',
            }}
          >
            {pendingNotifyCount}
          </span>
        )}
      </button>
    </div>
  </div>
  );
};
