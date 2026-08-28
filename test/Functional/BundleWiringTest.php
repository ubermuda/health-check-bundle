<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Test\Functional;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\HealthCheckBundle\Check\FailedMessagesCheck;
use Ubermuda\HealthCheckBundle\Check\MailerSenderCheck;
use Ubermuda\HealthCheckBundle\Check\MailerTransportCheck;
use Ubermuda\HealthCheckBundle\Check\MercureCheck;
use Ubermuda\HealthCheckBundle\Check\WorkerCheck;
use Ubermuda\HealthCheckBundle\Command\Console\CheckSystemStatusCommand;
use Ubermuda\HealthCheckBundle\Command\RunDiagnosticsHandler;
use Ubermuda\HealthCheckBundle\Controller\ShowHealthController;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticState;
use Ubermuda\HealthCheckBundle\UbermudaHealthCheckBundle;

final class BundleWiringTest extends KernelTestCase
{
    #[\Override]
    protected static function getKernelClass(): string
    {
        return HealthCheckTestKernel::class;
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();

        // FrameworkBundle::boot() registers an ErrorHandler exception handler that
        // kernel shutdown does not pop; restore it so PHPUnit does not flag the test risky.
        restore_exception_handler();
    }

    public function test_the_shipped_checks_are_collected_by_tag_in_priority_order(): void
    {
        self::bootKernel();

        $checks = self::getContainer()->get(RunDiagnosticsHandler::class);
        self::assertInstanceOf(RunDiagnosticsHandler::class, $checks);

        self::assertSame(
            ['mailer', 'mailer_sender', 'worker', 'failed_messages', 'mercure'],
            array_map(static fn (Diagnostic $check): string => $check->key, $checks()->checks),
        );
    }

    public function test_every_shipped_check_declares_its_priority(): void
    {
        $priorities = array_map(
            static fn (string $class): int => $class::priority(),
            [
                MailerTransportCheck::class,
                MailerSenderCheck::class,
                WorkerCheck::class,
                FailedMessagesCheck::class,
                MercureCheck::class,
            ],
        );

        self::assertSame($priorities, array_values(array_unique($priorities)));
        self::assertSame($priorities, [70, 60, 50, 40, 30]);
    }

    public function test_the_health_route_is_mounted_at_the_configured_path(): void
    {
        self::bootKernel();

        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $route = $router->getRouteCollection()->get('ubermuda_health_check');
        self::assertNotNull($route);
        self::assertSame('/healthz', $route->getPath());
    }

    public function test_the_configured_probe_token_reaches_the_controller(): void
    {
        self::bootKernel();

        $controller = self::getContainer()->get(ShowHealthController::class);
        self::assertInstanceOf(ShowHealthController::class, $controller);

        // The kernel configures "right-token"; nothing else proves the config
        // key is bound to the controller argument at all.
        $withToken = $controller(new Request(server: ['HTTP_X_PROBE_TOKEN' => 'right-token']));
        self::assertJsonStringEqualsJsonString('{"status":"ok","build":"test"}', (string) $withToken->getContent());

        $anonymous = $controller(new Request());
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $anonymous->getContent());
    }

    public function test_a_test_container_can_replace_the_private_diagnostics_handler(): void
    {
        // The handler stays private: an application's WebTestCase swaps it out
        // through the test container rather than redeclaring it public, and the
        // services that depend on it get the replacement.
        self::bootKernel();

        $replacement = new RunDiagnosticsHandler([]);
        self::getContainer()->set(RunDiagnosticsHandler::class, $replacement);

        self::assertSame($replacement, self::getContainer()->get(RunDiagnosticsHandler::class));

        $command = self::getContainer()->get(CheckSystemStatusCommand::class);
        self::assertInstanceOf(CheckSystemStatusCommand::class, $command);
        self::assertSame(
            $replacement,
            (new \ReflectionProperty(CheckSystemStatusCommand::class, 'runDiagnostics'))->getValue($command),
        );
    }

    public function test_every_key_the_shipped_checks_return_has_an_english_message(): void
    {
        self::bootKernel();

        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);

        $domain = UbermudaHealthCheckBundle::TRANSLATION_DOMAIN;
        $catalogue = $translator->getCatalogue('en')->all($domain);

        foreach (self::detailKeys() as $key) {
            self::assertArrayHasKey($key, $catalogue, \sprintf('Missing "%s" in the %s catalogue.', $key, $domain));
        }

        foreach (['mailer', 'mailer_sender', 'worker', 'failed_messages', 'mercure'] as $check) {
            self::assertArrayHasKey('check.'.$check.'.label', $catalogue);
        }

        // Through the accessors, so the domain a consumer's template is told to
        // use is the one the catalogue is actually registered under.
        foreach (DiagnosticState::cases() as $state) {
            self::assertSame($domain, $state->translationDomain());
            self::assertArrayHasKey($state->translationKey(), $catalogue);
        }
    }

    /**
     * Reads the keys out of the sources rather than restating them, so a check
     * that grows an outcome fails this test until the message exists.
     *
     * @return list<string>
     */
    private static function detailKeys(): array
    {
        $keys = [];
        foreach (glob(__DIR__.'/../../src/Check/*.php') ?: [] as $file) {
            preg_match_all("/'(check\\.[a-z_.]+)'/", (string) file_get_contents($file), $matches);
            $keys = [...$keys, ...$matches[1]];
        }

        self::assertNotEmpty($keys);

        return array_values(array_unique($keys));
    }
}
