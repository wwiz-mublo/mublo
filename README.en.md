# Mublo Framework

**English** | [한국어](README.md)

[![CI](https://github.com/wwiz-mublo/mublo/actions/workflows/ci.yml/badge.svg)](https://github.com/wwiz-mublo/mublo/actions/workflows/ci.yml)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

> Light Core, Free Extension.

Mublo is an MIT-licensed PHP 8.2+ application platform for building operational websites, communities, and commerce services from one extensible foundation.

Its central idea is that application features should own their behavior. The Shop Package understands products, the Board Package understands posts, and the Banner Plugin understands banner groups. The Core and the page builder provide shared infrastructure without absorbing that business logic.

- **Live overview and demo:** https://about.mublo.kr
- **Community demo:** https://talk.mublo.kr
- **Shop demo:** https://shop.mublo.kr
- **Latest release:** https://github.com/wwiz-mublo/mublo/releases/latest

> The demo sites and most detailed documentation currently use Korean. This English README and the English installation guide provide the initial entry points for international users.

## Why Mublo?

A general-purpose framework gives developers control, but every project still needs an administration foundation, content composition, extension boundaries, and operational features. A traditional CMS provides many of those pieces, but application-specific behavior can become coupled to the CMS or page builder.

Mublo takes a middle path:

- the **Core** owns shared runtime rules;
- **Packages** own substantial application domains;
- **Plugins** add focused capabilities;
- **Blocks** let those features participate in page composition;
- **Themes and skins** own presentation;
- a **Domain Context** determines which features and presentation apply to each site.

## Application-owned blocks

Mublo's block system is more than an HTML fragment editor. A Package or Plugin can register a block type together with its server-side renderer, editor configuration, selectable application data, skins, assets, and cache behavior.

Examples included in the repository:

- **Shop** provides manually selected products, automatically selected products, and product review blocks.
- **Board** provides latest post, latest comment, and board group blocks.
- **Banner** provides banner blocks backed by the plugin's own data.
- **FAQ** provides FAQ blocks and category selection.

The page builder remains domain-agnostic. Operators decide what to display and where to place it; the owning Package or Plugin decides what the data means and how it is retrieved.

## Architecture

```text
                         Core
        request, routing, DI, auth, rendering, admin
                           |
                    Domain Context
             settings + theme + enabled features
                           |
          +----------------+----------------+
          |                |                |
       Events          Contracts       Block Registry
          |                |                |
       Plugins          Packages        Page Builder
    Banner, FAQ,       Board, Shop      Row, Column,
    Popup, etc.                         Content, Skin
```

- **Providers** register services, routes, event subscribers, contracts, and block types.
- **Events** communicate state changes that multiple extensions may react to.
- **Contracts** define capabilities without coupling the Core to a concrete Package or Plugin.
- **Block Registry** lets application features expose reusable page content while retaining ownership of their data and rendering.

## Included applications

Mublo includes working application Packages rather than only empty extension examples.

### Board

- boards, groups, and categories
- layered permissions
- comments, attachments, and reactions
- member point integration
- block integration

### Shop

- products, categories, and options
- carts, orders, and payments
- coupons, shipping, reviews, and inquiries
- block integration

Bundled Plugins include Banner, FAQ, Popup, Widget, MemberPoint, VisitorStats, SnsLogin, Survey, and others.

## One foundation, different services

Each domain can enable a different combination of Packages, Plugins, Themes, and Blocks.

```text
company.example.com    -> pages, banners, FAQs
community.example.com  -> boards, comments, member points
shop.example.com       -> products, orders, payments, reviews
```

Multi-site operation is not the primary abstraction. It is a consequence of keeping application responsibilities separate and resolving the active domain before loading its extensions.

## Requirements

- PHP 8.2 or later
- MySQL 5.7.8 or later, or MariaDB 10.3 or later
- Composer when installing from source
- Apache or Nginx with `public/` configured as the document root
- required PHP extensions: `pdo`, `pdo_mysql`, `mysqli`, `mbstring`, `openssl`, `json`, `curl`, `fileinfo`, `gd`
- recommended PHP extensions: `zip`, `xml`, `intl`

For new deployments, MySQL 8.4 LTS or MariaDB 10.11 LTS is recommended.

## Quick start

Install from source:

```bash
git clone https://github.com/wwiz-mublo/mublo.git
cd mublo
composer install --no-dev
```

Configure your web server so that `public/` is the document root, make `config/`, `storage/`, and `public/storage/` writable by PHP, and open:

```text
https://your-domain.com/install
```

The web installer checks the environment and guides you through the database, domain, security, and administrator account setup. After installation, the administration area is available at `/admin`.

See the [English installation guide](docs/user-guide/installation.en.md) for the complete minimum setup and post-install security steps.

## Development and quality gates

```bash
composer test
composer analyse
composer check
```

CI currently tests PHP 8.2, 8.3, and 8.4, runs integration tests with MariaDB, audits production dependencies, checks extension API boundaries, and verifies fresh-install and upgrade migrations against supported MySQL and MariaDB versions.

## Documentation

- [English installation guide](docs/user-guide/installation.en.md)
- [English contribution guide](CONTRIBUTING.en.md)
- [English security policy](SECURITY.en.md)
- [Korean documentation home](docs/README.md)
- [Architecture book (Korean)](docs/architecture/README.md)
- [Block system developer guide (Korean)](docs/dev-guide/block-system.md)
- [Compatibility policy (Korean)](docs/compatibility-policy.md)

English documentation is being introduced incrementally. Corrections and translation contributions are welcome.

## Contributing

Bug reports, documentation improvements, architectural feedback, and code contributions are welcome. See [CONTRIBUTING.en.md](CONTRIBUTING.en.md).

Please report security vulnerabilities privately by following [SECURITY.en.md](SECURITY.en.md).

## License

Mublo is released under the [MIT License](LICENSE).
