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
