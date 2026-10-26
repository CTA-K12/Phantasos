# CTA Symfony Starter

Reusable Symfony 7.4 starter template for **Cascade Technology Alliance (CTA)** internal applications. CTA is a division of MESD (Multnomah Education Service District) that provides IT support for MESD and NWRESD.

All CTA apps share: Google OAuth authentication, the same PostgreSQL server, the same role hierarchy, and consistent UI conventions. When starting a new app, clone this repo and build on top of it — do not scaffold from `symfony new`.

> **First step for any new app**: Update `APP_NAME` in `.env`, the sidebar brand in `templates/_partials/_sidebar.html.twig`, and this `CLAUDE.md` with the app's name, purpose, and entity model.

## Technology Stack

- **Framework**: Symfony 7.4 LTS (PHP 8.5)
- **Database**: PostgreSQL at `appdev-db2.mesd.k12.or.us` (via Doctrine ORM)
- **Frontend**: Bootstrap 5, Turbo + Stimulus (Symfony UX), ApexCharts, Lucide icons
- **Auth**: Google OAuth via KnpUOAuth2ClientBundle (pre-authorized users only)
- **Assets**: AssetMapper (importmap, no Webpack)
- **Testing**: PHPUnit 13 + DAMADoctrineTestBundle
- **Static Analysis**: PHPStan level 8
- **Primary color**: `#7a5af8` (purple) — overrides Bootstrap blue throughout

## Organization Context

- **CTA** (Cascade Technology Alliance) is the IT division of MESD
- Apps serve both MESD and NWRESD staff
- All apps use `@mesd.k12.or.us` or `@nwresd.k12.or.us` Google accounts for OAuth
- PostgreSQL server: `appdev-db2.mesd.k12.or.us`
- Each app has its own database; the database name matches the app slug

## What Is Pre-Built

The following is already in place — do not re-scaffold or recreate:

- **Google OAuth** — `GoogleAuthenticator` fully configured. Users are pre-created by email; `googleId` is linked on first login. New users default to `isActive = false` and land on `/pending-approval`.
- **User entity** — `app_user` table. Fields: `id` (UUID v7), `email`, `googleId` (nullable), `displayName`, `avatarUrl`, `roles` (JSON), `isActive`, `createdAt`, `updatedAt`, `lastLoginAt`.
- **AppSetting entity** — key/value store (`key_name` column). Seeded with `data_retention_months = 36`.
- **AuditLog entity + AuditLogger service** — append-only audit trail. Inject `AuditLogger` into controllers and call `$this->auditLogger->log('action.name', 'EntityType', $id, $metadata)`.
- **Role hierarchy**: `ROLE_ADMIN > ROLE_SUPERVISOR > ROLE_STAFF`
- **Layout**: sidebar + navbar for authenticated pages (`layout.html.twig`), minimal shell for login/public pages (`base.html.twig`)
- **Help system**: slideout offcanvas, Markdown files in `docs/help/`, route-named files, fallback to `_default.md`
- **Settings page** (`/settings`) — shows role, DB name, environment
- **Support page** (`/support`) — shows user info, DB name, environment, support link
- **Dashboard** (`/dashboard`) — sample donut chart with muted hover pattern; replace with real data
- **Stimulus controllers**: `chart`, `filter`, `table_controls`, `view_toggle`
- **PHPStan level 8** — baseline has 2 pre-approved suppressions in `GoogleAuthenticator`
- **CI** (`.github/workflows/ci.yml`) — PHPStan + PHPUnit against PostgreSQL

## Architecture

### Role Hierarchy
`ROLE_ADMIN > ROLE_SUPERVISOR > ROLE_STAFF`

All routes require at minimum `ROLE_STAFF` (set via `#[IsGranted('ROLE_STAFF')]` on controllers or globally in `security.yaml`). The `/pending-approval` route is `PUBLIC_ACCESS`.

### Security
- Google OAuth — `GoogleAuthenticator` throws `CustomUserMessageAuthenticationException` if email not in DB
- `googleId` is nullable: admins pre-create users by email; googleId is linked at first login
- New users default to `isActive = false`; admin must activate before they can log in
- `/pending-approval` is public — inactive users land here after OAuth

### Entity Conventions
- All entity IDs use **UUID v7** (`Symfony\Component\Uid\Uuid::v7()`) — prevents URL enumeration
- Table name for User is `app_user` (`KEY` and `USER` are reserved in PostgreSQL)
- AppSetting column is `key_name` (not `key` — reserved in PostgreSQL)
- Audit log uses `onDelete: SET NULL` on user/entity FKs so the trail survives deletions

