# Mublo Framework

**A light core. Free extensions.**

*[한국어 README](README.md)*

Mublo is an MIT-licensed PHP application platform for building sites that need an
admin panel. One installation runs several different services — a corporate site,
a community, a shop — each with its own domain, theme, and set of enabled features.

The name comes from **Mu**lti + **Blo**ck.

---

## What is actually different here

Plenty of PHP frameworks let you add modules. Mublo's premise is narrower and more
specific: **an extension owns its data, its behaviour, and the page-builder blocks
that render it — all three, registered from one provider.**

A `Shop` package ships its own migrations, its own MVC, its own events and
contracts, *and* the "product list", "review", "exhibition" blocks that a
non-technical operator drags onto a page. Install it and the page builder simply
knows about products. Remove it and those blocks disappear with it. No core file
gets edited in either direction.

That last part is the reason the rest of the architecture exists.

```php
// From a package provider — one place, three kinds of registration
// register() — services into the container
public function register(DependencyContainer $container): void
{
    $container->singleton(BoardArticleRepository::class, fn (DependencyContainer $c) =>
        new BoardArticleRepository($c->get(Database::class))
    );
}

// boot() — event subscribers and page-builder blocks
public function boot(DependencyContainer $container, Context $context): void
{
    $container->get(EventDispatcher::class)
        ->addSubscriber(new BoardSearchSubscriber($container->get(BoardArticleRepository::class)));

    BlockRegistry::registerContentType(
        type: 'board',
        kind: BlockContentKind::PACKAGE->value,
        title: 'Latest posts',
        rendererClass: BoardRenderer::class,
        options: [
            'icon'         => 'bi-card-text',
            'capabilities' => BlockRegistry::capabilities(
                skin: true, items: true, count: true, style: true, aos: true, customConfig: false,
            ),
            'hasItems'     => true,
            'maxItems'     => 1,
            'skinBasePath' => MUBLO_PACKAGE_PATH . '/Board/views/Block/',
        ]
    );
}
```

*(Condensed from `packages/Board/BoardProvider.php`.)*

Registration is transactional. If a provider throws halfway through, core rolls
back the container bindings, listeners, routes and registry entries it had already
made. A broken extension does not leave a half-wired application behind.

---

## The three layers

| Layer | What it is | Examples |
|---|---|---|
| **Core** | Request flow and common rules only | routing, DI, auth, sessions, rendering, events, admin shell |
| **Plugin** | A small, horizontal feature | banners, popups, FAQ, social login, points, visitor stats |
| **Package** | A self-contained app with its own MVC and schema | Board, Shop |

The boundaries hold in practice: across `src/`, `packages/` and `plugins/` there
are currently **zero** imports from core into an extension, and zero imports
between extensions. They talk through events and contracts instead.

What CI enforces directly is the other axis — whether an extension reaches for
core internals:

```bash
composer check     # audit + DI rules + extension API + strict_types + PHPStan ×2 + tests
```

`tools/check-extension-api.php` fails the build if a bundled extension reaches for
anything outside the documented stable API. The current baseline is zero
violations — the file that used to freeze historical debt has been deleted.

When extensions need each other, they go through **Events** (something happened —
several listeners may react) or **Contracts** (I need this done — exactly one
implementation answers). Payment gateways, notification senders and FAQ lookups
are contracts. Post-signup point grants and admin-menu building are events.

---

## The block system

Operators build pages from rows and columns. Each column holds a *content type*
with a skin and settings.

What makes it more than an HTML-fragment editor is **block items**: the block
decides *where and how* something renders, while a package or plugin supplies
*what* is available to render. The same "product list" block can point at
different products, orderings and skins per domain — chosen in the admin UI, with
no code change.

For a developer, adding a new business feature to the page builder means supplying
its items. You do not touch the editor.

Raw HTML, CSS and JS blocks are available to editors on separate channels rather
than as one soup of markup. The HTML channel is sanitized — `<script>` tags, `on*`
handlers and `javascript:` URLs are stripped from it in every profile — and the JS
channel is the deliberate place for scripts. Editing rights are a trust level
here, the same way they are in most CMSes.

---

## Multi-domain

Not just domain aliases. Each domain gets its own theme, settings, members,
permissions, and its own set of enabled packages and plugins.

```text
company.example.com    →  pages, notices, banners, FAQ
community.example.com  →  boards, comments, attachments, points
shop.example.com       →  products, cart, orders, payments, reviews
```

