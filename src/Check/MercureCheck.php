<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Check;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;
use Ubermuda\HealthCheckBundle\UbermudaHealthCheckBundle;

/**
 * An absent hub degrades real-time delivery rather than breaking the app, so it
 * is a warning, not a failure. Any HTTP answer at all proves a hub is
 * listening; the endpoint legitimately rejects an unauthenticated, topic-less
 * GET, so only a transport-level error means "not there".
 */
final readonly class MercureCheck implements DiagnosticInterface
{
    /**
     * A firewalled hub host must make the page slow, never make it hang. timeout
     * alone does not deliver that: it bounds idle time, so a hub dripping bytes
     * is never idle and never cut off. max_duration bounds the whole exchange.
     */
    private const float PROBE_TIMEOUT_SECONDS = 3.0;
    private const float PROBE_MAX_DURATION_SECONDS = 5.0;

    public function __construct(
        private HttpClientInterface $httpClient,

        #[Autowire('%env(default::MERCURE_URL)%')]
        private ?string $mercureUrl,

        // MERCURE_JWT_SECRET commonly ships without a default, so it must be
        // read through `default::` — resolving it as a plain env var would make
        // this page fatal on exactly the instance that has not set it yet.
        #[Autowire('%env(default::MERCURE_JWT_SECRET)%')]
        private ?string $mercureJwtSecret,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 30;
    }

    #[\Override]
    public function __invoke(): Diagnostic
    {
        if (null === $this->mercureUrl || '' === $this->mercureUrl
            || null === $this->mercureJwtSecret || '' === $this->mercureJwtSecret) {
            return new Diagnostic(
                'mercure',
                DiagnosticState::Warning,
                'check.mercure.unconfigured',
                domain: UbermudaHealthCheckBundle::TRANSLATION_DOMAIN,
            );
        }

        try {
            $statusCode = $this->httpClient
                ->request('GET', $this->mercureUrl, [
                    'timeout' => self::PROBE_TIMEOUT_SECONDS,
                    'max_duration' => self::PROBE_MAX_DURATION_SECONDS,
                ])
                ->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('The configured Mercure hub did not answer.', ['exception' => $e]);

            return new Diagnostic(
                'mercure',
                DiagnosticState::Warning,
                'check.mercure.unreachable',
                domain: UbermudaHealthCheckBundle::TRANSLATION_DOMAIN,
            );
        }

        return new Diagnostic(
            'mercure',
            DiagnosticState::Ok,
            'check.mercure.reachable',
            ['%status%' => (string) $statusCode],
            UbermudaHealthCheckBundle::TRANSLATION_DOMAIN,
        );
    }
}
