# Third-party notices

PN Scripts Shop is (c) 2026 ПН СКРИПТС ЕООД (PN Scripts) and released under the MIT licence (see `LICENSE`).
Third-party components keep their own licences. Composer and npm packages are not committed; they are
downloaded at install time under the licences recorded in `composer.lock` and `package-lock.json`.

## Code and assets derived from or shipped with other projects

| Component | Where | Licence |
|---|---|---|
| Laravel React starter kit (layout and shadcn/ui-style component conventions the storefront and `packages/pn-shop-core/resources/js/components/ui/` started from), (c) Laravel LLC | storefront React code | MIT |
| shadcn/ui component patterns, Radix UI primitives, Headless UI, class-variance-authority, clsx, tailwind-merge, tw-animate-css | npm dependencies / component sources | MIT |
| Lucide icons (`lucide-react`) | npm dependency | ISC |
| Inertia.js, React, Tailwind CSS, Vite | npm dependencies | MIT |
| Filament 5 (admin panel) and its published assets, including the Inter typeface (c) The Inter Project Authors, https://github.com/rsms/inter | `php artisan filament:assets` output in `public/` (not committed) | Filament MIT; Inter SIL Open Font License 1.1 (https://openfontlicense.org/open-font-license-official-text/) |
| Laravel Telescope assets | `public/vendor/telescope/` | MIT |
| Instrument Sans web font (c) The Instrument Sans Project Authors | loaded at runtime from `fonts.bunny.net` by `packages/pn-shop-core/resources/views/app.blade.php`; not stored in this repository | SIL Open Font License 1.1 |

Sites that must not contact any third party should self-host the Instrument Sans font or change the font
stack in the theme.

The Laravel logo that an early commit contained (`public/logo.svg`, unused) was removed on 2026-10-27: it is a
Laravel trademark and is not part of PN Scripts Shop. "Laravel" appears in this project only to name the framework.