### AuditLogger
Inject via constructor: `private readonly AuditLogger $auditLogger`

```php
$this->auditLogger->log(
    'user.created',          // action string — add to ACTION_LABELS in AuditLogger.php
    'User',                  // entity type
    $user->getId()->toRfc4122(),
    ['email' => $email],     // metadata array
);
```

After bulk DQL DELETE operations, call `$em->clear()` to prevent stale identity map state, then re-fetch any entities needed before calling `auditLogger->log()`. The AuditLogger already handles detached user re-fetch internally.

### InputBag — Always Use Typed Getters
Never use `$request->query->get()` or `$request->request->get()` — these return `mixed` and fail PHPStan level 8. Always use:
- `->getString('key', 'default')`
- `->getInt('key', 0)`
- `->getBool('key', false)`

For CSRF tokens: `$request->request->getString('_token')`

### Repositories — Never Call `getEntityManager()` From Outside
Add `save()`, `remove()`, and `flush()` methods to repositories instead:

```php
public function save(MyEntity $entity, bool $flush = false): void
{
    $this->getEntityManager()->persist($entity);
    if ($flush) $this->getEntityManager()->flush();
}
```

## Template Hierarchy

- `base.html.twig` — minimal shell (login, public pages). Has `{% block head %}{% endblock %}` for per-page `<head>` additions.
- `layout.html.twig` — sidebar + navbar for authenticated pages
- Page templates extend `layout.html.twig` using `{% block content %}`

## UI Patterns

### Page Headers (all authenticated pages)
```twig
<div class="row align-items-center mb-4 g-3">
    <div class="col-12 col-md">
        <h4 class="mb-0">Page Title</h4>
    </div>
    <div class="col-12 col-md-auto">
        <div class="bg-white border rounded px-3 py-2">
            <div class="row g-2">
                <div class="col-12 col-md-auto">
                    <a href="..." class="btn btn-primary btn-sm w-100">
                        <i data-lucide="plus-circle" style="width:15px;height:15px;vertical-align:-2px;"></i> Label
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
```
All buttons must include a Lucide icon.

### Table / List Pages
1. Page header (see above)
2. Wrap the entire table block in `<div data-controller="table-controls">`
3. `.table-controls-bar d-flex justify-content-between align-items-center mb-3` — gray bar (`#f5f6f8`)
   - **Left**: search input inside `input-group input-group-sm table-controls-search` with a lucide `search` icon prepended
   - **Right**: `.table-controls-actions` — `"Show"` label + per-page `<select>` (`style="width:auto;"`) + `"records"` label
4. `.card` containing a `<table>` (inside `.table-responsive`) with `thead` and `tbody[data-table-controls-target="tbody"]`
5. `.card-footer d-flex justify-content-between align-items-center py-2` with:
   - **Left**: `<span class="text-muted small" data-table-controls-target="info"></span>` — renders "Showing X–Y of Z records"
   - **Right**: `<div data-table-controls-target="pagination"></div>` — renders ellipsis-style page links

Full controls bar pattern:
```twig
<div class="table-controls-bar d-flex justify-content-between align-items-center mb-3">
    <div class="input-group input-group-sm table-controls-search">
        <span class="input-group-text bg-white border-end-0">
            <i data-lucide="search" style="width:14px;height:14px;color:#6c757d;"></i>
        </span>
        <input type="text"
               class="form-control border-start-0 ps-0"
               placeholder="Search…"
               aria-label="Search"
               data-table-controls-target="search"
               data-action="input->table-controls#search">
    </div>
    <div class="table-controls-actions">
        <span class="text-muted small">Show</span>
        <select class="form-select form-select-sm"
                style="width:auto;"
                aria-label="Records per page"
                data-table-controls-target="perPage"
                data-action="change->table-controls#changePerPage">
            <option value="10">10</option>
            <option value="25">25</option>
            <option value="50">50</option>
            <option value="100">100</option>
        </select>
        <span class="text-muted small">records</span>
    </div>
</div>
```

Full card footer pattern:
```twig
<div class="card-footer d-flex justify-content-between align-items-center py-2">
    <span class="text-muted small" data-table-controls-target="info"></span>
    <div data-table-controls-target="pagination"></div>
</div>
```

### New / Edit Forms
Centered card (`col-lg-6 mx-auto`) with Cancel + Submit buttons, extending `layout.html.twig`.

### Badges
Use `.badge-soft-*` classes (not Bootstrap defaults): `success`, `danger`, `warning`, `info`, `secondary`, `purple`. All have `!important` on color to override Bootstrap's `color: #fff`.

