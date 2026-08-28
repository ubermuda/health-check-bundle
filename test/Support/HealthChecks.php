<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Test\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\NullTransportFactory;
use Symfony\Component\Mailer\Transport\SendmailTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Ubermuda\HealthCheckBundle\Check\FailedMessagesCheck;
use Ubermuda\HealthCheckBundle\Check\MailerSenderCheck;
use Ubermuda\HealthCheckBundle\Check\MailerTransportCheck;
use Ubermuda\HealthCheckBundle\Check\MercureCheck;
use Ubermuda\HealthCheckBundle\Check\WorkerCheck;
use Ubermuda\HealthCheckBundle\Command\RunDiagnosticsHandler;

/**
 * Builds a real RunDiagnosticsHandler wired to values a test controls, with the
 * checks in the order the container gives them so a test sees the real report.
 *
 * The defaults are the combination that touches no network at all — a null mail
 * transport and no Mercure hub — so a test that does not care about the checks
 * still cannot hang on a socket.
 */
final class HealthChecks
{
    public static function handler(
        Connection $connection,
        ?string $mailerDsn = 'null://null',
        ?string $mailerFromAddress = 'noreply@localhost',
        ?string $mercureUrl = null,
        ?string $mercureJwtSecret = null,
        ?HttpClientInterface $httpClient = null,
    ): RunDiagnosticsHandler {
        $logger = new NullLogger();

        return new RunDiagnosticsHandler([
            new MailerTransportCheck(
                new Transport([
                    new NullTransportFactory(),
                    new SendmailTransportFactory(),
                    new EsmtpTransportFactory(),
                ]),
                $mailerDsn,
                $logger,
            ),
            new MailerSenderCheck($mailerFromAddress),
            new WorkerCheck($connection, $logger),
            new FailedMessagesCheck($connection, $logger),
            new MercureCheck($httpClient ?? new MockHttpClient(), $mercureUrl, $mercureJwtSecret, $logger),
        ]);
    }

    /**
     * An in-memory database carrying the one table the queue checks read. The
     * column types mirror what the Doctrine transport creates: timestamps are
     * UTC wall-clock strings with no zone.
     */
    public static function messengerConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE messenger_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                body TEXT NOT NULL,
                headers TEXT NOT NULL,
                queue_name TEXT NOT NULL,
                created_at TEXT NOT NULL,
                available_at TEXT NOT NULL,
                delivered_at TEXT DEFAULT NULL
            )',
        );

        return $connection;
    }
}
