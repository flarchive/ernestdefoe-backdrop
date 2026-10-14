# Backdrop: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.**
- Commit subjects are conventional (`feat:`, `fix:`, `docs:`, `chore:`). The release drafter reads the bump from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. A cloud session cannot push tags or edit releases (403 by session type): push the commits, confirm the draft release exists, and say so.

## Design rules that exist for a reason

- Never patch `app` or `app.mount`. An own `mount` on the app instance hides every later prototype hook; that is the bug that hid Modern Footer under Cover Studio. If something must run after every initializer, use `app.beforeMount(cb)`.
- No `override()` of core components, no classes on `<body>`, no CSS outside `.Backdrop*` / `.UserCard.Backdrop`.
- Card positioning goes through `:where()` so core's absolute hover cards and any theme win. A plain `.UserCard.Backdrop { position: relative }` broke every hover card.
- Cover data stays in columns on `users`. A companion table needs eager loading on every user query, which is how Cover Studio ended up with a global scope on `User`.
- Migrations run before the extension's extenders boot, so the enable migration must not touch Backdrop's disk. The import is URL-only there; `backdrop:import` copies files.
- The legacy import runs once (marker `ernestdefoe-backdrop.legacy_imported`); after that a null `backdrop_cover` can mean "removed on purpose". `backdrop:import` only re-copies files. The import migration's `down` clears the marker and every `ernestdefoe-backdrop.*` permission row.
- Never resolve `UrlGenerator` (or anything that builds routes) while the enable migration runs: it freezes a route table without Backdrop's routes for that boot, which fails the first test after `test:setup`.
- The gallery is one JSON setting. Change it only through `Presets::mutate()` / `locked()`, which take a cache lock and read the settings row directly (settings are cached per request and in Redis).
- New `users` columns must also be declared with `Extend\Model(User::class)->cast()`: that is how flarum/phpstan learns they exist (it cannot read `Migration::addColumns`).

## Testing

- Run the suite on SQLite, Postgres and MySQL, each from a fresh `composer test:setup`, with and without `DB_PREFIX`. CI does.
- MySQL commits DDL immediately, ending the test's transaction: a test that creates tables or columns must undo everything it wrote in `tearDown` (see the legacy-schema cleanup in `BackdropTest`).
- A MariaDB server needs Flarum's `mariadb` driver, not `mysql`; with `mysql`, core's notification query fails on `CAST(... AS JSON)`.
- FoF Upload is optional. `FofUpload::store()` dispatches FoF Upload's own `Upload` command (so its permission, mime config, adapter, resize, watermark and thumbnails all apply) and returns null whenever it cannot, so Backdrop stores the cover itself. Never reference FoF Upload classes outside that class or a `Conditional` extender: forums without it must boot. Its files are never deleted by Backdrop; deleting one in My Media clears the cover (`DetachDeletedFofFile`).