### Action Buttons (table rows)
`.btn-icon` — 34×34px circular buttons. `.btn-icon-primary:hover` — purple tint. `.btn-icon-danger:hover` — red tint.

### Lucide Icons
Initialized via `createIcons({ icons })` on `DOMContentLoaded`, `turbo:load`, `turbo:frame-load`. Always include the icon name in the `icons` import in `app.js` when adding a new icon.

## ApexCharts Conventions

### Muted-by-default Hover (donut/pie charts)
All donut charts use a custom hover pattern — slices start at **70% opacity** by default and unmute to 100% on hover; non-hovered slices drop to **55%**. ApexCharts' built-in hover/active filters and expand-on-click are disabled.

This is implemented in `chart_controller.js` via `_attachDonutHover()`, called automatically for any chart with `data-chart-chart-key-value` containing `donut`-type data. The JS detects the chart type from `data-chart-type-value="donut"` and the key.

**Always include in donut chart options:**
```javascript
plotOptions: { pie: { expandOnClick: false } },
states: {
    hover: { filter: { type: 'none' } },
    active: { filter: { type: 'none' } },
},
```

### Muted-by-default Hover (stacked bar charts)
Stacked bar charts use `_attachRowHover()` — hovered row stays at full opacity, others drop to 60%. Same `states` config as above disables ApexCharts' default behavior.

**Never use ApexCharts' default hover behavior** on any chart type. Always disable with the `states` config above.

### Legend Position
Always `legend: { position: 'top' }` — never bottom.

### Equal-Height Chart Rows
Always add `h-100` to `.card` elements within a Bootstrap chart row so paired charts share the same height.

### Chart Types Available in `chart_controller.js`
- `line` — daily trend line, supports `eventDate` annotation (purple vertical line)
- `donut` — expects `[{label, count, color}]` or `[{behaviorName, count, color}]`
- `bar` — horizontal stacked bar; `byStudent` and `byTimeBlock` keys get row-hover
- Default bar — vertical, single series

### CTA Purple Color Palette (for chart colors)
```
#7a5af8  — primary purple
#a78bfa  — medium purple
#c4b5fd  — light purple
#6d28d9  — dark purple
#4c1d95  — deepest purple
```

### Dashboard Data Endpoint Pattern
The dashboard `data()` action returns JSON consumed by chart controllers via `chart:refresh` events (dispatched by `filter_controller`). Structure your response so each chart has its own key:
```php
return $this->json([
    'activityByType' => [...],   // donut data
    'byDay'          => [...],   // line chart data
    'byUser'         => [...],   // bar chart data
]);
```

## Stimulus Controllers

- **`chart_controller.js`** — ApexCharts wrapper. Values: `type`, `dataUrl`, `dataInline` (JSON string, skips fetch), `chartKey`, `height`, `eventDate`. Has async `capture()` method returning base64 PNG for PDF generation. Responds to `chart:refresh` custom events.
- **`filter_controller.js`** — For AJAX-based dashboards: `apply()` dispatches `chart:refresh` to all charts with current filter params. For server-rendered pages: `navigate()` calls `requestSubmit()` for Turbo GET navigation. Has `downloadTarget` — keep export link in sync with active filters.
- **`table_controls_controller.js`** — Client-side search + pagination for any table or card list. Targets: `tbody` (table rows), `cardItem` (card elements).
- **`view_toggle_controller.js`** — Card/table view toggle with localStorage persistence.

## Help Documentation System

- Markdown files in `docs/help/{route_name}.md` — one per page, named by Symfony route name
- Fallback: `docs/help/_default.md` shown for any route without a dedicated file
- Images: `public/help/images/`, referenced as `![alt](/help/images/filename.png)`
- Rendering: `league/commonmark` with `GithubFlavoredMarkdownConverter`
- Route: `GET /help/{page}` (`help_show`) — requires `ROLE_STAFF`
- Template: `templates/help/show.html.twig` — wrapped in `<turbo-frame id="help-frame">`
- Trigger: `?` button in navbar opens `#helpOffcanvas` via Bootstrap; inline script in `layout.html.twig` sets the Turbo Frame `src` from `data-help-page` on the current route
- To disable Turbo cache on a page (e.g. pages with inline chart data): add `{% block head %}<meta name="turbo-cache-control" content="no-cache">{% endblock %}` to the template

## CSS Load Order (critical)
In `assets/app.js`, Bootstrap CSS must be imported BEFORE `app.css`:
```js
import 'bootstrap/dist/css/bootstrap.min.css';
import './styles/app.css';  // must come AFTER Bootstrap or purple overrides get stomped
```

