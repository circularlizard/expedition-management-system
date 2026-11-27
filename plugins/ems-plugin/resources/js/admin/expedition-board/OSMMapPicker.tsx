import React, { useEffect, useRef, useState } from 'react';
import { ExpeditionRouteDay } from './types';

export interface ActiveWaypointTarget {
    dayIndex: number;
    kind: 'start' | 'checkpoint' | 'end';
    checkpointIndex?: number;
}

interface OSMMapPickerProps {
    days: ExpeditionRouteDay[];
    activeTarget: ActiveWaypointTarget | null;
    onSelectTarget: (target: ActiveWaypointTarget | null) => void;
    onMapPick: (target: ActiveWaypointTarget, lat: number, lng: number) => void;
    onAddCheckpoint: (dayIndex: number) => void;
}

const DAY_COLORS = ['#2271b1', '#15803d', '#b45309', '#7e22ce', '#be123c', '#0f766e'];

const loadLeaflet = (): Promise<any> => {
    return new Promise((resolve, reject) => {
        if ((window as any).L) {
            resolve((window as any).L);
            return;
        }
        if (!document.getElementById('leaflet-css')) {
            const link = document.createElement('link');
            link.id = 'leaflet-css';
            link.rel = 'stylesheet';
            link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            document.head.appendChild(link);
        }
        const script = document.createElement('script');
        script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        script.onload = () => resolve((window as any).L);
        script.onerror = reject;
        document.body.appendChild(script);
    });
};

const targetKey = (t: ActiveWaypointTarget | null): string => {
    if (!t) return '';
    return t.kind === 'checkpoint'
        ? `${t.dayIndex}:cp:${t.checkpointIndex ?? 0}`
        : `${t.dayIndex}:${t.kind}`;
};

const describeTarget = (t: ActiveWaypointTarget | null, days: ExpeditionRouteDay[]): string => {
    if (!t || !days[t.dayIndex]) return '';
    const dayNum = days[t.dayIndex].day_number;
    if (t.kind === 'start') return `Day ${dayNum} · Start`;
    if (t.kind === 'end') return `Day ${dayNum} · End`;
    return `Day ${dayNum} · Checkpoint ${(t.checkpointIndex ?? 0) + 1}`;
};

