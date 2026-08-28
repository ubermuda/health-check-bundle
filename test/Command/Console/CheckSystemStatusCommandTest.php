<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Test\Command\Console;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Ubermuda\HealthCheckBundle\Test\Functional\HealthCheckTestKernel;

/**
 * Full-stack run: the command resolves the real container handler, so every
 * registered check runs against this instance exactly as a status page would.
 */
final class CheckSystemStatusCommandTest extends KernelTestCase
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

    public function test_it_reports_every_registered_check_with_a_translated_label(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('System status:', $display);
        // The labels are translated, so a raw key on screen means the command
        // rendered the message id instead of the message.
        self::assertStringNotContainsString('check.mailer.label', $display);
        self::assertStringContainsString('Mail transport', $display);
    }

    public function test_an_unknown_result_is_never_silently_counted_as_a_pass(): void
    {
        // The worker check cannot observe a running consumer, so it reports
        // unknown on a test kernel — which the command must call out rather
        // than fold into a green summary.
        $tester = $this->tester();
        $tester->execute([]);

        self::assertStringContainsString('Unknown is not a pass', $tester->getDisplay());
    }

    public function test_a_failing_check_exits_non_zero(): void
    {
        // The kernel's mailer is null://null, which the mail check refuses to
        // call a pass.
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(
            new Application(self::bootKernel())->find('health-check:status'),
        );
    }
}
