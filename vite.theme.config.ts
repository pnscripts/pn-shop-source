import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { defineConfig } from 'vite';
import { coreRoot, themeChain, themeOverrides } from './scripts/vite-theme-overrides';

/**
 * Builds a theme into themes/<vendor>/<name>/dist: the whole storefront, with the theme's
 * files (and its parents') replacing the storefront's files of the same path. With --ssr,
 * the server-side rendering bundle goes to themes/<vendor>/<name>/ssr (never published).
 *
 *     npm run build:theme -- acme/aurora
 */
const root = import.meta.dirname;
const core = coreRoot(root);
const themeId = process.env.PNSHOP_THEME ?? '';

if (!themeId) {
    throw new Error('Set PNSHOP_THEME to the theme id, e.g. PNSHOP_THEME=acme/aurora (or use npm run build:theme -- acme/aurora).');
}

const chain = themeChain(root, themeId);
const themeDirectory = resolve(root, 'themes', themeId);
const manifest = JSON.parse(readFileSync(resolve(themeDirectory, 'pnshop.json'), 'utf8')) as { entries?: string[] };

export default defineConfig(({ isSsrBuild }) => ({
    // Entries and manifest keys stay "resources/js/app.tsx", wherever the core is installed.
    root: core,
    envDir: root,
    publicDir: false,
    plugins: [
        themeOverrides(core, chain),
        laravel({
            input: manifest.entries ?? ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.tsx',
            // URLs point where the bundle is published (public/themes/<id>/build) ...
            buildDirectory: `themes/${themeId}/build`,
            refresh: false,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            'ziggy-js': resolve(root, 'vendor/tightenco/ziggy'),
        },
    },
    build: {
        // ... while the files are written into the theme, which ships them.
        outDir: resolve(themeDirectory, isSsrBuild ? 'ssr' : 'dist'),
        emptyOutDir: true,
    },
}));