## Testing

- **PHPUnit 13** + **DAMADoctrineTestBundle** — each test wraps in a rolled-back transaction
- Uses the same DB as dev (no CREATE DATABASE privilege on remote server)
- Config: `phpunit.dist.xml` (note: `dist.xml` not `xml.dist`)
- Fixtures: four CTA developer admins (`dcramble`, `thand`, `staylor`, `jdhruv` — all `@mesd.k12.or.us`, ROLE_ADMIN, active), `supervisor@cta.org` (ROLE_SUPERVISOR, active), `staff@cta.org` (ROLE_STAFF, active), `pending@cta.org` (ROLE_STAFF, inactive)

### CSRF Testing Patterns

Extract CSRF tokens from rendered HTML:
```php
$crawler = $this->client->request('GET', '/some/form');
$token   = $crawler->filter('input[name="_token"]')->attr('value');
```

For Symfony named forms (e.g. `my_form`), the CSRF input is `my_form[_token]`:
```php
$token = $crawler->filter('input[name="my_form[_token]"]')->attr('value');
```

For toggle forms embedded in list pages, filter by form action:
```php
$token = $crawler->filter('form[action="' . $toggleUrl . '"] input[name="_token"]')->attr('value');
```

For POST-only endpoints with no rendered form, warm the session first:
```php
$this->client->request('GET', '/any-authenticated-route');
$session = $this->client->getRequest()->getSession();
$fakeRequest = \Symfony\Component\HttpFoundation\Request::create('http://localhost/');
$fakeRequest->setSession($session);
$requestStack = $this->client->getContainer()->get('request_stack');
$requestStack->push($fakeRequest);
$token = $this->client->getContainer()->get('security.csrf.token_manager')
    ->getToken('my-token-id')->getValue();
$requestStack->pop();
$session->save();
```

### PHP 8.5 Notes
- `fgetcsv()` requires explicit `$escape` param: `fgetcsv($handle, 0, ',', '"', '')`
- `ReflectionProperty::setAccessible()` is deprecated — omit entirely
- `StreamedResponse::getContent()` always returns `false` — use `$client->getInternalResponse()->getContent()` in tests for CSV content
- `fputcsv()` quotes fields containing spaces/commas — account for quoted headers like `"Time Block"` in assertions

## PHPStan

- Level 8 — configured in `phpstan.neon`
- Baseline: `phpstan-baseline.neon` (2 pre-approved suppressions in `GoogleAuthenticator`)
- Run: `composer test:stan`
- Regenerate baseline: `vendor/bin/phpstan analyse --memory-limit=512M --generate-baseline`

## Common Commands

```bash
symfony serve                                    # Start dev server
php bin/console doctrine:migrations:migrate      # Run migrations
php bin/console doctrine:migrations:diff         # Generate migration from entity changes
php bin/console doctrine:fixtures:load           # Load fixtures
composer test                                    # Run PHPUnit
composer test:stan                               # Run PHPStan
php bin/console cache:clear                      # Clear cache
php bin/console importmap:require <package>      # Add JS package
```

## Dev Login Bypass

A developer convenience route at `/dev-login` lets you log in as any fixture user without going through Google OAuth. Useful for quickly switching between roles and for headless testing tools.

**Two conditions must both be true — if either fails, the route returns 404:**
1. `APP_ENV=dev` — enforced by the kernel; always `prod` on real servers regardless of any flag
2. `APP_DEV_AUTH_ENABLED=true` — explicit opt-in via `.env.local`

**To enable locally:** add `APP_DEV_AUTH_ENABLED=true` to your `.env.local`

**How it works:** GET `/dev-login` renders a list of all users in the database. Clicking Login POSTs to the same route, which calls `Security::login()` to establish a session and redirects to `/dashboard`. No passwords, no Google redirect.

**Tests:** `DevLoginControllerTest` runs in `APP_ENV=test`, so both routes return 404 unconditionally — proving the kernel environment is the primary guard.

## Environment Variables

