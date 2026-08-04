# Block editor browser tests

These tests are read-only and run against a prepared Mublo test installation.

Required for authenticated scenarios:

- `MUBLO_E2E_BASE_URL`
- `MUBLO_E2E_ADMIN_ID` (domain operator or super administrator)
- `MUBLO_E2E_ADMIN_PASSWORD`

Optional prepared fixtures:

- `MUBLO_E2E_INACTIVE_PAGE_CODE`: an inactive block page
- `MUBLO_E2E_BLOCK_ROW_ID`: a block row owned by the current domain

Run `npm install`, install Chromium once with `npx playwright install chromium`, then run
`npm run test:e2e`. Scenarios without their fixture variables are reported as skipped.
