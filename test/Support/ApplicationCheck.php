<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Test\Support;

use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;

/** Stands in for a check an application contributes on top of the bundle's. */
final readonly class ApplicationCheck implements DiagnosticInterface
{
    #[\Override]
    public function __invoke(): Diagnostic
    {
        return new Diagnostic(key: 'application', state: DiagnosticState::Ok, detail: 'check.application.ok');
    }

    #[\Override]
    public static function priority(): int
    {
        return 0;
    }
}
