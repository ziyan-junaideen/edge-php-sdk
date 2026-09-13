# Repository Guidelines

## Project Structure & Module Organization

This repository is a lightweight PHP client for the Edge v2 JSON:API. Production code lives in `src/` under the `Edge\` namespace; key classes include `Client`, `Auth`, `Response`, `Exception`, and `Helpers`. Tests live in `tests/` under `Edge\Tests` and generally mirror the source class they cover (for example, `src/Client.php` is exercised by `tests/ClientTest.php`). Composer metadata and scripts are in `composer.json`, PHPUnit configuration is in `phpunit.xml.dist`, and CI workflows live in `.github/workflows/`.

## Edge API

### Sources of truth, in order

1. `/Volumes/Dev/Work/Edge/edge/ept` — the Phoenix/Elixir backend. Authoritative.
   - `openapi.json` — full v2 spec
   - `priv/openapi/description.md` — the narrative docs, including the canonical
     test-card table
   - `lib/core_http/views/*.ex` — the real field and relationship definitions
   - `lib/core_http/controllers/*.ex` — request handling, including `confirm`
   - `assets/js/edge.js` — the browser SDK source
2. `docs.tryedge.io` — published docs.
3. `/Volumes/Dev/Work/Edge/edge-elixir-sdk` - the Edge Elixir SDK I intend to improve this into.

Never infer an API field from this plugin or from the SDK. Where they disagree
with the backend, the backend wins.

### Hosts

|                     | Production                                    | Local dev                                  |
| ------------------- | --------------------------------------------- | ------------------------------------------ |
| API                 | `https://api.tryedge.io/v2/`                  | `https://api.tryedge.test:4001/v2/`        |
| Hosted payment form | `https://dashboard.tryedge.io`                | `https://dashboard.tryedge.test:4001`      |
| Browser SDK         | `https://assets.tryedge.io/assets/js/edge.js` | served from the dashboard host, unminified |

The SDK URL is deliberately the undigested path. Edge's developer page hands out
a content-hashed `edge-<digest>.js?vsn=d`, which changes on every deploy; a
previous version of this plugin hard-coded one and broke.

The dev publishable token is in `AGENTS.local.md`. Secret keys are never recorded
in this repo — take one from the Edge dashboard's Developers tab and put it in the
gateway settings

## Build, Test, and Development Commands

- `mise install` installs the pinned development PHP version from `mise.toml`.
- `composer install` installs runtime and development dependencies and generates autoload files.
- `composer test` runs the complete PHPUnit suite through the Composer script.
- `vendor/bin/phpunit --filter ClientTest` runs a focused test class or matching test name.
- `composer validate --strict` checks package metadata as CI does.

This is a library, so there is no local application server or separate build artifact.

## Coding Style & Naming Conventions

Use four-space indentation, one class per file, and PSR-4 namespaces (`Edge\` and `Edge\Tests\`). Match the existing PHP style: opening braces on the next line, camelCase method and variable names, PascalCase class names, and descriptive constants such as `DEFAULT_BASE_URI`. Keep compatibility with the PHP constraint in `composer.json` (`^7.3 || ^8.0`); do not introduce syntax available only in the locally pinned PHP 8.3. Add concise PHPDoc where behavior, accepted types, or security implications are not obvious.

## Testing Guidelines

Tests use PHPUnit 9.6. Name test files `*Test.php` and methods `test...` with behavior-focused names. Use Guzzle's `MockHandler` and history middleware for HTTP behavior; tests must not call the live Edge API. Assert request URLs, verbs, headers, and JSON bodies when changing client behavior. No numeric coverage threshold is configured, but new behavior and regressions should receive focused tests.

## Commit & Pull Request Guidelines

Recent commits use short, descriptive, sentence-style subjects such as `Porting the SDK to the Edge v2 API`. Keep each commit scoped to one logical change. Pull requests should explain the motivation and API impact, link relevant issues, list verification commands, and update `README.md` for public behavior changes. Ensure Composer validation and PHPUnit pass before requesting review.

## Security & Configuration

Never commit live or sandbox secret keys. Use clearly fake `ept_sandbox_s_test` values in tests. Preserve same-origin URL checks and TLS verification defaults when modifying request handling; disabling TLS is only appropriate for local self-signed environments.
