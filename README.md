# bb

A `gh`-style command-line client for **Bitbucket Cloud**, built with
[Laravel Zero](https://laravel-zero.com) and [Saloon](https://docs.saloon.dev).

Manage repositories, pull requests, issues, branches and pipelines from your
terminal — and let `git` push/pull over HTTPS using the same credentials.

![bb demo](assets/demo.gif)

---

## Install

### Homebrew (recommended)

```bash
brew install ahmetbedir/tap/bb
```

Ships as a **standalone native binary** (PHP is bundled) — no PHP install
required. `git` is needed for git-integrated commands, and `jq` is optional
(only for `--jq` filtering).

Upgrade / uninstall:

```bash
brew upgrade bb
brew uninstall bb
```

### Without Homebrew

```bash
# macOS / Linux
curl -fsSL https://github.com/ahmetbedir/bb/releases/latest/download/install.sh | sh

# Windows (PowerShell)
irm https://github.com/ahmetbedir/bb/releases/latest/download/install.ps1 | iex
```

### From source (development)

```bash
composer install
php bb <command>
```

Requires PHP 8.2+. To produce the native binaries + Homebrew formula locally:

```bash
scripts/release.sh v1.0.2   # builds dist/release/bb-* and dist/release/bb.rb
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
| `pr:list [branch]` | List PRs (`--state`, `--json`); optional source-branch filter |
| `pr:show [id]` | Show a PR (`--web`, `--json`) |
| `pr:status` | PRs you authored / must review |
| `pr:create` | Create a PR (`--title`, `--source`, `--dest`, `--fill`, `--web`) |
| `pr:checkout <id>` | Check out a PR branch locally (fork-aware) |
| `pr:diff [id]` | Show the PR diff (colorized, paged) |
| `pr:files [id]` | List changed files (+/- per file) |
| `pr:commits [id]` | List commits in the PR |
| `pr:checks [id]` | Show CI / pipeline statuses (`--watch`) |
| `pr:comment <id>` | Add a comment (`--body`) |
| `pr:approve <id>` | Approve |
| `pr:unapprove [id]` | Remove your approval |
| `pr:request-changes <id>` | Request changes (`--body`) |
| `pr:unrequest-changes [id]` | Remove your "request changes" |
| `pr:merge <id>` | Merge (`--strategy`, `--close-source-branch`) |
| `pr:edit <id>` | Edit title/description/destination |
| `pr:close <id>` | Decline a PR (`--yes`) |

Commands taking `[id]` default to the open PR for the current branch when no id is given.

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
| `branch:list` | List branches (`--name`, `--user`, `--json`) |
| `branch:create [name]` | Create a branch (`--from`) |
| `branch:delete <name>` | Delete a branch (`--yes`) |

### Pipelines
| Command | Description |
|---|---|
| `pipeline:list` | List recent pipelines (`--branch`, `--status`, `--trigger`, `--pattern`, `--commit`, `--json`) |
| `pipeline:view [pipeline]` | Show a pipeline, its steps and their timings (`--web`, `--json`) |
| `pipeline:step [pipeline] [step]` | One step in full: state, timings, image, runner and its commands (`--setup`, `--teardown`) |
| `pipeline:logs [pipeline] [step]` | Print or follow a step's build log (`--follow`, `--all`, `--tail`, `--grep`, `--output`) |
| `pipeline:tests [pipeline] [step]` | Test report of a step (`--all`, `--reasons`) |
| `pipeline:watch [pipeline]` | Follow a pipeline step by step, then notify |
| `pipeline:run` | Trigger a run (`--branch`, `--pattern`) |
| `pipeline:stop [pipeline]` | Stop a running pipeline |

Every pipeline argument accepts a **build number** (`4879`), a **UUID** (with or
without braces) or nothing at all — with no argument the most recent pipeline is
used, narrowed by `--branch` when given. A step is picked by its number
(`1`), a fragment of its name (`deploy`) or its UUID; commands that need one
default to the failed step and otherwise ask.

```bash
bb pipeline:view                      # latest pipeline in this checkout
bb pipeline:view --branch develop     # latest on develop
bb pipeline:logs 4879 deploy --tail 50
bb pipeline:logs --follow             # tail the running step live
bb pipeline:step 4879 1 --setup       # including Bitbucket's own setup commands
bb pipeline:logs 4879 --all --output build.log
```

### Deployments
| Command | Description |
|---|---|
| `deployment:list` | List deployments newest first (`--environment`, `--status`, `--limit`, `--json`) |
| `deployment:view [deployment]` | Full detail: release, commit, timings and the step that ran it (`--web`, `--json`) |
| `deployment:logs [deployment]` | Print or follow the log of the deploying step (`--follow`, `--tail`, `--grep`, `--output`) |
| `environment:list` | List environments with their lock, gate and restrictions |
| `environment:view <environment>` | One environment: locks, restrictions, variables and recent deployments |

Deployments are addressed by their number (`867`) or UUID, and default to the
most recent one; environments by name, slug or UUID.

```bash
bb deployment:list -e production
bb deployment:view                    # most recent deployment
bb deployment:view -e staging         # most recent staging deployment
bb deployment:logs 867 --grep error
bb environment:view production
```

### Other
| Command | Description |
|---|---|
| `browse` | Open the repository's Bitbucket page in your browser |
| `browse:show` | Print the repository URL without opening a browser |
| `api <path>` | Authenticated raw API request (`-X`, `-f`, `--query`, `--input`, `--jq`) |
| `configure` | Set up credentials interactively |
| `config [key] [value]` | Get/set preferences (e.g. `config pager off`) |
| `alias [name] [expansion]` | Manage command aliases (e.g. `alias co pr:checkout`) |
| `completion <shell>` | Print a shell completion script |

## Quality-of-life

- **Current-branch PRs** — `pr:show`, `pr:diff`, `pr:checks` work with no id inside a checkout; they resolve the open PR for the current branch.
- **`pr:create --fill`** — fills title/description from the branch commits.
- **Watch + notify** — `pipeline:watch [pipeline]` and `pr:checks --watch` poll until done and fire a desktop notification.
- **Live logs** — `pipeline:logs --follow` fetches only the bytes appended since the last poll (HTTP range requests), so tailing a running build is cheap.
- **Aliases** — `bb alias co pr:checkout`, then `bb co 42`.
- **Pager** — long `pr:diff` output is paged through `$PAGER`/less on a TTY (disable with `bb config pager off`).
- **Rate limits** — HTTP 429 responses are retried with backoff (honouring `Retry-After`).
- **`BB_DEBUG=1`** — trace every request/response to stderr.

## Plugins

Drop a PHP command class into `~/.config/bb/plugins/` and it becomes a bb
command — running with the **same** connector, helpers and Prompts/Termwind
styling as the built-ins. A plugin is any class extending
`App\Commands\BitbucketCommand`:

```php
// ~/.config/bb/plugins/HelloCommand.php
use App\Commands\BitbucketCommand;

class HelloCommand extends BitbucketCommand
{
    protected $signature = 'hello';
    protected $description = 'Say hello';

    public function handle(): int
    {
        $this->info('Hi from a plugin!');           // native look & feel
        // full access: $this->table(), $this->badge(), $this->send(),
        // $this->resolveRepo(), $this->link(), Laravel Prompts, etc.
        return self::SUCCESS;
    }
}
```

```bash
bb hello              # a plugin's signature IS its command name
bb plugin:list        # show loaded plugins
bb plugin:install <git-url|gist:ID|./path.php|./dir>   # install a plugin
bb plugin:remove <name>                        # remove one (interactive if omitted)
```

A plugin's `$signature` is used verbatim as its command name. Built-ins always
win on a name clash — a plugin can never shadow a native command.

A ready-made example (a standardized branch creator) lives in
[examples/plugins/BranchNewCommand.php](examples/plugins/BranchNewCommand.php):

```bash
cp examples/plugins/BranchNewCommand.php ~/.config/bb/plugins/
bb config branch_types  "feature,bugfix,hotfix,chore"
bb config branch_format "{type}/{ticket}-{slug}"
bb branch:new
```

Plugins load in both the source and the compiled PHAR build.

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
