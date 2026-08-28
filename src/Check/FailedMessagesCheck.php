<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Check;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;
use Ubermuda\HealthCheckBundle\UbermudaHealthCheckBundle;

/**
 * Surfaces the `failed` transport, which is otherwise invisible: messages that
 * exhausted their retries sit there indefinitely and no part of the UI mentions
 * them.
 */
final readonly class FailedMessagesCheck implements DiagnosticInterface
{
    private const string FAILED_COUNT_SQL = 'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :failed';

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 40;
    }

    #[\Override]
    public function __invoke(): Diagnostic
    {
        try {
            $failed = (int) $this->connection->fetchOne(
                self::FAILED_COUNT_SQL,
                ['failed' => MessengerQueues::FAILED],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('The failed-message queue could not be read.', ['exception' => $e]);

            return new Diagnostic(
                'failed_messages',
                DiagnosticState::Unknown,
                'check.failed_messages.unreadable',
                domain: UbermudaHealthCheckBundle::TRANSLATION_DOMAIN,
            );
        }

        if (0 === $failed) {
            return new Diagnostic(
                'failed_messages',
                DiagnosticState::Ok,
                'check.failed_messages.none',
                domain: UbermudaHealthCheckBundle::TRANSLATION_DOMAIN,
            );
        }

        return new Diagnostic(
            'failed_messages',
            DiagnosticState::Warning,
            'check.failed_messages.present',
            ['%count%' => $failed],
            UbermudaHealthCheckBundle::TRANSLATION_DOMAIN,
        );
    }
}
