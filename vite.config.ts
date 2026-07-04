import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';
import { writeFileSync } from 'fs';
import externalGlobals from 'rollup-plugin-external-globals';

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

/**
 * Map @wordpress/* imports to their runtime globals exposed by WordPress.
 * These packages are enqueued as WP script dependencies in Admin_Page.php
 * and are NOT bundled into our output files.
 */
const wpGlobals: Record<string, string> = {
    '@wordpress/components': 'wp.components',
    '@wordpress/element':    'wp.element',
    '@wordpress/i18n':       'wp.i18n',
    '@wordpress/api-fetch':  'wp.apiFetch',
    '@wordpress/hooks':      'wp.hooks',
    '@wordpress/compose':    'wp.compose',
    '@wordpress/data':       'wp.data',
};

export default defineConfig({
    plugins: [react(), buildManifestPlugin()],
    build: {
        outDir: 'assets/js',
        emptyOutDir: false,
        rollupOptions: {
            input: {
                'column-mapper':    resolve( __dirname, 'resources/js/admin/column-mapper/index.tsx' ),
                'expedition-board': resolve( __dirname, 'resources/js/admin/expedition-board/index.tsx' ),
                'signups-board':    resolve( __dirname, 'resources/js/admin/signups-board/index.tsx' ),
            },
            external: Object.keys( wpGlobals ),
            plugins: [
                externalGlobals( wpGlobals ),
            ],
            output: {
                entryFileNames: '[name].js',
                chunkFileNames: '[name].js',
                assetFileNames: '[name].[ext]',
                manualChunks: undefined,
            },
        },
    },
});
