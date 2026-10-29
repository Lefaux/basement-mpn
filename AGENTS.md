# AGENTS.md

## Product decisions

- The target layout is `Shared Drive / Artist / Ableton Project`; other locations are inconsistent.
- For an invalid legacy location, infer display artist/title from `Artist - Track Project` when present, but retain `Invalid Location` and keep it out of the main board.
- Missing preview/full renders, LANDR masters, and `_MSTR` finals are normal workflow states, not inconsistencies. Inconsistencies are limited to invalid folder placement/naming and mismatched `.als` versus regular WAV/MP3 render basenames.
- Within an artist folder, a project named after another artist is valid when its name explicitly credits the parent artist as an `Edit` or `Remix`, with or without parentheses (for example, `David Bowie - Lets Dance - DJ Lefaux Edit Project`).
- Main-board file filters show the number of currently displayed (artist-filtered) tracks matching each filter, are combined with AND logic, and persist as `file_filter[]` GET parameters.
- WAV/MP3 files with a case-insensitive `_PRV` basename suffix are previews regardless of size; the suffix is ignored when matching a preview to an `.als` basename. Existing size-based preview rules remain in effect.
- The "Missing Previews" navigation view lists clean tracks with no previews, renders, LANDR masters, or `_MSTR` finals, grouped into artist columns and with the main artist filter.
- The "Missing LANDR" navigation view lists clean tracks that have at least one render and neither a LANDR file nor an `_MSTR` final; previews are optional. It is grouped into artist columns and has the main artist filter.
- The "Missing Master" navigation view lists clean tracks that have at least one LANDR file and no `_MSTR` final; previews and renders are optional. It is grouped into artist columns and has the main artist filter.
- The "Ready to release" navigation view lists clean tracks with at least one `_MSTR` final; previews, renders, and LANDR files are optional. It is grouped into artist columns and has the main artist filter.
- The primary navigation uses the Basement MPN wide logo and an explicit Overview menu item to link to the Overview. All non-Overview links are bold, use `#b6d433` when active, show their current track count, and are hidden when that count is zero.
- Deferred audio playback: replace the Google Drive preview iframe with an authenticated Symfony streaming endpoint and one global native `<audio>` player. The endpoint must only stream indexed WAV/MP3 Drive file IDs, download through the service account with `alt=media`, and forward HTTP range semantics (`Range`, `206`, `Content-Range`, `Accept-Ranges`, `Content-Length`) for seeking without buffering full files in PHP. This avoids Google Drive iframe/CSP failures; account for hosting bandwidth when implementing it.

This is a Symfony project. Check `composer.json` for the exact Symfony/PHP version
in use, and read `symfony.lock` to see which recipes ran. Don't assume Doctrine,
Twig, API Platform, Messenger, or Lock are installed unless one of those says so.

## Ask before generating

If the task doesn't specify, ask rather than guess:

- Persistence: Doctrine ORM, Doctrine ODM, or none?
- Interface: server-rendered (Twig), API (Serializer, maybe API Platform), or both?
- Auth: SecurityBundle, and which authenticator?

If you can't ask (no interactive channel), state the assumption you're making and
pick the smallest option (e.g. no persistence layer) rather than scaffolding a
full stack nobody asked for.

## Adding features: Flex, not hand-wiring

Install new capabilities with `composer require <package>` (e.g. `symfony/lock`,
`symfony/messenger`, `orm-pack`) and let the Flex recipe register the bundle and
generate its config. Don't hand-edit `config/bundles.php` or hand-write a bundle's
base config; that's what the recipe is for. Don't skip a good-fit component just
because it isn't installed yet; installing it is one command.

## Conventions

Follow https://symfony.com/doc/current/best_practices.html to write idiomatic
Symfony:

