# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

The shared guidance above covers the API sources of truth, commands, style, testing and security. This file adds only the Claude-specific parts.

## Commands

- `composer test` runs the full suite. CI (`.github/workflows/php-composer.yml`) runs `composer validate --strict`, then `composer install`, then `composer run-script test`.
- To run one test: `vendor/bin/phpunit --filter testLeadingSlashDoesNotDropTheVersionPrefix` (a method name) or `--filter ClientTest` (a class).
- `phpunit.xml.dist` sets `failOnWarning` and `failOnRisky`, so a test with no assertions fails the run.
- `composer.lock` is gitignored, so dependencies resolve fresh on every install.

## Architecture

- Everything is static, and state lives in static properties. `Auth::$apiKey` holds the key. `Client` holds the memoized Guzzle client, base URI, User-Agent suffix and TLS verify flag. Tests must call `Client::reset()` in both `setUp` and `tearDown`, and inject HTTP through `Client::setHttpClient()`. `Auth` has no reset, so set a fake key in `setUp`.
- Request pipeline: the public verbs (`get`, `create`, `update`/`patch`, `confirm`) all go through `Client::request()`. It adds the headers and reads the API key at call time. It resolves the URL, then decodes the response through `Response::toObject()`, so callers get `stdClass`, not arrays.
  - Every error is normalized to `Edge\Exception`. A Guzzle `RequestException` goes through `Exception::fromRequestException()`, and a `TransferException` (connection failures, redirect loops) becomes an exception with status 0.
- The memoized Guzzle client is built with no configuration on purpose. Per-call values such as the key, headers and `verify` are passed per request, so nothing gets frozen into it.
- URL resolution is done by hand in `Client::url()`, not with Guzzle's `base_uri`. RFC 3986 resolution drops `/v2` when an endpoint has a leading slash.
  - Absolute URLs pass only when their scheme, host and port match the base URI (`assertSameOrigin()`). Every request carries the secret key, so relaxing this check would leak it.
- `normalizeBaseUri()` adds `/v2` to a bare host. `EDGE_API_BASE_URI` is read lazily on the first `getBaseUri()` call.
- JSON:API quirks already encoded in the code:
  - The API has no PUT or DELETE.
  - `confirm()` sends an empty `attributes` as `new \stdClass()`, because an empty PHP array would encode to `[]`.
  - `get()` omits Guzzle's `query` option when the query is empty, so pagination links keep their own query string.
- `Exception::describe()` picks the message in this order: the first error's `detail`, then `title`, then `code`. Next is a short plain-text body, which covers gateway auth failures. Last is a generic `Edge API error (HTTP <status>)` message.

## Claude-specific notes

- The backend sources of truth listed in AGENTS.md (`/Volumes/Dev/Work/Edge/edge/ept`, `/Volumes/Dev/Work/Edge/edge-elixir-sdk`) are outside this working directory. Add them with `claude --add-dir <path>` or `/add-dir` before verifying API fields. Never guess a field when the backend can't be read.
- For API field or relationship questions, use an Explore subagent to search `lib/core_http/views/*.ex` and `openapi.json` in the backend. That keeps the large spec out of the main context.
- AGENTS.md refers to `AGENTS.local.md` for the dev publishable token. It is local-only and may be absent. Don't create it, and don't copy tokens from it into tracked files.
- The locally pinned PHP (8.3, via mise) is newer than the supported floor (`^7.3`). Passing tests locally doesn't prove 7.3 compatibility. Avoid typed properties, arrow functions, `match`, the nullsafe operator, named arguments, union types and constructor promotion.
- Commit subjects follow the existing short sentence style, e.g. "Porting the SDK to the Edge v2 API".
