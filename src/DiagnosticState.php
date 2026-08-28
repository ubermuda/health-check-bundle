<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle;

/**
 * Outcome of a single diagnostic check.
 *
 * `Unknown` is a first-class result, not a placeholder: some things an operator
 * needs (is a messenger worker consuming?) cannot be established from a web
 * request, and reporting them as green would be an assertion the app cannot
 * back up.
 */
enum DiagnosticState: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Unknown = 'unknown';
    case Failed = 'failed';

    /**
     * Translation key of the state's own name. It resolves in the bundle's
     * catalogue, never in the `$domain` the Diagnostic carries: that domain is
     * the contributing check's, and the four state names are the bundle's
     * whoever contributed the row.
     *
     * @return non-empty-string
     */
    public function translationKey(): string
    {
        return 'state.'.$this->value;
    }

    /** @return non-empty-string */
    public function translationDomain(): string
    {
        return UbermudaHealthCheckBundle::TRANSLATION_DOMAIN;
    }

    /**
     * How loudly this state should be reported, so a page can show the worst
     * result of a set. Higher wins.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Unknown => 1,
            self::Warning => 2,
            self::Failed => 3,
        };
    }
}
