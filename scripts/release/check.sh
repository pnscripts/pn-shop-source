#!/usr/bin/env bash
# Everything the lint and tests workflows check, in the same order, so a
# change is known to be green before it is pushed.
#
# Usage: scripts/release/check.sh
#
# Needs the dependencies installed (composer install, npm ci) and a .env with
# an application key, as CI makes one (cp .env.example .env, then
# php artisan key:generate). The MySQL and PostgreSQL jobs of the tests
# workflow are not repeated here; see docs/development/testing.md for those.
set -euo pipefail

cd "$(dirname "$0")/../.."

step() { printf '\n==> %s\n' "$1"; }

step 'Pint'
vendor/bin/pint --test

step 'Larastan (app, level 5)'
vendor/bin/phpstan analyse --no-progress --memory-limit=1G

step 'Larastan (core, level 7)'
vendor/bin/phpstan analyse -c phpstan-core.neon --no-progress --memory-limit=1G

step 'Prettier'
npm run format:check

step 'ESLint'
npm run lint:check

step 'TypeScript'
npm run types

step 'Composer audit'
composer audit

step 'npm audit (what ships)'
npm audit --omit=dev --audit-level=moderate

step 'npm audit (build and development tools)'
npm audit --audit-level=high

step 'Build'
npm run build

step 'Bundle size'
node scripts/check-bundle-size.mjs

step 'PHPUnit'
vendor/bin/phpunit

printf '\nAll checks passed.\n'