- Use PHP attributes for framework metadata, and not only on controllers:
  `#[Route]`, `#[MapRequestPayload]`, `#[IsGranted]` on actions, `#[Assert\...]`
  on properties, `#[AsCommand]`, `#[AsEventListener]`, `#[AsMessageHandler]`, and
  `#[AsAlias]` / `#[AsTaggedItem]` / `#[Autoconfigure]` on services. No YAML or
  XML routing.
- Rely on autowiring and autoconfiguration. Type-hint constructor arguments and
  let the container resolve them. Where a type-hint can't express it, stay in the
  class with `#[Autowire]` (parameters, env vars, expressions) or `#[Target]` (one
  of several implementations of an interface). A YAML service definition is the
  last resort, not the first.
- Controllers extend `AbstractController`, stay thin, and delegate to services.
- Use the framework for what it already does: Form for server-rendered forms,
  Validator for validation, Serializer for JSON, Messenger for async work,
  Security (voters, authenticators) for access control, Twig `path()`/`url()`
  instead of hardcoded URLs.
- Before hand-writing infrastructure (locks, queues, caches, HTTP clients,
  mailers, schedulers) or reaching for a third-party library, check whether a
  Symfony component covers it. It usually does.

Three specifics worth spelling out, because they are easy to get wrong:

- Bind request data with `#[MapRequestPayload]` / `#[MapQueryString]` on action
  arguments, which wires up Serializer and Validator for you, instead of calling
  `json_decode()` or `SerializerInterface` by hand. If neither package is
  installed yet, `composer require` them rather than falling back to manual
  parsing.
- Use constructor property promotion, and `readonly` for DTOs and value objects.
  Don't mark a service `readonly` if it might become `lazy: true`: a lazy proxy
  can't extend a `readonly` class.
- Use `symfony/lock` (`LockFactory`) for mutual exclusion. A hand-built flag or
  lock file looks fine in review and is usually wrong under concurrency.

## Everyday workflow

- Run the app with `symfony serve -d`, and commands with `symfony console ...`
  (or `bin/console` when the Symfony CLI isn't available).
- When something fails, read `var/log/dev.log` and the web profiler
  (`/_profiler`) before changing code.
- If `maker-bundle` is installed, prefer `bin/console make:*` with every argument
  passed up front and `--no-interaction` where supported: makers prompt on a
  terminal by default, which hangs a non-interactive shell. If a maker still
  needs interactive input, hand-write the code instead.
- If Doctrine ORM is installed, schema changes go through migrations
  (`bin/console make:migration`, then `doctrine:migrations:migrate`), never
  `doctrine:schema:update` or hand-written SQL.
- `.env` is committed and holds defaults only. Real secrets belong in `.env.local`
  (git-ignored) or the secrets vault (`bin/console secrets:set`), read via
  `%env(...)%`.

## Testing

Install `symfony/test-pack` if it isn't already. Functional/HTTP tests extend
`WebTestCase`; service-level tests extend `KernelTestCase`. Run
`php bin/phpunit` (falls back to `vendor/bin/phpunit`). A feature isn't done
until it has a test that exercises it the way a caller would, an HTTP request for
a controller or a service call for a service, not just "it didn't throw."

## Code style

Symfony's coding standard, the `@Symfony` php-cs-fixer ruleset (a PSR-12-derived
superset). Run `vendor/bin/php-cs-fixer fix` if `friendsofphp/php-cs-fixer` is
installed; it isn't part of the skeleton by default.

## Discover, don't guess

Framework APIs change between versions and your training data may be stale. Look
things up in the project instead of relying on memory:

- `bin/console about`: versions, environment, paths.
- `bin/console debug:router`, `debug:container`, `debug:autowiring <name>`,
  `debug:config <bundle>`, `config:dump-reference <bundle>`: what exists and how
  it is configured.
- `bin/console lint:container`, plus `lint:twig templates/` and
  `lint:yaml config/` where those packages are installed: validate before running.
- Read the installed source and docblocks under `vendor/`.
- Docs: https://symfony.com/doc/current/ (switch to the version matching
  `composer.json` if it differs).
