import React from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
  faPersonHiking,
  faBicycle,
  faWater,
  faEnvelope,
} from '@fortawesome/free-solid-svg-icons';

export interface TeamScopeBarProps {
  level: 'bronze' | 'silver' | 'gold';
  transport: string;
  pendingNotifyCount: number;
  onChangeLevel: (level: 'bronze' | 'silver' | 'gold') => void;
  onChangeTransport: (transport: string) => void;
  onOpenNotify: () => void;
}

const ALLOWED_TRANSPORTS = [
  { key: 'hillwalking', label: 'Hillwalking', icon: faPersonHiking },
  { key: 'biking', label: 'Biking', icon: faBicycle },
  { key: 'paddling', label: 'Paddling', icon: faWater },
];

export const TeamScopeBar: React.FC<TeamScopeBarProps> = ({
  level,
  transport,
  pendingNotifyCount,
  onChangeLevel,
  onChangeTransport,
  onOpenNotify,
}) => (
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
          {(['bronze', 'silver', 'gold'] as const).map((l) => (
            <button
              key={l}
              type="button"
              className={`ems-scope-btn ${level === l ? 'ems-scope-btn--active' : ''}`}
              onClick={() => onChangeLevel(l)}
            >
              {l.charAt(0).toUpperCase() + l.slice(1)}
            </button>
          ))}
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