Required in `.env.local` for local dev:
- `DATABASE_URL` — PostgreSQL connection string (already set in `.env` for this project's DB)
- `GOOGLE_CLIENT_ID` — Google OAuth client ID
- `GOOGLE_CLIENT_SECRET` — Google OAuth client secret
- `APP_SECRET` — Symfony application secret
- `APP_DEV_AUTH_ENABLED=true` — enables `/dev-login` bypass (dev only; off by default)

## Login Page Customization

The login page (`templates/security/login.html.twig`) has two zones to customize per app:

### Left panel (sign-in content)
Three fields to update — search for the comments in the template:
```twig
{# Tagline — update this for your app #}
<p class="pp-login-tagline">Your application tagline here.</p>

{# Description — update this for your app #}
<p class="pp-login-desc">
    A Cascade Technology Alliance application.
    Sign in with your CTA Google account to get started.
</p>
```
Also update the logo name in `.pp-login-logo-name` and the `<title>` block.

### Right panel (decorative)
The right panel uses a CSS dot grid background + an oversized SVG decorative element. The SVG should echo the app's logo icon. The current default uses the Lucide `layers` icon (scaled 28×, centred at 340,450 within a 680×900 viewBox). Replace the `<g transform="translate(4, 114) scale(28)">` block and its paths with an SVG version of the new app's logo icon.

**Dot grid background** (keep as-is across apps — defined in `.pp-login-right` CSS):
```css
background-color: #ede9fe;
background-image: radial-gradient(circle, #c4b5fd 1px, transparent 1px);
background-size: 28px 28px;
```

**SVG decorative element** — all lines use `stroke="#7a5af8"` at low opacity so the dot grid shows through. Use `opacity="0.32"` on all strokes for a consistent subtle look.

---

### App Examples

#### CTA App (starter default / demo)
- **App Name**: CTA App
- **Tagline**: Your application tagline here.
- **Description**: A Cascade Technology Alliance application. Sign in with your CTA Google account to get started.
- **Logo icon**: `lucide:layers`
- **Right panel**: Oversized `layers` icon (3 stacked rounded-rect outlines), all strokes at `opacity="0.32"`, dot grid background.

#### Balcony CRM
- **App Name**: Balcony CRM
- **Tagline**: See the full picture.
- **Description**: Understand how teams deliver services and support across schools and districts.
- **Logo icon**: `lucide:mountain-snow` (or similar mountain/landscape icon)
- **Right panel**: Connected-node network graph (dots + lines), suggesting relationships and visibility across an organization. Strokes at `opacity="0.32"`.

#### PulsePoint
- **App Name**: PulsePoint
- **Tagline**: Every behavior tells a story.
- **Description**: Behavior data tracking for K–12 education. Record incidents, spot patterns, and make better decisions for the students who need it most.
- **Logo icon**: `lucide:activity` (pulse/heartbeat waveform)
- **Right panel**: Scatter plot of data points with a trailing trend line, evoking behavior data over time. Dots and line at `opacity="0.32"`.

---

### Choosing a Right-Panel Design
Match the decorative SVG to the app's domain:
| Domain | Suggested motif |
|--------|----------------|
| Data / analytics | Scatter plot, line chart, bar chart shapes |
| Relationships / CRM | Connected-node graph |
| Layered systems / infrastructure | Stacked layers (default) |
| Communication / messaging | Speech bubble outlines, wave forms |
| Education / scheduling | Grid / calendar grid outlines |

Keep all strokes at `stroke="#7a5af8"` and `opacity="0.32"` for visual consistency across apps. The dot grid background CSS stays the same for every app.

## Starting a New App From This Starter

1. Clone this repo into the new project directory
2. Update `APP_NAME` in `.env`
3. Update the sidebar brand name in `templates/_partials/_sidebar.html.twig`
4. Create a new database on `appdev-db2.mesd.k12.or.us` and update `DATABASE_URL`
5. Update `CLAUDE.md` — replace this "Starting a New App" section with the app's purpose and entity model
6. Run `php bin/console doctrine:migrations:migrate && php bin/console doctrine:fixtures:load`
7. Design the domain entity model — then generate migrations with `doctrine:migrations:diff`

## Key Design Decisions

- **UUID v7** for all entity IDs — prevents URL enumeration, sortable by time
- **`app_user` table name** — `USER` is reserved in PostgreSQL
- **`key_name` column on AppSetting** — `KEY` is reserved in PostgreSQL
- **`googleId` nullable** — admins pre-create users by email; Google links on first login
- **`isActive = false` default** — new users must be activated by admin before login
- **Bootstrap `.badge { color: #fff }`** overrides require `!important` on `badge-soft-*` color values
- **`btn-outline-secondary`** globally overridden in `app.css` to use light purple (`#c4b5fd` border, `#7a5af8` text) instead of Bootstrap grey
- **No localStorage for server-persisted settings** — always read user preferences from the server on page load; localStorage causes stale-state bugs across sessions
- **Turbo cache** — pages with inline chart data should opt out with `<meta name="turbo-cache-control" content="no-cache">` to prevent double chart renders during Turbo preview
- **`hosted_domain` removed** from `knpu_oauth2_client.yaml` — was causing domain validation errors
