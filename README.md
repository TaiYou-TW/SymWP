# SymWP

## Structure

- `env-builder/`
  - docker files and configs to build environment for S2E
- `harnesses/`
  - harnesses for analysis
- `patches/`
  - patches for submodules
- `php-src/`
  - submodule: `php-src`
- `s2e/`
  - `plugins/`
    - source code for S2E plugins
  - `templates/`
    - templates for S2E env
- `scripts/`
  - automations scripts
- `sqlite-database-integration/`
  - submodule: `sqlite-database-integration`
- `WordPress`
  - submodule: `WordPress`

## How to build SymWP?

```bash
git clone --recursive https://github.com/TaiYou-TW/SymWP.git

# Patch sqlite-database-integration plugin
cd SymWP/sqlite-database-integration
git apply ../patches/sqlite-database-integration.patch
cd ../

# Copy sqlite-database-integration plugin to WordPress
cp -r sqlite-database-integration ./WordPress/wp-content/plugins/

# Patch WordPress
cd WordPress
git apply ../patches/WordPress.patch
cd ../
```