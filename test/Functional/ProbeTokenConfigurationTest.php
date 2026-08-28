<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Test\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An unconfigured probe token reaches the controller as null, not as the empty
 * string the config default suggests, and it does so at request time: the
 * container compiles clean and the first probe gets the 500.
 */
final class ProbeTokenConfigurationTest extends TestCase
{
    private ?HealthCheckTestKernel $kernel = null;

    private ?string $previousToken = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->previousToken = $_ENV['HEALTH_PROBE_TOKEN'] ?? null;
        unset($_ENV['HEALTH_PROBE_TOKEN'], $_SERVER['HEALTH_PROBE_TOKEN']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;

        if (null !== $this->previousToken) {
            $_ENV['HEALTH_PROBE_TOKEN'] = $this->previousToken;
        }

        parent::tearDown();

        // FrameworkBundle::boot() registers an ErrorHandler exception handler that
        // kernel shutdown does not pop; restore it so PHPUnit does not flag the test risky.
        restore_exception_handler();
    }

    public function test_an_explicit_null_token_still_serves_the_endpoint(): void
    {
        $response = $this->probe(new NullProbeTokenKernel('test', true));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $response->getContent());
    }

    public function test_an_unset_environment_variable_still_serves_the_endpoint(): void
    {
        $response = $this->probe(new EnvProbeTokenKernel('test', true));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $response->getContent());
    }

    public function test_a_blank_environment_variable_still_serves_the_endpoint(): void
    {
        // What a self-hosted `.env` ships: the key is present and empty, which
        // `default::` resolves to null exactly as an absent key does.
        $_ENV['HEALTH_PROBE_TOKEN'] = '';

        $response = $this->probe(new EnvProbeTokenKernel('test', true));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $response->getContent());
    }

    /**
     * The probe presents a header, so a token normalised to anything a caller
     * could guess — null compared loosely, say — would show up as the build
     * field the test kernel's provider contributes.
     */
    private function probe(HealthCheckTestKernel $kernel): Response
    {
        $this->kernel = $kernel;
        $kernel->boot();

        return $kernel->handle(Request::create(
            '/healthz',
            server: ['HTTP_X_PROBE_TOKEN' => 'anything'],
        ));
    }
}
