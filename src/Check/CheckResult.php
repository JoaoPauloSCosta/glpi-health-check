<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check;

/**
 * Outcome of a single check.
 *
 * Built with a fluent API:
 *   CheckResult::warning('...', Severity::MEDIUM)
 *       ->impact('...')->cause('...')->recommendation('...')
 *       ->steps(['...', '...'])->doc('https://...')
 *       ->facts(['Label' => 'value'])->items([...], ['col' => 'Header']);
 */
final class CheckResult
{
    /** Maximum number of detail rows kept per result. */
    public const MAX_ITEMS = 100;

    private string $status;
    private int $severity;
    private string $message;
    private string $impact = '';
    private string $cause = '';
    private string $recommendation = '';
    /** @var string[] */
    private array $steps = [];
    private string $doc_url = '';
    /** @var array<string, string> */
    private array $facts = [];
    /** @var array<int, array<string, mixed>> */
    private array $items = [];
    /** @var array<string, string> */
    private array $columns = [];
    private int $items_total = 0;

    private function __construct(string $status, int $severity, string $message)
    {
        $this->status   = $status;
        $this->severity = $severity;
        $this->message  = $message;
    }

    public static function ok(string $message): self
    {
        return new self(Status::OK, Severity::NONE, $message);
    }

    public static function info(string $message): self
    {
        return new self(Status::INFO, Severity::NONE, $message);
    }

    public static function warning(string $message, int $severity = Severity::MEDIUM): self
    {
        return new self(Status::WARNING, min(max($severity, Severity::LOW), Severity::HIGH), $message);
    }

    public static function critical(string $message, int $severity = Severity::HIGH): self
    {
        return new self(Status::CRITICAL, max($severity, Severity::HIGH), $message);
    }

    public static function skipped(string $reason): self
    {
        return new self(Status::SKIPPED, Severity::NONE, $reason);
    }

    public static function error(string $message): self
    {
        return new self(Status::ERROR, Severity::NONE, $message);
    }

    /**
     * Build the worst result among several findings of a same check.
     *
     * @param array<int, array{0: string, 1: int}> $findings list of [status, severity]
     */
    public static function worstOf(array $findings, string $message_ok, string $message_problem): self
    {
        $status   = Status::OK;
        $severity = Severity::NONE;
        foreach ($findings as [$f_status, $f_severity]) {
            if (Status::getWeight($f_status) < Status::getWeight($status)
                || ($f_status === $status && $f_severity > $severity)) {
                $status   = $f_status;
                $severity = $f_severity;
            }
        }
        switch ($status) {
            case Status::CRITICAL:
                return self::critical($message_problem, $severity);
            case Status::WARNING:
                return self::warning($message_problem, $severity);
            case Status::INFO:
                return self::info($message_ok);
        }
        return self::ok($message_ok);
    }

    public function impact(string $impact): self
    {
        $this->impact = $impact;
        return $this;
    }

    public function cause(string $cause): self
    {
        $this->cause = $cause;
        return $this;
    }

    public function recommendation(string $recommendation): self
    {
        $this->recommendation = $recommendation;
        return $this;
    }

    /**
     * @param string[] $steps
     */
    public function steps(array $steps): self
    {
        $this->steps = array_values(array_filter($steps, 'strlen'));
        return $this;
    }

    public function doc(string $url): self
    {
        $this->doc_url = $url;
        return $this;
    }

    /**
     * Key indicators displayed as "label: value".
     *
     * @param array<string, scalar|null> $facts
     */
    public function facts(array $facts): self
    {
        foreach ($facts as $label => $value) {
            if (is_bool($value)) {
                $value = $value ? __('Sim', 'healthcheck') : __('Não', 'healthcheck');
            }
            $this->facts[(string) $label] = $value === null ? '-' : (string) $value;
        }
        return $this;
    }

    /**
     * Detail rows displayed as a table.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<string, string>            $columns key => header label (defaults to row keys)
     */
    public function items(array $items, array $columns = []): self
    {
        $items = array_values($items);
        $this->items_total = count($items);
        $this->items = array_slice($items, 0, self::MAX_ITEMS);
        if (count($columns) === 0 && count($this->items) > 0) {
            $keys = array_keys($this->items[0]);
            $columns = array_combine($keys, $keys);
        }
        $this->columns = $columns;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getSeverity(): int
    {
        return $this->severity;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function toArray(): array
    {
        return [
            'status'         => $this->status,
            'severity'       => $this->severity,
            'message'        => $this->message,
            'impact'         => $this->impact,
            'cause'          => $this->cause,
            'recommendation' => $this->recommendation,
            'steps'          => $this->steps,
            'doc_url'        => $this->doc_url,
            'facts'          => $this->facts,
            'columns'        => $this->columns,
            'items'          => array_map(
                static function (array $row): array {
                    return array_map(
                        static function ($value) {
                            if (is_bool($value)) {
                                return $value ? __('Sim', 'healthcheck') : __('Não', 'healthcheck');
                            }
                            return is_scalar($value) || $value === null ? $value : json_encode($value);
                        },
                        $row
                    );
                },
                $this->items
            ),
            'items_total'    => $this->items_total,
        ];
    }
}
