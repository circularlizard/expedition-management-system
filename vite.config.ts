import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';
import { writeFileSync } from 'fs';

function buildManifestPlugin() {
    return {
        name: 'ems-build-manifest',
        closeBundle() {
            const manifest = { built_at: new Date().toISOString() };
            writeFileSync(
                resolve( __dirname, 'assets/build-manifest.json' ),
                JSON.stringify( manifest, null, 2 )
            );
        },
    };
}

export default defineConfig({
    plugins: [react(), buildManifestPlugin()],
    build: {
        outDir: 'assets/js',
        emptyOutDir: false,
        rollupOptions: {
            input: {
                'column-mapper': resolve( __dirname, 'resources/js/admin/column-mapper/index.tsx' ),
                'expedition-board': resolve( __dirname, 'resources/js/admin/expedition-board/index.tsx' ),
            },
            output: {
                entryFileNames: '[name].js',
                chunkFileNames: '[name].js',
                assetFileNames: '[name].[ext]',
                manualChunks: undefined,
            },
        },
    },
});
