<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Controller;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Ubermuda\HealthCheckBundle\Command\CheckDatabaseHealthCommand;
use Ubermuda\HealthCheckBundle\Command\CheckDatabaseHealthHandler;
use Ubermuda\HealthCheckBundle\HealthMetadataProvider;

/**
 * Liveness endpoint for load balancers and uptime probes: it answers "can this
 * container serve a request that reaches the database", nothing more.
 *
 * Unauthenticated on purpose — a load balancer has no credentials — which is
 * why the body is a fixed two-state string. Everything an operator would find
 * useful here is something an anonymous caller must not learn: the failing
 * exception (its message carries the database host and user), whether any
 * account exists yet. Those belong on the authenticated diagnostics page.
 *
 * Anything more the endpoint reports is contributed by a HealthMetadataProvider
 * and is sensitive unless the provider says otherwise; sensitive fields reach
 * only a caller presenting the configured probe token. With no token
 * configured — the default a self-hosted instance inherits — they never appear
 * at all.
 */
#[AsController]
final class ShowHealthController
{
    /**
     * `null` is the same answer as `''`: no token is configured, so sensitive
     * fields never appear. It arrives that way from
     * `%env(default::HEALTH_PROBE_TOKEN)%`, which resolves an unset or blank
     * variable to null at runtime rather than at compile time.
     */
    private readonly string $probeToken;

    /** @param iterable<HealthMetadataProvider> $metadataProviders */
    public function __construct(
        private readonly CheckDatabaseHealthHandler $checkDatabaseHealth,

        #[AutowireIterator('ubermuda_health_check.metadata')]
        private readonly iterable $metadataProviders,
        ?string $probeToken = null,
    ) {
        $this->probeToken = $probeToken ?? '';
    }

    public function __invoke(Request $request): JsonResponse
    {
        $healthy = ($this->checkDatabaseHealth)(new CheckDatabaseHealthCommand())->healthy;

        $payload = ['status' => $healthy ? 'ok' : 'error'];

        // Header, never a query parameter: those are recorded in access logs
        // and forwarded in Referer. hash_equals because the comparison is
        // against a secret.
        $trusted = '' !== $this->probeToken
            && hash_equals($this->probeToken, (string) $request->headers->get('X-Probe-Token', ''));

        $response = new JsonResponse(
            $this->withMetadata($payload, $trusted),
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
        // A cached health check reports the state of a container that may since
        // have died, which is the one thing a probe must never do.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Every provider is asked and every contributed key is validated, whoever
     * is calling, so a provider that breaks the contract breaks the endpoint
     * for everybody rather than only for the one caller holding the token.
     *
     * @param array<string, string|int|bool|null> $payload
     *
     * @return array<string, string|int|bool|null>
     */
    private function withMetadata(array $payload, bool $trusted): array
    {
        $contributed = ['status' => true];

        foreach ($this->metadataProviders as $provider) {
            foreach ($provider->fields() as $key => $value) {
                if ('status' === $key) {
                    throw new \LogicException(\sprintf('"%s" contributed a "status" field. That key is the health endpoint\'s own verdict and cannot be contributed.', $provider::class));
                }

                if (isset($contributed[$key])) {
                    throw new \LogicException(\sprintf('"%s" contributed a "%s" field another provider had already contributed.', $provider::class, $key));
                }
                $contributed[$key] = true;

                if (null === $value || ($provider->sensitive() && !$trusted)) {
                    continue;
                }

                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
