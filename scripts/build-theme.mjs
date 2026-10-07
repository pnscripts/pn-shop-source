#!/usr/bin/env node
// npm run build:theme -- <vendor/name> [--no-ssr]: build a theme's storefront bundle into
// themes/<vendor>/<name>/dist, and its server-side rendering bundle into .../ssr.
import { spawnSync } from 'node:child_process';

const id = process.argv[2] ?? '';

if (!/^[a-z0-9-]+\/[a-z0-9-]+$/.test(id)) {
    console.error('Usage: npm run build:theme -- <vendor/name>');
    process.exit(1);
}

const build = (...args) =>
    spawnSync('npx', ['vite', 'build', '--config', 'vite.theme.config.ts', ...args], {
        stdio: 'inherit',
        env: { ...process.env, PNSHOP_THEME: id },
    }).status ?? 1;

const status = build();

process.exit(status !== 0 || process.argv.includes('--no-ssr') ? status : build('--ssr'));
