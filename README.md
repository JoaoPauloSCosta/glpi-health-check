# Health Check GLPI plugin

[![License](https://img.shields.io/badge/License-GPLv3+-blue.svg)](LICENSE)
[![GLPI](https://img.shields.io/badge/GLPI-10.0.x-brightgreen.svg)](https://glpi-project.org)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777bb4.svg)](https://www.php.net)

**English** | [Português (Brasil)](README.pt_BR.md)

Health Check is a free and open source plugin that **diagnoses, monitors and helps prevent** operational, technical and security problems in a GLPI installation.

It analyses the installation, detects misconfigurations, failures and operational risks, and explains each finding with clear guidance on how to fix it. The plugin is **strictly advisory**: it never modifies GLPI files, database or settings. The only data it writes is its own diagnostic history and configuration.

> The user interface is currently available in Brazilian Portuguese. All strings go through GLPI's translation functions (`healthcheck` domain) and can be translated.

## Table of contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
- [Configuration](#configuration)
- [Privacy and offline operation](#privacy-and-offline-operation)
- [Extending the plugin](#extending-the-plugin)
- [Known limitations](#known-limitations)
- [Contributing](#contributing)
- [License](#license)

## Features

### Dashboard

Available under **Setup > Health Check**:

- health score (0–100) and overall status: green (healthy), yellow (attention required) or red (critical problem);
- passed, warning, critical, informational and not-checked counters;
- "start here" shortcut to the most severe problem;
- **check map**: one cell per check, grouped by category, showing where problems concentrate;
- problems sorted by criticity, each one expandable;
- score trend and comparison with the previous run;
- buttons to run a full or light diagnostic and to export the report.

Every result includes: check name, status, criticity, description, operational impact, probable cause, recommendation, step-by-step fix, link to the official GLPI documentation when available, and the measured data behind the conclusion.

### Checks

| Area | What is checked |
|---|---|
| **Update and requirements** | GLPI version and newer releases (optional lookup), PHP version and end of security support, installer requirements (extensions, memory, timezones…), database schema version, pending structural migrations (InnoDB, utf8mb4, timestamps, unsigned keys, row format) |
| **Database** | Connection and latency, MySQL/MariaDB version, compatibility and end of support, critical server settings (`max_allowed_packet`, charset, PHP × database clock drift…), crashed tables, tables without primary key, AUTO_INCREMENT close to its limit, schema integrity and expected indexes |
| **Automatic actions (cron)** | Cron execution and last run, GLPI × CLI mode, disabled essential and cleanup tasks, late or never executed tasks, stuck tasks, recurring failures |
| **E-mail** | Sending configuration, notification queue (pending, retrying, given up), sending/authentication errors from `mail-error.log`, mail collectors (errors, last collection, SSL), refused e-mails, SMTP/IMAP TCP connectivity |
| **Directories and permissions** | Existence, read and write access of documents, cache, sessions, plugins, marketplace, logs and configuration directories; world-writable directories and world-readable secrets (Linux/Unix) |
| **Plugins** | Installed plugins with version and state, abnormal states, declared compatibility with the current GLPI/PHP and with the next major GLPI version, marketplace updates (optional lookup) |
| **Security** | HTTPS and application URL, default accounts with default passwords, web server document root and exposure of `files/` and `config/`, forgotten sensitive files, session cookies, insecure settings (`display_errors`, debug mode…), password policy, administrative access, REST API exposure |
| **Performance** | Disk space, memory, OPcache and PHP limits, GLPI cache, log volume and recent errors, database volume and fragmentation, database server indicators, slow automatic actions |
| **Operational integrity** | Orphan records, duplicate users and shared e-mails, users without authorization, inconsistent entities, assets without owner, incomplete rules, documents without file |

### Statuses and score

| Status | Meaning |
|---|---|
| Passed | Check is OK |
| Warning | Attention required (low, medium or high criticity) |
| Critical | Critical problem (high or critical criticity) |
| Informational | Useful information, not a problem |
| Not checked | The check could not be performed (CLI context, unavailable resource, disabled option). The plugin reports it instead of guessing |
| Check error | The check failed unexpectedly; other checks are not affected |

The score starts at 100 and loses points for each problem according to its criticity. The overall status is red when there is at least one critical problem (or the score is below 50), yellow when there are warnings (or the score is below 85), and green otherwise.

### Light and full diagnostics

- **Light**: fast checks only. Runs automatically **every day** (`HealthcheckLight` automatic action).
- **Full**: adds database schema comparison, index verification, data integrity scans and e-mail connectivity tests. Runs **every week** between 01:00 and 06:00 (`HealthcheckFull` automatic action) and on demand from the dashboard.

### History, comparison and exports

- Every run is stored with date, type, origin, score and counters, according to the configured retention (default: 180 days / 500 runs).
- **History > Compare selected** shows, check by check, new problems, solved problems, regressions and improvements.
- Any run can be exported as **PDF** (full report), **JSON** (structured data for integrations) or **CSV** (spreadsheet, `;` separator, UTF-8).

## Requirements

- GLPI **>= 10.0.0** and **< 10.0.99**
- PHP **>= 7.4** (within the range supported by your GLPI version)
- System cron configured for GLPI to run scheduled diagnostics

## Installation

### From a release

1. Download the latest archive from the [releases page](https://github.com/JoaoPauloSCosta/glpi-health-check/releases).
2. Extract it into the `plugins/` (or `marketplace/`) directory of GLPI. The directory **must** be named `healthcheck`.
3. Go to **Setup > Plugins**, then install and enable **Health Check**.

### From source

```sh
cd /path/to/glpi/plugins
git clone https://github.com/JoaoPauloSCosta/glpi-health-check.git healthcheck
```

Then install and enable it from **Setup > Plugins**, or with the GLPI console:

```sh
php bin/console plugin:install healthcheck --username=glpi
php bin/console plugin:activate healthcheck
```

The plugin has no external dependency: no `composer install` or `npm install` is required.

### Uninstallation

Uninstalling from **Setup > Plugins** removes the plugin tables, configuration and automatic actions.

## Usage

1. Open **Setup > Health Check**.
2. Click **Run full diagnostic** (*Executar diagnóstico completo*).
3. Start with the problem shown in **Start here** (*Comece por aqui*), then follow the list sorted by priority.

### Rights

| Action | Required right |
|---|---|
| View dashboard, history and exports | *Setup > General* — read |
| Run diagnostics, change settings, delete history | *Setup > General* — update |

### Scheduled diagnostics

Frequency, time window and execution mode of `HealthcheckLight` and `HealthcheckFull` are managed in **Setup > Automatic actions**. Both are registered in CLI mode, so the system cron must call GLPI:

```sh
* * * * * php /path/to/glpi/front/cron.php
```

Some checks depend on the web context (web server document root, session cookies, web server OPcache) and are reported as *not checked* when the diagnostic runs from cron. Run a manual diagnostic from the browser to evaluate them.

## Configuration

Available in **Setup > Health Check > Settings** (*Configurações*):

| Setting | Default |
|---|---|
| Allow optional external lookups | Disabled |
| Test the connection to configured mail servers (full diagnostic only) | Enabled |
| Free disk space warning / critical threshold | 15% / 5% |
| Pending notifications warning / critical threshold | 100 / 1000 |
| History retention | 180 days, 500 runs |

## Privacy and offline operation

- All local checks work **without internet access**.
- No environment data, credential, user or ticket information is sent to third parties.
- **External lookups are optional and disabled by default.** When enabled, they perform:
  - an anonymous GET of the public GLPI releases list on GitHub (cached for 24 hours);
  - a query to the GLPI marketplace catalog for plugin updates (uses the GLPI Network key already configured in GLPI).

  Both use the proxy configured in GLPI.
- Without external lookups, the version check relies on the information recorded by GLPI's own `checkupdate` automatic action, when available.
- The mail connectivity test only opens a TCP connection to the servers already configured in GLPI: no authentication, no data sent. It can be disabled.
- Exported reports may contain server names, logins and e-mail addresses: handle them as internal documents.

## Extending the plugin

Each check is an independent class extending `GlpiPlugin\Healthcheck\Check\AbstractCheck`:

```php
namespace GlpiPlugin\Myplugin;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;

class MyCheck extends AbstractCheck
{
    public function getKey(): string      { return 'myplugin.my_check'; }
    public function getCategory(): string { return Category::SECURITY; }
    public function getTitle(): string    { return __('My check', 'myplugin'); }
    public function isLight(): bool       { return true; } // false = full diagnostic only

    public function run(): CheckResult
    {
        if ($problem_detected) {
            return CheckResult::warning(__('Problem description', 'myplugin'), Severity::MEDIUM)
                ->impact('...')
                ->cause('...')
                ->recommendation('...')
                ->steps(['...', '...'])
                ->doc(self::DOC_INSTALL)
                ->facts(['Indicator' => 'value'])
                ->items([['column' => 'value']], ['column' => 'Header']);
        }
        return CheckResult::ok(__('Everything is fine', 'myplugin'));
    }
}
```

Other plugins can register their checks without changing Health Check, from their `plugin_init_xxx()` function:

```php
$PLUGIN_HOOKS['healthcheck_checks']['myplugin'] = [\GlpiPlugin\Myplugin\MyCheck::class];
```

Rules followed by every check:

- **read-only**: never modify GLPI files, database or configuration;
- **independent**: never rely on another check's result;
- **no guessing**: return `CheckResult::skipped()` when the check cannot be performed.

Each check runs inside a `try/catch` with output buffering: an exception becomes a *check error* result, is logged in `files/_log/php-errors.log`, and does not stop the diagnostic.

### Project structure

```
healthcheck/
├── setup.php, hook.php     Plugin registration, PSR-4 autoload, install/uninstall
├── healthcheck.xml         Descriptor for the GLPI plugins catalog
├── front/                  Pages: dashboard, run, history, compare, export, config
├── templates/              Twig templates (@healthcheck/...)
├── css/                    Plugin stylesheet
└── src/
    ├── Check/              AbstractCheck, CheckResult, Status, Severity, Category and the checks by area
    ├── Registry.php        List of checks and extension hook
    ├── Runner.php          Runs each check in isolation and stores the run
    ├── RunRepository.php   Persistence (glpi_plugin_healthcheck_runs / _results)
    ├── Score.php           Health score and overall status
    ├── ReportView.php      Sorting, grouping and run comparison
    ├── Exporter.php        PDF (GLPI's TCPDF), JSON and CSV exports
    ├── Cron.php            Automatic actions
    ├── ReleaseInfo.php     GLPI releases (optional lookup / offline)
    └── Settings.php        Configuration (glpi_configs, "plugin:healthcheck" context)
```

## Known limitations

- The user interface is only available in Brazilian Portuguese for now.
- On Windows servers, NTFS ACLs are not evaluated; only effective read/write access is checked.
- End of support dates of PHP and MySQL/MariaDB come from an internal table; versions not listed do not raise an end of support warning.
- SMTP/IMAP authentication failures are inferred from logs and mail collector error counters; the plugin never authenticates.
- The external release lookup requires PHP to have a CA bundle configured (`curl.cainfo` / `openssl.cafile`); otherwise the check reports the SSL failure.

## Contributing

- Report bugs and suggestions in the [issue tracker](https://github.com/JoaoPauloSCosta/glpi-health-check/issues).
- Pull requests are welcome. Please follow the [GLPI coding standards](https://glpi-developer-documentation.readthedocs.io/en/master/codingstandards.html) and keep checks read-only.
- Translations: strings use the `healthcheck` gettext domain.

See also the [GLPI plugin development documentation](https://glpi-developer-documentation.readthedocs.io/en/master/plugins/index.html).

## License

Copyright (C) 2026 João Paulo S. Costa

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. See [LICENSE](LICENSE).
