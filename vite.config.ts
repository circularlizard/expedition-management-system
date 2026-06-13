import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';

export default defineConfig({
    plugins: [react()],
    build: {
        outDir: 'assets/js',
        emptyOutDir: false,
        rollupOptions: {
            input: {
                reconciliation: resolve( __dirname, 'resources/js/admin/reconciliation/index.tsx' ),
                'column-mapper': resolve( __dirname, 'resources/js/admin/column-mapper/index.tsx' ),
                'expedition-board': resolve( __dirname, 'resources/js/admin/expedition-board/index.tsx' ),
            },
            output: {
                entryFileNames: '[name].js',
                chunkFileNames: '[name]-[hash].js',
                assetFileNames: '[name].[ext]',
            },
        },
    },
});
