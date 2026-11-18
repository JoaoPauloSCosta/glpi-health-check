# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-11-18

### Added

- Compatibility with GLPI 11.0 and GLPI 12.0, in addition to GLPI 10.0. The same release runs on the three branches; version specific code is isolated in `GlpiPlugin\Healthcheck\Compat`.
- Each run stores a signature of the evaluation rules (registered checks, penalty weights and configured thresholds), next to the plugin version.
- Comparison: checks that exist in only one of the two runs are reported as "Check added" / "Check removed" instead of new or solved problems, a notice tells when the rules changed between the runs, and the score difference is split into "same checks" and "rule changes". The dashboard shows the same notice for the previous run.
- Automatic actions check for GLPI 12 and later: actions blocked in the new "Error" status after repeated failures, and actions currently failing and being retried. The essential tasks check also reports essential actions blocked in this status.

### Changed

- The stylesheet moved from `css/` to `public/css/`, the only plugin directory served by GLPI 11 and later.
- Asset ownership check: on GLPI 11 and later, groups linked through `glpi_groups_items` are taken into account.
- Web server document root check: evaluated by the plugin itself on GLPI 11 and later, where the GLPI requirement classes it relied on no longer exist.
- SQL replica detection and external release lookup no longer depend on APIs removed in GLPI 12.
- The English (en_GB, en_US) translation is declared in the plugin descriptor and documented.

### Fixed

- GLPI 12: fatal errors when loading the plugin menu class, caused by the native types added to `CommonGLPI` (`$rightname` redeclaration and `canView()` return type).
- PHP version check: on GLPI 11 and later, the highest supported PHP branch (e.g. PHP 8.5) was wrongly reported as unsupported, as `GLPI_MAX_PHP` became inclusive.
- Database version check: the recommendation now shows the minimum MySQL/MariaDB versions of the running GLPI (GLPI 11: MySQL 8.0/MariaDB 10.6, GLPI 12: MySQL 8.0/MariaDB 10.11).
- PDF export: the download silently failed (`TCPDF ERROR: Some data has already been output`) when anything had been output earlier in the request, such as PHP notices shown in debug mode. The PDF is now built in memory and any pending output is discarded before sending the file; JSON and CSV exports use the same path.
- GLPI 12: PDF export crashed with "Typed property GLPIPDF::$total_count must not be accessed before initialization", as the report was created without an item count. The number of checks is now passed, and the PDF footer shows it.
- On GLPI 12, instructions point to the new menu locations of the password policy (*Setup > Security*) and of the logs purge (*Setup > Data and Privacy > Historical logs*).

## [1.0.0] - 2026-11-07

Internal version, not published as a release archive.

### Added

- Dashboard under *Setup > Health Check*: health score, overall status, check map by category, prioritized problem list, score trend and comparison with the previous run.
- Update and requirements checks: GLPI version, PHP version and end of life, installer requirements, database schema version, pending structural migrations.
- Database checks: connection and latency, MySQL/MariaDB version and support, critical server settings, table status, schema integrity and expected indexes.
- Automatic actions checks: cron execution, execution mode, essential and cleanup tasks, late, stuck and failing tasks.
- E-mail checks: sending configuration, notification queue, sending/authentication errors, mail collectors, refused e-mails, SMTP/IMAP connectivity.
- Directory checks: access to data, cache, sessions, logs, plugins, marketplace and configuration directories; overly open permissions.
- Plugin checks: installed plugins and states, declared compatibility, available updates (optional).
- Security checks: HTTPS and application URL, default passwords, web server document root, sensitive files, session cookies, insecure settings, password policy, administrative access, REST API exposure.
- Performance checks: disk space, memory, PHP settings, GLPI cache, log volume, database volume and server indicators, slow automatic actions.
- Operational integrity checks: orphan records, duplicate users, entity tree, assets without owner, incomplete rules, documents without file.
- Diagnostic history with comparison between two runs.
- PDF, JSON and CSV exports.
- Light (daily) and full (weekly) scheduled diagnostics as GLPI automatic actions.
- Optional external lookups (GLPI releases, marketplace), disabled by default.
- `healthcheck_checks` hook allowing other plugins to register checks.
