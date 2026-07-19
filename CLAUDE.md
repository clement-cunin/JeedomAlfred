# CLAUDE.md

Guidance for Claude/autoclaude (and human contributors) working in this repository.

## Project

Alfred is a Jeedom plugin (PHP) that embeds an AI agent into the Jeedom home automation
interface. See `README.md` for architecture and setup.

## Git hooks

Before any commit, make sure `core.hooksPath` points to `.githooks`:

```
git config core.hooksPath
```

If empty or different, activate it once per clone:

```
git config core.hooksPath .githooks
```

`.githooks/pre-commit` runs `php -l` on every staged `.php` file and blocks the commit if
one of them fails to compile. This exists because a PHP fatal error (redeclared class
constant) that reached git history once crashed a production MCP server — nothing caught
it before the commit. Do not bypass this hook (`--no-verify`) to work around a failing
lint; fix the PHP syntax error instead.

PHPStan/Psalm were evaluated for this hook but not adopted yet: this plugin relies on
Jeedom core classes (`eqLogic`, `cmd`, `jeedom`, `log`, `config`, `DB`, `ajax`, `user`, …)
that only exist at runtime inside a Jeedom installation and aren't published as a
composer package, so a useful static-analysis pass would first need hand-written stubs
for the whole Jeedom API surface. That's out of scope for this quick guard rail; `php -l`
catches the fatal-error class of bug the hook was built for.
