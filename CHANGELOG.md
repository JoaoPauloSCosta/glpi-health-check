# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
