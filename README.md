# SymWP

## Structure

- `env-builder/`
  - docker files and configs to build environment for S2E
- `harnesses/`
  - harnesses for analysis
- `s2e/`
  - `plugins/`
    - source code for S2E plugins
  - `templates/`
    - templates for S2E env
- `scripts/`
  - automations scripts
- `sqlite-database-integration/`
  - submodule: `sqlite-database-integration`
- `patches/`
  - patches for submodules

## How to build SymWP?

```bash
git clone --recursive https://github.com/TaiYou-TW/SymWP.git

# Patch sqlite-database-integration plugin
cd SymWP/sqlite-database-integration
git apply ../patches/sqlite-database-integration.patch
```