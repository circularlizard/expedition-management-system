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
        outDir: resolve( __dirname, 'assets/js' ),
        emptyOutDir: true,
        chunkSizeWarningLimit: 1000,
        rollupOptions: {
            input: {
                'expedition-board':  resolve( __dirname, 'resources/js/admin/expedition-board/index.tsx' ),
                'signups-board':     resolve( __dirname, 'resources/js/admin/signups-board/index.tsx' ),
                'email-templates':   resolve( __dirname, 'resources/js/admin/email-templates/index.tsx' ),
                'volunteers':        resolve( __dirname, 'resources/js/admin/volunteers/index.tsx' ),
                'volunteer-signup':   resolve( __dirname, 'resources/js/admin/volunteers/signup-wizard.tsx' ),
                'ems-portal':         resolve( __dirname, 'resources/js/portal/index.tsx' ),
                'leader-dashboard':   resolve( __dirname, 'resources/js/leader-dashboard/index.tsx' ),
                'fluent-forms-sync':  resolve( __dirname, 'resources/js/fluent-forms/index.ts' ),
                'route-card-studio': resolve( __dirname, 'resources/js/route-card/standalone/index.tsx' ),
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
