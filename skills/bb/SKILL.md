---
name: bb
description: Use the bb CLI to work with Bitbucket Cloud (pull requests, pipelines, deployments, issues, branches, repositories, raw API). Use whenever a task touches a Bitbucket repository, PR, pipeline or deployment.
---

# Using bb (Bitbucket Cloud CLI) as an agent

## Ground rules

- **Always ask for JSON**: add `--json`, or `--jq '<filter>'` to get only the
  fields you need (fewer tokens). `--jq` alone implies `--json` and needs the
  `jq` binary.
- **Run non-interactively**: add `-n`. Commands that would prompt fail with a
  clear message instead of guessing.
- **Destructive or risky actions need `--yes`** (`pr:merge`, `pr:close`,
  `branch:delete`, `repo:delete`, `auth:logout`, `pipeline:run`,
  `pipeline:stop`, `repo:edit --public`, `plugin:install`, and `bb api` with
  any method other than GET). Without it they refuse and send nothing. Only
  pass `--yes` when the user asked for that action.
- `--jq` is checked before anything is sent: an invalid filter or missing `jq`
  fails with exit 1 and no side effects.
- **Target a repo explicitly** with `-R workspace/repo` unless you are inside a
  clone whose `origin` points to bitbucket.org.
- **stdout is data, stderr is diagnostics.** In JSON mode an API failure prints
  `{"error":{"status":404,"message":"..."}}` on stderr. Exit code `0` means the
  action happened; `1` means it did not.
- Piped table output (without `--json`) is tab-separated with a header row.
- bb recognises agent sessions (Claude Code, Cursor, Codex, Gemini CLI,
  Windsurf, Copilot... or `AI_AGENT` set) and then behaves as if piped and
  non-interactive even inside a pseudo-terminal. `BB_AGENT=1|0` forces it.

## Authentication

```bash
export BB_TOKEN=<Atlassian API token or repo/workspace access token>
export BB_WORKSPACE=acme   # optional default workspace
bb auth:status -n          # verify; exit 1 if not authenticated
```

`BB_TOKEN` overrides the config file and is never written to disk. Otherwise
the user must have run `bb configure` or `bb auth login`.

## Discover commands

```bash
bb list --format=json | jq -r '.commands[].name'
bb help pr:create            # options for one command
```

## Common workflows

```bash
# Pull requests
bb pr:list -R acme/api --jq '.[] | {id, title, author: .author.display_name}'
bb pr:show 42 -R acme/api --json
bb pr:diff 42 -R acme/api                      # raw unified diff
bb pr:create -R acme/api -n --title "Fix X" --source fix/x --dest main --description "..." --jq '.id'
bb pr:comment 42 -R acme/api --body "LGTM" --json
bb pr:checks 42 -R acme/api --json             # build statuses; exit 1 if any failed
bb pr:merge 42 -R acme/api --strategy squash --yes --json
bb pr:status -R acme/api --json                # {"authored": [...], "reviewing": [...]}

# Pipelines
bb pipeline:list -R acme/api --json
bb pipeline:run -R acme/api --branch main --yes --jq '.build_number'
bb pipeline:view 4879 -R acme/api --json
bb pipeline:logs 4879 -R acme/api              # failed step's log by default
bb pipeline:tests 4879 -R acme/api --json

# Deployments
bb env:list -R acme/api --json
bb deployment:list -R acme/api --json

# Anything else: raw API (paths relative to https://api.bitbucket.org/2.0)
bb api /repositories/acme/api --jq '.mainbranch.name'
bb api /repositories/acme/api/refs/branches --paginate --jq '.[].name'
bb api /repositories/acme/api/pullrequests/42/comments -X POST -f 'content.raw=Hi' --yes
```

## Tips

- List commands default to a `--limit`; a stderr note tells you when more
  results exist. Raise `--limit` or use `bb api ... --paginate`.
- Inside a clone, PR commands accept no id and use the PR for the current
  branch (a "Using PR #N" note goes to stderr).
- Do not use `--web` or `browse` in agent runs; they open a browser.
