<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Check;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;
use Ubermuda\HealthCheckBundle\UbermudaHealthCheckBundle;

/**
 * Builds the configured transport and, when it speaks SMTP, opens a real
 * connection to it. Anything else is reported as unverifiable rather than
 * assumed working: an API transport would need a live credentialed call to
 * prove anything, and a status page must not spend the operator's quota.
 */
final readonly class MailerTransportCheck implements DiagnosticInterface
{
    /** A firewalled SMTP host must make the page slow, never make it hang. */
    private const float PROBE_TIMEOUT_SECONDS = 3.0;

    public function __construct(
        #[Autowire(service: 'mailer.transport_factory')]
        private Transport $transportFactory,

        #[Autowire('%env(default::MAILER_DSN)%')]
        private ?string $mailerDsn,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 70;
    }

    #[\Override]
    public function __invoke(): Diagnostic
    {
        if (null === $this->mailerDsn || '' === $this->mailerDsn) {
            return $this->diagnostic(DiagnosticState::Failed, 'check.mailer.unset');
        }

        try {
            $transport = $this->transportFactory->fromString($this->mailerDsn);
        } catch (\Throwable $e) {
            $this->logger->warning('MAILER_DSN could not be parsed.', ['exception' => $e]);

            return $this->diagnostic(DiagnosticState::Failed, 'check.mailer.invalid');
        }

        if ($transport instanceof NullTransport) {
            return $this->diagnostic(DiagnosticState::Failed, 'check.mailer.null_transport');
        }

        if (!$transport instanceof SmtpTransport) {
            return $this->diagnostic(DiagnosticState::Unknown, 'check.mailer.unverifiable');
        }

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(self::PROBE_TIMEOUT_SECONDS);
        }

        try {
            $transport->start();
            $transport->stop();
        } catch (\Throwable $e) {
            $this->logger->warning('The SMTP host in MAILER_DSN did not accept a connection.', ['exception' => $e]);

            return $this->diagnostic(DiagnosticState::Failed, 'check.mailer.smtp_unreachable');
        }

        return $this->diagnostic(DiagnosticState::Ok, 'check.mailer.smtp_reachable');
    }

    /** @param non-empty-string $detail */
    private function diagnostic(DiagnosticState $state, string $detail): Diagnostic
    {
        return new Diagnostic('mailer', $state, $detail, domain: UbermudaHealthCheckBundle::TRANSLATION_DOMAIN);
    }
}
