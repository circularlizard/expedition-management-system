import React from 'react';
import { useBoard } from './useBoard';
import { OSMReference } from './OSMReference';

export const ExplorersPage: React.FC = () => {
    const { data, loading, error, refetch } = useBoard();

    if (loading) return <p>Loading explorers...</p>;
    if (error) return <div className="notice notice-error"><p>{error}</p></div>;
    if (!data) return <p>No data loaded.</p>;

    return (
        <div className="ems-board">
            <OSMReference data={data} onChanged={refetch} />
        </div>
    );
};

export default ExplorersPage;
