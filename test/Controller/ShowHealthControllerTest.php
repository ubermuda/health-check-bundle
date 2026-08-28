<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Test\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\HealthCheckBundle\Command\CheckDatabaseHealthHandler;
use Ubermuda\HealthCheckBundle\Controller\ShowHealthController;
use Ubermuda\HealthCheckBundle\HealthMetadataProvider;
use Ubermuda\HealthCheckBundle\Test\Support\StaticMetadataProvider;

final class ShowHealthControllerTest extends TestCase
{
    public function test_a_reachable_database_gets_200_and_an_ok_body(): void
    {
        $response = $this->controller()(new Request());

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_unreachable_database_gets_503_and_leaks_nothing_about_the_failure(): void
    {
        /** @var Connection&Stub $connection */
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException(
            new \RuntimeException('SQLSTATE[08006] could not connect to server: host=db.internal user=app password=hunter2'),
        );

        // The real handler, so the assertions below still cover the whole path
        // from the driver exception to the rendered body.
        $handler = new CheckDatabaseHealthHandler($connection, new NullLogger());

        $response = (new ShowHealthController($handler, []))(new Request());

        self::assertSame(503, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertJsonStringEqualsJsonString('{"status":"error"}', $body);
        // The exception message carries the database host, user and password;
        // an unauthenticated probe must never see any of it.
        self::assertStringNotContainsString('db.internal', $body);
        self::assertStringNotContainsString('hunter2', $body);
        self::assertStringNotContainsString('SQLSTATE', $body);
    }

    public function test_sensitive_metadata_is_withheld_when_no_probe_token_is_configured(): void
    {
        // The default a self-hosted instance inherits: presenting any header at
        // all must still reveal nothing.
        $response = $this->controller(
            providers: [new StaticMetadataProvider(['version' => '77bc23c'])],
            probeToken: '',
        )(self::probeRequest('anything'));

        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $response->getContent());
    }

    public function test_a_null_probe_token_is_the_same_as_no_token(): void
    {
        // What %env(default::HEALTH_PROBE_TOKEN)% resolves to when the variable
        // is unset or blank.
        $response = $this->controller(
            providers: [new StaticMetadataProvider(['version' => '77bc23c'])],
            probeToken: null,
        )(self::probeRequest('anything'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $response->getContent());
    }

    public function test_sensitive_metadata_is_withheld_from_a_wrong_probe_token(): void
    {
        $response = $this->controller(
            providers: [new StaticMetadataProvider(['version' => '77bc23c'])],
            probeToken: 'right-token',
        )(self::probeRequest('wrong-token'));

        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $response->getContent());
    }

    public function test_sensitive_metadata_is_withheld_when_the_header_is_absent(): void
    {
        $response = $this->controller(
            providers: [new StaticMetadataProvider(['version' => '77bc23c'])],
            probeToken: 'right-token',
        )(new Request());

        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $response->getContent());
    }

    public function test_a_correct_probe_token_gets_the_sensitive_metadata(): void
    {
        $response = $this->controller(
            providers: [new StaticMetadataProvider(['version' => '77bc23c'])],
            probeToken: 'right-token',
        )(self::probeRequest('right-token'));

        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","version":"77bc23c"}',
            (string) $response->getContent(),
        );
    }

    public function test_metadata_a_provider_declares_safe_reaches_an_anonymous_caller(): void
    {
        $response = $this->controller(
            providers: [new StaticMetadataProvider(['region' => 'ams3'], sensitive: false)],
        )(new Request());

        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","region":"ams3"}',
            (string) $response->getContent(),
        );
    }

    public function test_a_null_field_is_dropped_rather_than_reported_as_null(): void
    {
        $response = $this->controller(
            providers: [new StaticMetadataProvider(['version' => null, 'region' => 'ams3'], sensitive: false)],
        )(new Request());

        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","region":"ams3"}',
            (string) $response->getContent(),
        );
    }

    public function test_fields_from_several_providers_are_merged_flat(): void
    {
        $response = $this->controller(
            providers: [
                new StaticMetadataProvider(['region' => 'ams3'], sensitive: false),
                new StaticMetadataProvider(['schema' => 42, 'debug' => false], sensitive: false),
            ],
        )(new Request());

        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","region":"ams3","schema":42,"debug":false}',
            (string) $response->getContent(),
        );
    }

    public function test_a_provider_cannot_overwrite_the_endpoints_own_verdict(): void
    {
        // Otherwise a contributor could make a failing instance answer "ok".
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/status/');

        $this->controller(
            providers: [new StaticMetadataProvider(['status' => 'ok'], sensitive: false)],
        )(new Request());
    }

    public function test_the_status_key_is_refused_even_for_an_anonymous_caller(): void
    {
        // The contract is checked whoever is calling, so a broken provider is
        // not something only a token holder ever discovers.
        $this->expectException(\LogicException::class);

        $this->controller(
            providers: [new StaticMetadataProvider(['status' => 'ok'])],
            probeToken: 'right-token',
        )(new Request());
    }

    public function test_two_providers_cannot_contribute_the_same_field(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/version/');

        $this->controller(
            providers: [
                new StaticMetadataProvider(['version' => 'a'], sensitive: false),
                new StaticMetadataProvider(['version' => 'b'], sensitive: false),
            ],
        )(new Request());
    }

    /** @param list<HealthMetadataProvider> $providers */
    private function controller(array $providers = [], ?string $probeToken = ''): ShowHealthController
    {
        $connection = $this->createStub(Connection::class);

        return new ShowHealthController(
            new CheckDatabaseHealthHandler($connection, new NullLogger()),
            $providers,
            $probeToken,
        );
    }

    private static function probeRequest(string $token): Request
    {
        return new Request(server: ['HTTP_X_PROBE_TOKEN' => $token]);
    }
}
