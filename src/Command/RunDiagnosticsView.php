<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Command;

use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticState;

final readonly class RunDiagnosticsView
{
    /**
     * @param list<Diagnostic> $checks
     * @param DiagnosticState  $overall the worst state among $checks, for the page-level summary
     */
    public function __construct(
        public array $checks,
        public DiagnosticState $overall,
    ) {
    }
}
