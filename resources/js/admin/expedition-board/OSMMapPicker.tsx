import React, { useEffect, useRef, useState } from 'react';

interface OSMMapPickerProps {
    startValue: string;
    endValue: string;
    onSelectStart: (val: string) => void;
    onSelectEnd: (val: string) => void;
}

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

const parseCoords = (val: string): [number, number] | null => {
    if (!val) return null;
    const match = val.match(/^(-?\d+\.\d+),\s*(-?\d+\.\d+)$/);
    if (match) {
        return [parseFloat(match[1]), parseFloat(match[2])];
    }
    return null;
};

const reverseGeocode = async (lat: number, lng: number): Promise<string> => {
    try {
        const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`, {
            headers: { 'Accept-Language': 'en' }
        });
        if (res.ok) {
            const data = await res.json();
            const addr = data.address;
            const name = addr.road || addr.suburb || addr.town || addr.city || addr.county || '';
            const county = addr.county || addr.state || '';
            return name ? (county ? `${name}, ${county}` : name) : `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
        }
    } catch (e) {
        console.error(e);
    }
    return `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
};

export const OSMMapPicker: React.FC<OSMMapPickerProps> = ({
    startValue,
    endValue,
    onSelectStart,
    onSelectEnd,
}) => {
    const mapContainerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<any>(null);
    const startMarkerRef = useRef<any>(null);
    const endMarkerRef = useRef<any>(null);
    const routeLineRef = useRef<any>(null);
    
    const [leafletLoaded, setLeafletLoaded] = useState(false);
    const [mode, setMode] = useState<'idle' | 'setStart' | 'setEnd'>('idle');

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

        // Standard Edinburgh/UK default center
        const defaultCenter: [number, number] = [55.9533, -3.1883];
        let center = defaultCenter;
        let zoom = 11;

        const startCoords = parseCoords(startValue);
        const endCoords = parseCoords(endValue);

        if (startCoords) {
            center = startCoords;
            zoom = 13;
        } else if (endCoords) {
            center = endCoords;
            zoom = 13;
        }

        const map = L.map(mapContainerRef.current).setView(center, zoom);
        mapRef.current = map;

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap contributors',
        }).addTo(map);

        // Map Click Event
        map.on('click', async (e: any) => {
            const { lat, lng } = e.latlng;
            const address = await reverseGeocode(lat, lng);
            const valueStr = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;

            if (mode === 'setStart') {
                onSelectStart(valueStr);
                setMode('idle');
            } else if (mode === 'setEnd') {
                onSelectEnd(valueStr);
                setMode('idle');
            }
        });

        return () => {
            map.remove();
            mapRef.current = null;
            startMarkerRef.current = null;
            endMarkerRef.current = null;
            routeLineRef.current = null;
        };
    }, [leafletLoaded]);

    // Handle marker / route updates when values change
    useEffect(() => {
        if (!leafletLoaded || !mapRef.current) return;
        const L = (window as any).L;
        const map = mapRef.current;

        const startCoords = parseCoords(startValue);
        const endCoords = parseCoords(endValue);

        // 1. Start Marker
        if (startCoords) {
            if (startMarkerRef.current) {
                startMarkerRef.current.setLatLng(startCoords);
            } else {
                const greenIcon = new L.Icon({
                    iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png',
                    shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                    iconSize: [25, 41],
                    iconAnchor: [12, 41],
                    popupAnchor: [1, -34],
                    shadowSize: [41, 41]
                });
                startMarkerRef.current = L.marker(startCoords, { icon: greenIcon })
                    .addTo(map)
                    .bindPopup('<b>Start Location</b>');
            }
        } else {
            if (startMarkerRef.current) {
                map.removeLayer(startMarkerRef.current);
                startMarkerRef.current = null;
            }
        }

        // 2. End Marker
        if (endCoords) {
            if (endMarkerRef.current) {
                endMarkerRef.current.setLatLng(endCoords);
            } else {
                const redIcon = new L.Icon({
                    iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
                    shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                    iconSize: [25, 41],
                    iconAnchor: [12, 41],
                    popupAnchor: [1, -34],
                    shadowSize: [41, 41]
                });
                endMarkerRef.current = L.marker(endCoords, { icon: redIcon })
                    .addTo(map)
                    .bindPopup('<b>End Location</b>');
            }
        } else {
            if (endMarkerRef.current) {
                map.removeLayer(endMarkerRef.current);
                endMarkerRef.current = null;
            }
        }

        // 3. Polyline between markers
        if (startCoords && endCoords) {
            if (routeLineRef.current) {
                routeLineRef.current.setLatLngs([startCoords, endCoords]);
            } else {
                routeLineRef.current = L.polyline([startCoords, endCoords], { color: '#2271b1', weight: 4, dashArray: '5, 10' })
                    .addTo(map);
            }
            
            // Adjust bounds to fit both markers
            const bounds = L.latLngBounds([startCoords, endCoords]);
            map.fitBounds(bounds, { padding: [40, 40] });
        } else {
            if (routeLineRef.current) {
                map.removeLayer(routeLineRef.current);
                routeLineRef.current = null;
            }
        }
    }, [leafletLoaded, startValue, endValue]);

    return (
        <div className="ems-osm-picker" style={{ marginBottom: '16px', background: '#fafafa', border: '1px solid #dcdcde', borderRadius: '4px', padding: '12px' }}>
            <div style={{ display: 'flex', gap: '10px', marginBottom: '10px', alignItems: 'center' }}>
                <span style={{ fontSize: '13px', fontWeight: 600, color: '#1d2327' }}>📍 Interactive OSM Map Picker</span>
                <button
                    type="button"
                    className={`button ${mode === 'setStart' ? 'button-primary' : ''}`}
                    style={{ fontSize: '12px' }}
                    onClick={() => setMode(mode === 'setStart' ? 'idle' : 'setStart')}
                >
                    {mode === 'setStart' ? 'Click map to set Start...' : '🗺️ Set Start Point'}
                </button>
                <button
                    type="button"
                    className={`button ${mode === 'setEnd' ? 'button-primary' : ''}`}
                    style={{ fontSize: '12px' }}
                    onClick={() => setMode(mode === 'setEnd' ? 'idle' : 'setEnd')}
                >
                    {mode === 'setEnd' ? 'Click map to set End...' : '🗺️ Set End Point'}
                </button>
                {mode !== 'idle' && (
                    <button type="button" className="button-link" style={{ fontSize: '12px', color: '#d63638' }} onClick={() => setMode('idle')}>
                        Cancel
                    </button>
                )}
            </div>
            
            {!leafletLoaded ? (
                <div style={{ height: '300px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: '#f0f0f1', color: '#646970', borderRadius: '3px' }}>
                    Loading OpenStreetMaps widget…
                </div>
            ) : (
                <div 
                    ref={mapContainerRef} 
                    style={{ height: '300px', borderRadius: '3px', border: '1px solid #dcdcde', zIndex: 1 }} 
                />
            )}
            <p style={{ margin: '6px 0 0', fontSize: '11px', color: '#646970' }}>
                💡 Click one of the buttons above, then click on the map to set the starting or ending coordinates (lat, lng).
            </p>
        </div>
    );
};

export default OSMMapPicker;
