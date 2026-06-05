# Eleva CRM for Photographers — Frontend Source

This folder contains the **human-readable source** for the plugin's admin React app.
WordPress loads only the compiled output in `../dist/`; this `src/` tree is the
non-compiled source from which that output is built (WordPress.org guideline #4).

The same source is also mirrored publicly at <https://github.com/ateleva/fotonic>.

## Stack

- React 18 + Vite (build tool)
- Zustand (UI state) + TanStack Query (server state)
- Tailwind CSS (`preflight: false`, scoped to `#fotonic-app-root`)
- Hash router (`createHashRouter`)

## Prerequisites

- Node.js 22 (matches the CI build)
- npm 10+

## Build from source

```bash
cd src            # this folder (the Vite project root)
npm install       # or: npm ci   (uses package-lock.json for a reproducible build)
npm run build     # compiles to ../dist/
```

`npm run build` runs Vite, writes a `../dist/index.php` silence stub, and removes
the generated `index.html` (WordPress does not need it).

## Build output (`../dist/`)

| File | Source |
| --- | --- |
| `fotonic-app.js` | app entry (`src/main.jsx`) |
| `fotonic-app.css` | all styles (Tailwind + `src/index.css`) |
| `fotonic-chunk-<name>-<hash>.js` | code-split route/feature chunks (lazy-loaded) |
| `index.php` | `// Silence is golden.` stub |

Output naming is defined in `vite.config.js` (`rollupOptions.output`).

## Other scripts

```bash
npm run watch     # rebuild ../dist/ on every save (development)
npm run dev       # Vite dev server with HMR (proxies /wp-json to fotonic.local)
npm run lint      # ESLint
```

## Layout

```text
src/
├── main.jsx            app entry + Pro-component injection points
├── router.jsx          hash routes
├── App.jsx
├── index.css           Tailwind entry
├── api/                REST clients (X-WP-Nonce auth)
├── components/         shared UI (Button, Modal, Table, …)
├── features/           customers / services / works / dashboard / vault / settings / calendar
├── context/            VaultContext
├── lib/                webcrypto.js (browser-side AES-GCM, v2 layer)
└── utils/              date.js, i18n.js
```
