# SymWP

## Structure

- `WordPress/`
  - submodule: `WordPress`
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

## How to build SymWP?

> Our project only support Ubuntu 22.04, and we recommend run it in our env-builder. Please look at `env-builder/docker-compose.yml`.

You should install S2E first, you can read their documentation [here](https://s2e.systems/docs/s2e-env.html#installing-s2e-env).

Now, you should have a s2e folder at `~/s2e`.

Next, let's build SymWP:

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

# Manually compile php-src
cd php-src
sudo apt install -y pkg-config build-essential autoconf bison re2c libxml2-dev libsqlite3-dev
./buildconf
./configure CFLAGS="-no-pie" CXXFLAGS="-no-pie" CPPFLAGS="-no-pie" --enable-debug
make -j4
cd ../

# Copy our files to S2E folder
cp -r WordPress/ ../s2e/
cp harnesses/wordpress-loader.php ../s2e/
cp "scripts/*.(php|py)" ../s2e/

# Active s2e env if you haven't
source ../s2e-env/venv/bin/activate

# Download the plugin
cd ../s2e/
wget https://downloads.wordpress.org/plugin/custom-404-pro.3.2.7.zip
unzip custom-404-pro.3.2.7.zip

# Start testing!
python3 pipeline_runner.py custom-404-pro # `-h` to see help
```