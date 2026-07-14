# bb

A `gh`-style command-line client for **Bitbucket Cloud**, built with
[Laravel Zero](https://laravel-zero.com) and [Saloon](https://docs.saloon.dev).

Manage repositories, pull requests, issues, branches and pipelines from your
terminal — and let `git` push/pull over HTTPS using the same credentials.

---

## Requirements

- PHP 8.2+
- `git` on your PATH
- `jq` on your PATH (optional — only for `--jq` filtering)

## Install

```bash
./install.sh                        # builds a PHAR and installs to /usr/local/bin
PREFIX=~/.local/bin ./install.sh    # install without sudo
```

Or run from source during development:

```bash
composer install
php bb <command>
```

## Authentication

bb supports three auth methods. Pick one:

### OAuth (browser) — recommended

Create an OAuth consumer in **Workspace settings → OAuth consumers** with the
callback URL `http://localhost:8888/callback`, then:

```bash
bb auth login
```

Tokens are refreshed automatically when they expire.

### App password / API token

```bash
bb configure
```

- **App password**: username + an [app password](https://bitbucket.org/account/settings/app-passwords/).
- **API / access token**: sent as a Bearer token.

Credentials are stored in `~/.config/bb/config.json` (mode `0600`).

### Check / clear

```bash
bb auth status
bb auth logout
```

## Use git over HTTPS via bb

Register bb as a git credential helper so `git push`/`git pull` authenticate
transparently for `bitbucket.org`:

```bash
bb auth setup-git
```

## Selecting a repository

Commands that act on a repo resolve it in this order (like `gh`):

1. the `-R, --repo workspace/repo` flag
2. the `origin` git remote of the current directory
3. a bare slug + the default workspace set during `configure`

```bash
cd my-repo/          # inside a Bitbucket checkout
bb pr:list        # repo detected from origin
bb pr:list -R acme/other-repo
```

## Commands

### Auth
| Command | Description |
|---|---|
| `auth:login` | Log in via OAuth in the browser |
| `auth:status` | Show current authentication status |
| `auth:logout` | Remove stored credentials |
| `auth:token` | Print the bearer token (for scripting) |
| `auth:setup-git` | Configure git HTTPS auth via bb |

### Repositories
| Command | Description |
|---|---|
| `repo:list [workspace]` | List repositories |
| `repo:show` | Show repository details (`--web`, `--json`) |
| `repo:clone <repo> [dir]` | Clone with git (`--https`) |
| `repo:create <repo>` | Create a repo (`--public`, `--project`, `--clone`) |
| `repo:edit <repo>` | Edit settings (`--description`, `--name`, `--project`, `--private/--public`) |
| `repo:fork <repo>` | Fork a repo (`--into`, `--name`) |
| `repo:delete <repo>` | Delete a repo (`--yes`) |

### Workspaces, projects, commits, tags
| Command | Description |
|---|---|
| `workspace:list` | List workspaces you belong to |
| `project:list [workspace]` | List projects in a workspace |
| `commit:list [revision]` | List commits (`--limit`, `--json`) |
| `tag:list` | List tags |
| `tag:create [name]` | Create a tag (`--target`) |

### Pull requests
| Command | Description |
|---|---|
| `pr:list` | List PRs (`--state`, `--json`) |
| `pr:show <id>` | Show a PR (`--web`, `--json`) |
| `pr:status` | PRs you authored / must review |
| `pr:create` | Create a PR (`--title`, `--source`, `--dest`, `--web`) |
| `pr:checkout <id>` | Check out a PR branch locally (fork-aware) |
| `pr:diff <id>` | Show the PR diff (colorized) |
| `pr:checks <id>` | Show CI / pipeline statuses |
| `pr:comment <id>` | Add a comment (`--body`) |
| `pr:approve <id>` | Approve |
| `pr:request-changes <id>` | Request changes (`--body`) |
| `pr:merge <id>` | Merge (`--strategy`, `--close-source-branch`) |
| `pr:edit <id>` | Edit title/description/destination |
| `pr:close <id>` | Decline a PR (`--yes`) |

### Issues
| Command | Description |
|---|---|
| `issue:list` | List issues (`--state`, `--json`) |
| `issue:view <id>` | Show an issue (`--web`, `--json`) |
| `issue:create` | Create an issue (`--title`, `--kind`, `--priority`) |
| `issue:comment <id>` | Add a comment (`--body`) |
| `issue:edit <id>` | Edit an issue |
| `issue:close <id>` | Close an issue (`--state`) |

### Branches
| Command | Description |
|---|---|
| `branch:list` | List branches (`--json`) |
| `branch:create [name]` | Create a branch (`--from`) |
| `branch:delete <name>` | Delete a branch (`--yes`) |

### Pipelines
| Command | Description |
|---|---|
| `pipeline:list` | List recent pipelines (`--json`) |
| `pipeline:view <uuid>` | Show a pipeline and its steps |
| `pipeline:run` | Trigger a run (`--branch`, `--pattern`) |
| `pipeline:stop <uuid>` | Stop a running pipeline |

### Other
| Command | Description |
|---|---|
| `api <path>` | Authenticated raw API request (`-X`, `-f`, `--query`, `--input`, `--jq`) |
| `configure` | Set up credentials interactively |
| `config [key] [value]` | Get/set preferences (e.g. `config pager off`) |
| `alias [name] [expansion]` | Manage command aliases (e.g. `alias co pr:checkout`) |
| `completion <shell>` | Print a shell completion script |

## Quality-of-life

- **Current-branch PRs** — `pr:show`, `pr:diff`, `pr:checks` work with no id inside a checkout; they resolve the open PR for the current branch.
- **`pr:create --fill`** — fills title/description from the branch commits.
- **Watch + notify** — `pipeline:watch [uuid]` and `pr:checks --watch` poll until done and fire a desktop notification.
- **Aliases** — `bb alias co pr:checkout`, then `bb co 42`.
- **Pager** — long `pr:diff` output is paged through `$PAGER`/less on a TTY (disable with `bb config pager off`).
- **Rate limits** — HTTP 429 responses are retried with backoff (honouring `Retry-After`).
- **`BB_DEBUG=1`** — trace every request/response to stderr.

## Scripting

Most read commands support `--json` for raw output and `--jq` to filter it:

```bash
bb repo:list acme --json --jq='.[].slug'
bb pr:list -R acme/api --json --jq='.[] | select(.state=="OPEN") | .title'
bb api /repositories/acme/api --jq='.mainbranch.name'
```

## Development

```bash
composer install
vendor/bin/pest        # run the test suite
vendor/bin/pint        # format code
```

## License

MIT