Core resolves the domain first and then loads only what that domain enabled.

Worth saying plainly: cross-domain data isolation is **a convention, not a
sandbox**. Queries are domain-scoped by the repository layer, and that is
deliberate — "show boards from every site" and "share a product across domains"
are features some installs need. If you require hard tenant isolation enforced by
the framework, this is not that.

---

## Not a framework with nothing in it

Two real applications ship in the repository, and they are what keeps the
extension API honest:

- **Board** — boards, groups, categories, tiered permissions, nested comments,
  attachments, reactions, point integration
- **Shop** — products, options, cart, orders, payments, coupons, shipping,
  reviews, inquiries, block integration

Plus 15 bundled plugins (banners, popups, widgets, FAQ, Q&A, member points,
visitor stats, social login, surveys, messaging senders, and more).

Reservation and rental packages run in production too, but they are not part of
this release yet.

Shop's payment path is a reasonable sample of the care level: the charged amount
is recomputed server-side from the persisted order row, verification is
fail-closed if the gateway does not report an approved amount, the gateway key and
order number are cross-checked, and the state transition is a compare-and-swap so
a client callback and a PG webhook arriving together cannot both win.

---

## Is this for you

If you want a headless API framework, or a general-purpose Laravel alternative,
this isn't it.

If you want an admin-heavy PHP site — members, permissions, file handling,
several services on one codebase — where non-technical operators assemble pages
themselves and developers extend what they can assemble, it is.

Two things to know before you spend time on it:

- **Most documentation is in Korean.** The `docs/` tree is thorough — an
  architecture book, developer guides, an API reference — but it has not been
  translated. Code identifiers and commit history are English; prose is not.
- **No Packagist distribution yet.** You clone or download a release; there is no
  `composer create-project`.

---

## Requirements

- PHP 8.2+
- MySQL 5.7.8+ or MariaDB 10.3+ (for new deployments: MySQL 8.4 LTS / MariaDB 10.11 LTS)
- Composer
- Extensions: `pdo`, `pdo_mysql`, `mysqli`, `mbstring`, `openssl`, `json`, `curl`, `fileinfo`, `gd`
- Recommended: `zip`, `xml`, `intl`

## Quick start

```bash
composer install
```

Point your web root at `public/`, then open `https://your-domain.com/install` and
follow the installer: database → domain → security → admin account.

Admin lives at `/admin` afterwards.

Full guide, including an nginx server block and post-install hardening:
[docs/user-guide/installation.md](docs/user-guide/installation.md)

---

## Documentation

| Audience | Link |
|---|---|
| Start here | [Docs home](docs/README.md) |
| Architecture | [Architecture Book](docs/architecture/README.md) |
| Philosophy | [Design philosophy](docs/philosophy.md) |
| Extension model | [Plugin / Package / Event / Contract / Block](docs/extension-model.md) |
| Operators | [User guide](docs/user-guide/README.md) |
| Developers | [Developer guide](docs/dev-guide/README.md) |
| Extension authors | [Compatibility policy](docs/compatibility-policy.md) |
| Reference | [Schema, events, config, hook points](docs/reference/README.md) |

## Layout

```text
mublo/
├── config/      # generated by the installer
├── database/    # core migrations
├── docs/
├── packages/    # Board, Shop
├── plugins/     # 15 bundled plugins
├── public/      # web root — index.php, install/, storage/
├── src/         # core
├── storage/     # cache, logs, sessions
├── tests/
└── views/
```

## Quality

```bash
composer test     # PHPUnit
composer check    # everything CI runs
```

`composer check` runs a security audit, the DI-boundary checker, the extension-API
checker, a `strict_types` sweep, a database-compatibility check, PHPStan at
level 0 across the tree and level 3 over core, the bundled packages and plugins,
and the test suite.

## Versioning

Core is at `1.0.0`. What counts as stable API and how it may change is defined in
[the compatibility policy](docs/compatibility-policy.md). Build against the
documented events, contracts and provider conventions — not internals.

The commit history here starts at the public release: this repository is a
publish of a codebase that had already been running in production, and the
history was squashed for it. The version number reflects the code's maturity,
not the age of this repository.

## Contributing

Welcome. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

Please do not open public issues for vulnerabilities. Follow
[SECURITY.md](SECURITY.md).

## License

MIT. Third-party components and their licenses — including four LGPL
dependencies — are listed in [NOTICE](NOTICE).
