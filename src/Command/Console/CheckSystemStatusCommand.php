<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Command\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\HealthCheckBundle\Command\RunDiagnosticsHandler;
use Ubermuda\HealthCheckBundle\DiagnosticState;

/**
 * The diagnostics report as a command, so a deploy can end with a check rather
 * than with a checklist somebody remembers. Same checks, same wording, same
 * refusal to guess.
 *
 * `unknown` is reported as unknown and never rolled into the exit code: a
 * running messenger worker leaves no lasting trace, so an idle queue proves
 * nothing either way. A green exit that cannot tell "working" from "nothing
 * running" is worse than no check at all.
 */
#[AsCommand(
    name: 'health-check:status',
    description: 'Run the registered infrastructure checks and exit non-zero on failure.',
)]
final class CheckSystemStatusCommand extends Command
{
    public function __construct(
        private readonly RunDiagnosticsHandler $runDiagnostics,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'strict',
            null,
            InputOption::VALUE_NONE,
            'Also exit non-zero when a check reports a warning.',
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $status = ($this->runDiagnostics)();

        if ([] === $status->checks) {
            $io->warning('No diagnostic checks are registered on this instance.');

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($status->checks as $check) {
            $rows[] = [
                strtoupper($check->state->value),
                $this->translator->trans('check.'.$check->key.'.label', [], $check->domain),
                $this->translator->trans($check->detail, $check->detailParameters, $check->domain),
            ];
        }
        $io->table(['State', 'Check', 'Detail'], $rows);

        $unknown = array_filter(
            $status->checks,
            static fn ($check): bool => DiagnosticState::Unknown === $check->state,
        );
        if ([] !== $unknown) {
            $io->note(\sprintf(
                '%d check(s) reported unknown. Unknown is not a pass: nothing was observed either way, so verify those by hand.',
                count($unknown),
            ));
        }

        $strict = true === $input->getOption('strict');
        $failed = DiagnosticState::Failed === $status->overall
            || ($strict && DiagnosticState::Warning === $status->overall);

        if ($failed) {
            $io->error(\sprintf('System status: %s.', $status->overall->value));

            return Command::FAILURE;
        }

        $io->success(\sprintf('System status: %s.', $status->overall->value));

        return Command::SUCCESS;
    }
}
