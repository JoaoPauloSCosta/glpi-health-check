# Releasing Health Check

Maintainer checklist to publish a version. This file and `tools/` are not part of the release archive.

## 1. Prepare

1. Set the version in `setup.php` (`PLUGIN_HEALTHCHECK_VERSION`).
2. Move the `[Unreleased]` entries of `CHANGELOG.md` under `## [x.y.z] - YYYY-MM-DD`.
3. In `healthcheck.xml`, add one `<version>` block per supported GLPI branch, all with the same
   `<num>` and the same `<download_url>`:

   ```xml
   <version>
      <num>x.y.z</num>
      <compatibility>~11.0.0</compatibility>
      <download_url>https://github.com/JoaoPauloSCosta/glpi-health-check/releases/download/x.y.z/glpi-healthcheck-x.y.z.tar.bz2</download_url>
   </version>
   ```

   Only declare a branch after installing, activating and running a diagnostic on it (step 3).
   Keep older versions in the list only while their archive is still downloadable.
4. Update the "tested on" table in `README.md` and `README.pt_BR.md`.
5. Commit on a branch, open a pull request and merge it into `main`.

## 2. Build and validate the archive

```sh
git checkout main && git pull
tools/build-release.sh          # builds dist/glpi-healthcheck-x.y.z.tar.bz2 from HEAD and validates it
```

`tools/validate-package.php` checks the archive integrity, the single `healthcheck/` root directory,
required files, the absence of development/temporary files, the version consistency between the archive
name, `setup.php` and `healthcheck.xml`, and the PHP syntax of every file. Run it with each PHP
version you support, e.g. `PHP_BIN=/usr/bin/php7.4 tools/build-release.sh`.

## 3. Test on a clean GLPI

For each GLPI branch declared in `healthcheck.xml` (latest patch version):

```sh
cd /path/to/clean/glpi/plugins
tar -xjf /path/to/glpi-healthcheck-x.y.z.tar.bz2
cd ..
php bin/console plugin:install healthcheck --username=glpi
php bin/console plugin:activate healthcheck
php front/cron.php --force HealthcheckLight
```

Then, in the browser: open *Setup > Health Check*, run a full diagnosis, export PDF/JSON/CSV, open the
history and the comparison, save the settings. Finally uninstall the plugin and check that the
`glpi_plugin_healthcheck_*` tables, the `plugin:healthcheck` configuration and the two automatic
actions are gone.

Test the upgrade too: install the previous version, run a diagnostic, replace the directory with the
new archive, update the plugin, and check that the history is kept.

## 4. Publish the GitHub release

```sh
git tag -a x.y.z -m "Health Check x.y.z"
git push origin x.y.z
```

On GitHub, *Releases > Draft a new release*: choose the tag `x.y.z`, title `Health Check x.y.z`, paste
the changelog section, attach `dist/glpi-healthcheck-x.y.z.tar.bz2` (and its `.sha256`), publish.

The archive name and the tag must match the `download_url` of `healthcheck.xml` exactly. Check it:

```sh
php tools/validate-package.php dist/glpi-healthcheck-x.y.z.tar.bz2 --check-urls
```

If PHP has no CA bundle configured, point `CURL_CA_BUNDLE` to one (e.g. the `ca-bundle.crt` shipped with Git).

## 5. Plugins catalog

The catalog reads `healthcheck.xml` from the default branch:
`https://raw.githubusercontent.com/JoaoPauloSCosta/glpi-health-check/main/healthcheck.xml`.
Submit this URL once on https://plugins.glpi-project.org; later versions are picked up when the XML
on `main` is updated.
