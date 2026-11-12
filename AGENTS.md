# AGENTS.md

Guidance for AI agents working on the `bb` codebase. For agents that *use* the
CLI, see [`skills/bb/SKILL.md`](skills/bb/SKILL.md).

## What this is

`bb` is a `gh`-style CLI for Bitbucket Cloud, built on Laravel Zero (console)
and Saloon (HTTP). Entry point: `./bb`. Commands are auto-discovered from
`app/Commands`.

## Commands

```bash
composer test      # vendor/bin/pest
composer lint      # vendor/bin/pint --test
composer format    # vendor/bin/pint
./bb list          # every command; ./bb list --format=json for machine output
```

Run `composer test` before finishing any change. Laravel PAO (dev dependency)
detects agent sessions and turns Pest output into one JSON line
(`{"result":"passed","tests":80,...}`, failures with file and line), so there
is no need to grep or tail the test output.

## Layout

- `app/Commands/BitbucketCommand.php`: abstract base for every command. Owns
  output (`table`, `link`, `outputJson`, `wantsJson`), prompts (`confirm`,
  `ask`, `canPrompt`), errors (`send`, `reportApiError`, `reportError`,
  `stderr`), pagination (`collectAll`), repo resolution (`resolveRepo`,
  `resolvePrId`) and git helpers. Reuse these; do not reimplement them.
- `app/Commands/<Area>/*Command.php`: one class per command, signature
  `area:verb`.
- `app/Commands/Concerns/`: shared pipeline and deployment logic.
- `app/Http/Integrations/Bitbucket/Requests/<Area>/`: one Saloon request per
  endpoint. `ApiRequest` (no body) vs `ApiRequestWithBody`; `NextPageRequest`
  follows `next` links.
- `app/Support/CredentialStore.php`: config file (`~/.config/bb/config.json`,
  `BB_HOME` overrides) plus `BB_TOKEN` / `BB_WORKSPACE` env overrides that are
  never written to disk.
- `tests/Feature`: command tests with Saloon `MockClient::global`, run through
  `runCommand($cmd, $params, decorated: true)` from `tests/Pest.php`.

## Rules for commands (the agent contract)

These keep `bb` safe and parseable for scripts and agents. New commands must
follow them.

1. **Machine output.** Anything that returns data, including write commands
   (create, edit, merge, comment), takes `{--json}` and `{--jq=}` and does
   `if ($this->wantsJson()) return $this->outputJson($data);` before printing
   human text. `--jq` alone implies `--json`.
2. **stdout is data, stderr is diagnostics.** Use `$this->error()` /
   `$this->warn()` (routed to stderr) or `$this->stderr()` for notes. Never
   print progress or hints to stdout in JSON mode.
3. **No silent defaults.** Destructive or irreversible actions take
   `{--y|yes}` and guard with `if (! $this->option('yes') && ! $this->confirm(...))`.
   The base `confirm()` / `ask()` throw when nobody can answer (piped stdin or
   `-n`), so an agent gets a clear error instead of an unintended action.
4. **Exit codes.** `self::SUCCESS` (0) or `self::FAILURE` (1). Return FAILURE
   whenever the requested action did not happen.
5. **Agent sessions** (detected in `AppServiceProvider::configureForAgents`
   via `laravel/agent-detector`, `BB_AGENT` overrides) run non-interactive and
   undecorated even in a pseudo-terminal, so rules 2 and 3 apply to them too.
   The hook is skipped in unit tests.
6. **Tables** go through `$this->table()`: boxed in a terminal, tab-separated
   with no ANSI when piped.
7. Add a feature test for new behaviour (see `tests/Feature/AgentModeTest.php`
   for JSON, non-interactive and piped-output patterns).

## Bitbucket API quirks

- Spec: https://api.bitbucket.org/swagger.json
- A GET with a JSON body gets an HTML 403 from the CDN: never attach a body to
  GET.
- UUIDs in paths keep their braces percent-encoded (`%7B...%7D`).
- `/pipelines/{ref}` also accepts a build number; `/pipelines` ignores `q`.
- `/deployments` sorts only by a few fields (`sort=-state.started_on` works,
  `sort=-number` errors).
- Step logs support HTTP Range requests (used by `--follow`).
- App passwords are retired; only API/access tokens and OAuth are supported.
