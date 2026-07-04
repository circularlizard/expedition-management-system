import React, { useEffect, useRef, useState } from 'react';

interface OSMReadOnlyMapProps {
    startLocation?: string;
    endLocation?: string;
}

let leafletPromise: Promise<void> | null = null;
const loadLeaflet = (): Promise<void> => {
    if (leafletPromise) return leafletPromise;
    leafletPromise = new Promise((resolve, reject) => {
        if ((window as any).L) {
            resolve();
            return;
        }
        // CSS
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        document.head.appendChild(link);

        // JS
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

export const OSMReadOnlyMap: React.FC<OSMReadOnlyMapProps> = ({ startLocation, endLocation }) => {
    const mapContainerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<any>(null);
    const [leafletLoaded, setLeafletLoaded] = useState(false);

    const startCoords = parseCoords(startLocation);
    const endCoords = parseCoords(endLocation);

    // If no valid coordinates, don't show map at all
    if (!startCoords && !endCoords) {
        return null;
    }

    useEffect(() => {
        loadLeaflet().then(() => {
            setLeafletLoaded(true);
        }).catch(err => {
            console.error('Failed to load Leaflet:', err);
        });
    }, []);

    useEffect(() => {
        if (!leafletLoaded || !mapContainerRef.current) return;
        const L = (window as any).L;
        if (!L) return;

        let center: [number, number] = [55.9533, -3.1883];
        let zoom = 11;

        if (startCoords) {
            center = startCoords;
            zoom = 13;
        } else if (endCoords) {
            center = endCoords;
            zoom = 13;
        }

        const map = L.map(mapContainerRef.current, {
            zoomControl: true,
            dragging: true,
            scrollWheelZoom: false,
        }).setView(center, zoom);
        mapRef.current = map;

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap contributors',
        }).addTo(map);

        const markers: any[] = [];

        if (startCoords) {
            const startIcon = L.divIcon({
                html: '<div style="background-color: #2e7d32; color: white; border: 2px solid white; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.3)">S</div>',
                className: 'custom-div-icon',
                iconSize: [24, 24],
                iconAnchor: [12, 12],
            });
            const m = L.marker(startCoords, { icon: startIcon }).addTo(map).bindPopup('Start Location');
            markers.push(m);
        }

        if (endCoords) {
            const endIcon = L.divIcon({
                html: '<div style="background-color: #c62828; color: white; border: 2px solid white; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.3)">E</div>',
                className: 'custom-div-icon',
                iconSize: [24, 24],
                iconAnchor: [12, 12],
            });
            const m = L.marker(endCoords, { icon: endIcon }).addTo(map).bindPopup('End Location');
            markers.push(m);
        }

        if (startCoords && endCoords) {
            L.polyline([startCoords, endCoords], { color: '#2271b1', weight: 4, opacity: 0.7 }).addTo(map);
            const bounds = L.latLngBounds([startCoords, endCoords]);
            map.fitBounds(bounds, { padding: [40, 40] });
        }

        return () => {
            map.remove();
            mapRef.current = null;
        };
    }, [leafletLoaded, startLocation, endLocation]);

    return (
        <div style={{ marginTop: '16px' }} data-testid="read-only-map">
            <div 
                ref={mapContainerRef} 
                style={{ 
                    height: '250px', 
                    width: '100%', 
                    borderRadius: '4px', 
                    border: '1px solid #ccd0d4',
                    boxShadow: 'inset 0 1px 2px rgba(0,0,0,0.07)'
                }} 
            />
        </div>
    );
};

export default OSMReadOnlyMap;
