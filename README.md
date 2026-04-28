# Coqui Toolkit Generator

Standalone toolkit generator for scaffolding and extending installable Coqui toolkits.

## Installation

```bash
composer require carmelosantana/coqui-toolkit-generator
```

When installed into a Coqui workspace, the toolkit is auto-discovered on boot.

## Tools

- `coqui_toolkit_create` scaffolds a new toolkit package under `workspace/packages/<name>`.
- `coqui_toolkit_add` adds a new tool method to an existing generated toolkit.

## Workflow

1. Use `coqui_toolkit_create` to generate a package skeleton.
2. Use `coqui_toolkit_add` to add more tools to the generated toolkit class.
3. Install the generated toolkit package into the target Coqui workspace.
4. Restart Coqui so the installed toolkit is discovered.

## Development

```bash
composer install
composer test
composer analyse
```
