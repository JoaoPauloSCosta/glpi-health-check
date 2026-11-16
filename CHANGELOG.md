# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-11-15

### Added

- Compatibility with GLPI 11.0 and GLPI 12.0, in addition to GLPI 10.0. The same release runs on the three branches; version specific code is isolated in `GlpiPlugin\Healthcheck\Compat`.
- Each run stores a signature of the evaluation rules (registered checks, penalty weights and configured thresholds), next to the plugin version.
- Comparison: checks that exist in only one of the two runs are reported as "Check added" / "Check removed" instead of new or solved problems, a notice tells when the rules changed between the runs, and the score difference is split into "same checks" and "rule changes". The dashboard shows the same notice for the previous run.

### Changed

- The stylesheet moved from `css/` to `public/css/`, the only plugin directory served by GLPI 11 and later.
- Asset ownership check: on GLPI 11 and later, groups linked through `glpi_groups_items` are taken into account.
- Web server document root check: evaluated by the plugin itself on GLPI 11 and later, where the GLPI requirement classes it relied on no longer exist.
- SQL replica detection and external release lookup no longer depend on APIs removed in GLPI 12.

## [1.0.0] - 2026-11-07

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
