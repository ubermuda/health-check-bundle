<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Check;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;
use Ubermuda\HealthCheckBundle\UbermudaHealthCheckBundle;

final readonly class MailerSenderCheck implements DiagnosticInterface
{
    /**
     * Domain of the MAILER_FROM_ADDRESS default a skeleton ships. Any address
     * on it is deliverable nowhere, so an instance still using one cannot
     * complete a single registration — and matching the domain rather than an
     * exact default also catches the operator who edited the local part and
     * stopped.
     */
    private const string PLACEHOLDER_FROM_DOMAIN = '@localhost';

    public function __construct(
        #[Autowire('%env(default::MAILER_FROM_ADDRESS)%')]
        private ?string $mailerFromAddress,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 60;
    }

    #[\Override]
    public function __invoke(): Diagnostic
    {
        if (null === $this->mailerFromAddress || '' === $this->mailerFromAddress
            || str_ends_with($this->mailerFromAddress, self::PLACEHOLDER_FROM_DOMAIN)) {
            return new Diagnostic(
                'mailer_sender',
                DiagnosticState::Warning,
                'check.mailer_sender.placeholder',
                domain: UbermudaHealthCheckBundle::TRANSLATION_DOMAIN,
            );
        }

        return new Diagnostic(
            'mailer_sender',
            DiagnosticState::Ok,
            'check.mailer_sender.configured',
            ['%address%' => $this->mailerFromAddress],
            UbermudaHealthCheckBundle::TRANSLATION_DOMAIN,
        );
    }
}
