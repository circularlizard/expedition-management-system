import React, { useEffect, useRef, useState } from 'react';
import { ExpeditionRouteDay } from './types';

interface OSMReadOnlyMapProps {
    days?: ExpeditionRouteDay[];
    startLocation?: string;
    endLocation?: string;
}

const DAY_COLORS = ['#2271b1', '#15803d', '#b45309', '#7e22ce', '#be123c', '#0f766e'];

let leafletPromise: Promise<void> | null = null;
const loadLeaflet = (): Promise<void> => {
    if (leafletPromise) return leafletPromise;
    leafletPromise = new Promise((resolve, reject) => {
        if ((window as any).L) {
            resolve();
            return;
        }
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        document.head.appendChild(link);

        const script = document.createElement('script');
        script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Leaflet load failed'));
        document.body.appendChild(script);
    });
    return leafletPromise;
};

const parseCoords = (val?: string): [number, number] | null => {
    if (!val) return null;
    const match = val.trim().match(/^(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)$/);
    if (match) {
        return [parseFloat(match[1]), parseFloat(match[2])];
    }
    return null;
};

export const OSMReadOnlyMap: React.FC<OSMReadOnlyMapProps> = ({ days = [], startLocation, endLocation }) => {
    const mapContainerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<any>(null);
    const [leafletLoaded, setLeafletLoaded] = useState(false);

    const hasDayCoords = days.some(
        (d) =>
            (d.start.lat !== null && d.start.lng !== null) ||
            (d.end.lat !== null && d.end.lng !== null) ||
            d.checkpoints.some((cp) => cp.lat !== null && cp.lng !== null)
    );

    const startCoords = parseCoords(startLocation);
    const endCoords = parseCoords(endLocation);

    if (!hasDayCoords && !startCoords && !endCoords) {
        return null;
    }

    useEffect(() => {
        loadLeaflet()
            .then(() => setLeafletLoaded(true))
            .catch((err) => console.error('Failed to load Leaflet:', err));
    }, []);

    useEffect(() => {
        if (!leafletLoaded || !mapContainerRef.current) return;
        const L = (window as any).L;
        if (!L) return;

        const map = L.map(mapContainerRef.current, {
            zoomControl: true,
            dragging: true,
            scrollWheelZoom: false,
        }).setView(startCoords || endCoords || [55.9533, -3.1883], 12);
        mapRef.current = map;

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap contributors',
        }).addTo(map);

        const allCoords: [number, number][] = [];

        if (hasDayCoords) {
            days.forEach((day, dIdx) => {
                const color = DAY_COLORS[dIdx % DAY_COLORS.length];
                const dayCoords: [number, number][] = [];

                const addMarker = (
                    lat: number | null,
                    lng: number | null,
                    badge: string,
                    popup: string,
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
                    L.marker(pt, { icon }).addTo(map).bindPopup(popup);
                };

                addMarker(day.start.lat, day.start.lng, `D${day.day_number} S`, `Day ${day.day_number} Start: ${day.start.label}`, true);
                day.checkpoints.forEach((cp, cpIdx) => {
                    addMarker(cp.lat, cp.lng, `D${day.day_number}·${cpIdx + 1}`, `Day ${day.day_number} CP${cpIdx + 1}: ${cp.label}`, false);
                });
                addMarker(day.end.lat, day.end.lng, `D${day.day_number} E`, `Day ${day.day_number} End: ${day.end.label}`, true);

                if (dayCoords.length >= 2) {
                    L.polyline(dayCoords, { color, weight: 4, opacity: 0.85 }).addTo(map);
                }
            });
        } else {
            if (startCoords) {
                allCoords.push(startCoords);
                const startIcon = L.divIcon({
                    html: '<div style="background-color:#2e7d32;color:white;border:2px solid white;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:bold;box-shadow:0 2px 4px rgba(0,0,0,0.3)">S</div>',
                    className: 'custom-div-icon',
                    iconSize: [24, 24],
                    iconAnchor: [12, 12],
                });
                L.marker(startCoords, { icon: startIcon }).addTo(map).bindPopup('Start Location');
            }
            if (endCoords) {
                allCoords.push(endCoords);
                const endIcon = L.divIcon({
                    html: '<div style="background-color:#c62828;color:white;border:2px solid white;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:bold;box-shadow:0 2px 4px rgba(0,0,0,0.3)">E</div>',
                    className: 'custom-div-icon',
                    iconSize: [24, 24],
                    iconAnchor: [12, 12],
                });
                L.marker(endCoords, { icon: endIcon }).addTo(map).bindPopup('End Location');
            }
            if (startCoords && endCoords) {
                L.polyline([startCoords, endCoords], { color: '#2271b1', weight: 4, opacity: 0.7 }).addTo(map);
            }
        }

        if (allCoords.length >= 2) {
            map.fitBounds(L.latLngBounds(allCoords), { padding: [36, 36] });
        } else if (allCoords.length === 1) {
            map.setView(allCoords[0], 13);
        }

        return () => {
            map.remove();
            mapRef.current = null;
        };
    }, [leafletLoaded, days, startLocation, endLocation, hasDayCoords]);

    return (
        <div className="ems-map-readonly-wrapper" data-testid="read-only-map">
            <div ref={mapContainerRef} className="ems-map-readonly-canvas" />
        </div>
    );
};

export default OSMReadOnlyMap;
