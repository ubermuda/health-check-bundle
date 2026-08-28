<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Test\Check;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Ubermuda\HealthCheckBundle\Command\RunDiagnosticsView;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticState;
use Ubermuda\HealthCheckBundle\Testing\HealthChecks;

/**
 * Runs against a real database so the backlog query — including the way
 * messenger_messages stores UTC wall-clock times in a timezone-less column — is
 * exercised as written rather than mocked away.
 */
final class DiagnosticChecksTest extends TestCase
{
    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        $this->connection = HealthChecks::messengerConnection();
    }

    public function test_the_shipped_null_mailer_is_a_failure_not_a_pass(): void
    {
        $view = (HealthChecks::handler($this->connection, mailerDsn: 'null://null'))();

        $check = self::check($view, 'mailer');
        self::assertSame(DiagnosticState::Failed, $check->state);
        self::assertSame('check.mailer.null_transport', $check->detail);
        self::assertSame('ubermuda_health_check', $check->domain);
    }

    public function test_an_unset_mailer_dsn_is_a_failure(): void
    {
        $view = (HealthChecks::handler($this->connection, mailerDsn: null))();

        $check = self::check($view, 'mailer');
        self::assertSame(DiagnosticState::Failed, $check->state);
        self::assertSame('check.mailer.unset', $check->detail);
    }

    public function test_a_non_smtp_transport_is_reported_as_unverifiable_not_ok(): void
    {
        $view = (HealthChecks::handler($this->connection, mailerDsn: 'sendmail://default'))();

        self::assertSame(DiagnosticState::Unknown, self::check($view, 'mailer')->state);
    }

    public function test_an_unparseable_dsn_is_a_failure(): void
    {
        $view = (HealthChecks::handler($this->connection, mailerDsn: 'not-a-dsn'))();

        $check = self::check($view, 'mailer');
        self::assertSame(DiagnosticState::Failed, $check->state);
        self::assertSame('check.mailer.invalid', $check->detail);
    }

    public function test_the_placeholder_sender_address_is_flagged(): void
    {
        $view = (HealthChecks::handler($this->connection, mailerFromAddress: 'noreply@localhost'))();

        self::assertSame(DiagnosticState::Warning, self::check($view, 'mailer_sender')->state);
    }

    public function test_any_localhost_sender_address_is_flagged_not_just_a_shipped_default(): void
    {
        $view = (HealthChecks::handler($this->connection, mailerFromAddress: 'admin@localhost'))();

        self::assertSame(DiagnosticState::Warning, self::check($view, 'mailer_sender')->state);
    }

    public function test_an_unset_sender_address_is_flagged(): void
    {
        $view = (HealthChecks::handler($this->connection, mailerFromAddress: null))();

        self::assertSame(DiagnosticState::Warning, self::check($view, 'mailer_sender')->state);
    }

    public function test_a_real_sender_address_passes_and_is_echoed_back(): void
    {
        $view = (HealthChecks::handler($this->connection, mailerFromAddress: 'hello@example.com'))();

        $check = self::check($view, 'mailer_sender');
        self::assertSame(DiagnosticState::Ok, $check->state);
        self::assertSame(['%address%' => 'hello@example.com'], $check->detailParameters);
    }

    public function test_an_empty_queue_is_unknown_because_it_proves_nothing(): void
    {
        $view = (HealthChecks::handler($this->connection))();

        $check = self::check($view, 'worker');
        self::assertSame(DiagnosticState::Unknown, $check->state);
        self::assertSame('check.worker.queue_empty', $check->detail);
    }

    public function test_a_freshly_queued_message_is_still_unknown(): void
    {
        $this->enqueue('default', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $view = (HealthChecks::handler($this->connection))();

        $check = self::check($view, 'worker');
        self::assertSame(DiagnosticState::Unknown, $check->state);
        self::assertSame('check.worker.backlog_fresh', $check->detail);
    }

    public function test_a_message_nobody_claimed_for_minutes_proves_no_worker_is_consuming(): void
    {
        $this->enqueue('default', new \DateTimeImmutable('-5 minutes', new \DateTimeZone('UTC')));

        $view = (HealthChecks::handler($this->connection))();

        $check = self::check($view, 'worker');
        self::assertSame(DiagnosticState::Failed, $check->state);
        self::assertSame('check.worker.backlog_stale', $check->detail);
        self::assertSame(1, $check->detailParameters['%count%']);
        self::assertGreaterThanOrEqual(300, (int) $check->detailParameters['%seconds%']);
    }

    public function test_a_claim_abandoned_by_a_dead_worker_counts_as_backlog(): void
    {
        // A worker that crashed mid-delivery leaves delivered_at set. Past the
        // transport's redelivery timeout it would be handed out again, so a row
        // still sitting here means nothing is consuming — the same verdict as
        // an unclaimed backlog, and the exact state that locks an operator out
        // of a fresh install.
        $claimedAt = new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC'));
        $this->enqueue('default', $claimedAt, $claimedAt);

        $view = (HealthChecks::handler($this->connection))();

        $check = self::check($view, 'worker');
        self::assertSame(DiagnosticState::Failed, $check->state);
        self::assertSame('check.worker.backlog_stale', $check->detail);
        self::assertSame(1, $check->detailParameters['%count%']);
    }

    public function test_a_message_a_worker_claimed_moments_ago_is_unknown_not_failed(): void
    {
        // A live worker mid-delivery must not read as failure, and must not read
        // as "the queue is empty" either.
        $this->enqueue(
            'default',
            new \DateTimeImmutable('-10 minutes', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $view = (HealthChecks::handler($this->connection))();

        $check = self::check($view, 'worker');
        self::assertSame(DiagnosticState::Unknown, $check->state);
        self::assertSame('check.worker.claimed_in_flight', $check->detail);
        self::assertSame(1, $check->detailParameters['%count%']);
    }

    public function test_messages_parked_in_the_failed_transport_do_not_count_as_a_backlog(): void
    {
        $this->enqueue('failed', new \DateTimeImmutable('-5 minutes', new \DateTimeZone('UTC')));

        $view = (HealthChecks::handler($this->connection))();

        // Guard: without it, "the worker check is not Failed" would also pass
        // on a run where the row was never inserted.
        $failedMessages = self::check($view, 'failed_messages');
        self::assertSame(DiagnosticState::Warning, $failedMessages->state);
        self::assertSame(1, $failedMessages->detailParameters['%count%']);

        self::assertSame(DiagnosticState::Unknown, self::check($view, 'worker')->state);
    }

    public function test_an_empty_failed_transport_passes(): void
    {
        $view = (HealthChecks::handler($this->connection))();

        self::assertSame(DiagnosticState::Ok, self::check($view, 'failed_messages')->state);
    }

    public function test_an_unreadable_queue_is_unknown_rather_than_a_failure(): void
    {
        $this->connection->executeStatement('DROP TABLE messenger_messages');

        $view = (HealthChecks::handler($this->connection))();

        self::assertSame('check.worker.queue_unreadable', self::check($view, 'worker')->detail);
        self::assertSame(DiagnosticState::Unknown, self::check($view, 'worker')->state);
        self::assertSame('check.failed_messages.unreadable', self::check($view, 'failed_messages')->detail);
        self::assertSame(DiagnosticState::Unknown, self::check($view, 'failed_messages')->state);
    }

    public function test_an_unconfigured_mercure_hub_warns_without_calling_anything(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('no request expected'));

        $view = (HealthChecks::handler($this->connection, httpClient: $client))();

        $check = self::check($view, 'mercure');
        self::assertSame(DiagnosticState::Warning, $check->state);
        self::assertSame('check.mercure.unconfigured', $check->detail);
    }

    public function test_any_http_answer_from_the_hub_counts_as_present(): void
    {
        // The hub legitimately rejects an unauthenticated, topic-less GET, so
        // a 401 is proof it is listening; gating on 2xx would be a false red.
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 401]));

        $view = (HealthChecks::handler(
            $this->connection,
            mercureUrl: 'http://mercure/.well-known/mercure',
            mercureJwtSecret: 'a-secret-that-is-long-enough-for-hs256',
            httpClient: $client,
        ))();

        $check = self::check($view, 'mercure');
        self::assertSame(DiagnosticState::Ok, $check->state);
        self::assertSame(['%status%' => '401'], $check->detailParameters);
    }

    public function test_a_hub_that_cannot_be_reached_warns_rather_than_fails(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new TransportException('name or service not known'));

        $view = (HealthChecks::handler(
            $this->connection,
            mercureUrl: 'http://mercure/.well-known/mercure',
            mercureJwtSecret: 'a-secret-that-is-long-enough-for-hs256',
            httpClient: $client,
        ))();

        $check = self::check($view, 'mercure');
        self::assertSame(DiagnosticState::Warning, $check->state);
        self::assertSame('check.mercure.unreachable', $check->detail);
    }

    public function test_the_overall_state_is_the_worst_of_the_checks(): void
    {
        // A null mail transport is a failure, so nothing milder can win.
        $view = (HealthChecks::handler($this->connection, mailerDsn: 'null://null'))();

        self::assertSame(DiagnosticState::Failed, $view->overall);
    }

    /**
     * Mirrors what the Doctrine transport writes: UTC wall-clock times in a
     * timezone-less column. `$deliveredAt` is the claim a consuming worker
     * stamps on the row and clears by deleting it.
     */
    private function enqueue(string $queueName, \DateTimeImmutable $availableAt, ?\DateTimeImmutable $deliveredAt = null): void
    {
        $this->connection->insert('messenger_messages', [
            'body' => '{}',
            'headers' => '{}',
            'queue_name' => $queueName,
            'created_at' => $availableAt,
            'available_at' => $availableAt,
            'delivered_at' => $deliveredAt,
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
            'available_at' => Types::DATETIME_IMMUTABLE,
            'delivered_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    private static function check(RunDiagnosticsView $view, string $key): Diagnostic
    {
        foreach ($view->checks as $check) {
            if ($check->key === $key) {
                return $check;
            }
        }

        throw new \LogicException(\sprintf('No "%s" check in the status view.', $key));
    }
}