export const OSMMapPicker: React.FC<OSMMapPickerProps> = ({
    days,
    activeTarget,
    onSelectTarget,
    onMapPick,
    onAddCheckpoint,
}) => {
    const mapContainerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<any>(null);
    const layerGroupRef = useRef<any>(null);

    const [leafletLoaded, setLeafletLoaded] = useState(false);
    const [toolbarDayIndex, setToolbarDayIndex] = useState(0);

    const activeTargetRef = useRef(activeTarget);
    const onMapPickRef = useRef(onMapPick);
    useEffect(() => {
        activeTargetRef.current = activeTarget;
    }, [activeTarget]);
    useEffect(() => {
        onMapPickRef.current = onMapPick;
    }, [onMapPick]);

    useEffect(() => {
        if (toolbarDayIndex >= days.length && days.length > 0) {
            setToolbarDayIndex(days.length - 1);
        }
    }, [days.length, toolbarDayIndex]);

    useEffect(() => {
        loadLeaflet()
            .then(() => setLeafletLoaded(true))
            .catch((err) => console.error('Failed to load Leaflet:', err));
    }, []);

    useEffect(() => {
        if (!leafletLoaded || !mapContainerRef.current) return;
        const L = (window as any).L;
        if (!L) return;

        const map = L.map(mapContainerRef.current).setView([55.9533, -3.1883], 11);
        mapRef.current = map;
        layerGroupRef.current = L.layerGroup().addTo(map);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap contributors',
        }).addTo(map);

        map.on('click', (e: any) => {
            const target = activeTargetRef.current;
            if (!target) return;
            const { lat, lng } = e.latlng;
            onMapPickRef.current(target, Number(lat.toFixed(6)), Number(lng.toFixed(6)));
        });

        return () => {
            map.remove();
            mapRef.current = null;
            layerGroupRef.current = null;
        };
    }, [leafletLoaded]);

    useEffect(() => {
        if (!leafletLoaded || !mapRef.current || !layerGroupRef.current) return;
        const L = (window as any).L;
        const map = mapRef.current;
        const group = layerGroupRef.current;
        group.clearLayers();

        const allCoords: [number, number][] = [];

        days.forEach((day, dIdx) => {
            const color = DAY_COLORS[dIdx % DAY_COLORS.length];
            const dayCoords: [number, number][] = [];

            const addPointMarker = (
                lat: number | null,
                lng: number | null,
                badge: string,
                title: string,
                isEndpoint: boolean
            ) => {
                if (lat === null || lng === null || Number.isNaN(lat) || Number.isNaN(lng)) return;
                const pt: [number, number] = [lat, lng];
                dayCoords.push(pt);
                allCoords.push(pt);

                const icon = L.divIcon({
                    html: `<div style="background-color:${isEndpoint ? color : '#ffffff'};color:${isEndpoint ? '#ffffff' : color};border:2px solid ${color};border-radius:9999px;min-width:26px;height:24px;padding:0 5px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;box-shadow:0 2px 4px rgba(0,0,0,0.25);white-space:nowrap;">${badge}</div>`,
                    className: 'ems-day-marker-icon',
                    iconSize: [28, 24],
                    iconAnchor: [14, 12],
                });
                L.marker(pt, { icon }).addTo(group).bindPopup(`<strong>${title}</strong><br/>${lat.toFixed(5)}, ${lng.toFixed(5)}`);
            };

            addPointMarker(day.start.lat, day.start.lng, `D${day.day_number} S`, `Day ${day.day_number} Start: ${day.start.label}`, true);
            day.checkpoints.forEach((cp, cpIdx) => {
                addPointMarker(cp.lat, cp.lng, `D${day.day_number}·${cpIdx + 1}`, `Day ${day.day_number} CP${cpIdx + 1}: ${cp.label}`, false);
            });
            addPointMarker(day.end.lat, day.end.lng, `D${day.day_number} E`, `Day ${day.day_number} End: ${day.end.label}`, true);

            if (dayCoords.length >= 2) {
                L.polyline(dayCoords, { color, weight: 4, opacity: 0.85 }).addTo(group);
            }
        });

        if (allCoords.length >= 2) {
            map.fitBounds(L.latLngBounds(allCoords), { padding: [36, 36] });
        } else if (allCoords.length === 1) {
            map.setView(allCoords[0], 13);
        }
    }, [leafletLoaded, days]);

    const selectedDayIdx = Math.min(toolbarDayIndex, Math.max(0, days.length - 1));
    const currentDay = days[selectedDayIdx];

    return (
        <div className="ems-map-picker">
            <div className="ems-map-picker__header">
                <span className="ems-map-picker__title">Interactive Route Map</span>

                {days.length > 0 && (
                    <div className="ems-map-picker__controls">
                        <select
                            aria-label="Active day for map toolbar"
                            className="ems-form-input ems-map-picker__day-select"
                            value={selectedDayIdx}
                            onChange={(e) => setToolbarDayIndex(Number(e.target.value))}
                        >
                            {days.map((d, idx) => (
                                <option key={d.day_number} value={idx}>
                                    Day {d.day_number}{d.date ? ` (${d.date})` : ''}
                                </option>
                            ))}
                        </select>

                        <select
                            aria-label="Active waypoint target"
                            className="ems-form-input ems-map-picker__target-select"
                            value={targetKey(activeTarget)}
                            onChange={(e) => {
                                const val = e.target.value;
                                if (!val) {
                                    onSelectTarget(null);
                                    return;
                                }
                                const [dStr, kind, cpStr] = val.split(':');
                                const dayIndex = Number(dStr);
                                if (kind === 'cp') {
                                    onSelectTarget({ dayIndex, kind: 'checkpoint', checkpointIndex: Number(cpStr) });
                                } else if (kind === 'start' || kind === 'end') {
                                    onSelectTarget({ dayIndex, kind });
                                }
                            }}
                        >
                            <option value="">Select waypoint to place on map…</option>
                            {days.map((d, dIdx) => (
                                <React.Fragment key={d.day_number}>
                                    <option value={`${dIdx}:start`}>Day {d.day_number} · Start</option>
                                    {d.checkpoints.map((_, cpIdx) => (
                                        <option key={`cp-${cpIdx}`} value={`${dIdx}:cp:${cpIdx}`}>
                                            Day {d.day_number} · Checkpoint {cpIdx + 1}
                                        </option>
                                    ))}
                                    <option value={`${dIdx}:end`}>Day {d.day_number} · End</option>
                                </React.Fragment>
                            ))}
                        </select>

                        {currentDay && (
                            <button
                                type="button"
                                className="button button-small"
                                onClick={() => onAddCheckpoint(selectedDayIdx)}
                            >
                                + Add Checkpoint to Day {currentDay.day_number}
                            </button>
                        )}

                        {activeTarget && (
                            <button
                                type="button"
                                className="button-link ems-import-error"
                                onClick={() => onSelectTarget(null)}
                            >
                                Cancel Pick
                            </button>
                        )}
                    </div>
                )}
            </div>

            {activeTarget && (
                <div className="ems-map-picker__banner">
                    Click anywhere on the map to set coordinates for <strong>{describeTarget(activeTarget, days)}</strong>.
                </div>
            )}

            {!leafletLoaded ? (
                <div className="ems-map-placeholder">Loading OpenStreetMap…</div>
            ) : (
                <div ref={mapContainerRef} className="ems-map-canvas" />
            )}

            <p className="ems-map-help">
                Choose a waypoint target above (or click &ldquo;Pick on Map&rdquo; on any row below), then click the map to set its coordinates.
            </p>
        </div>
    );
};
