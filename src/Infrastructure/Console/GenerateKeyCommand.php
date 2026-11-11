<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Infrastructure\Console;

use Override;
use Phil\StandardWebhooks\Ed25519SecretKey;
use Phil\StandardWebhooks\HmacSecret;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *     bin/console phil:standard-webhooks:generate-key        # v1a: a secret key and its public key
 *     bin/console phil:standard-webhooks:generate-key v1     # v1: a shared secret.
 *
 * Prints new keys once, for a secret store: nothing is kept.
 */
#[AsCommand(name: 'phil:standard-webhooks:generate-key', description: 'Generates a Standard Webhooks key')]
final class GenerateKeyCommand extends Command
{
    #[Override]
    protected function configure(): void
    {
        $this->addArgument('scheme', InputArgument::OPTIONAL, 'v1a (Ed25519) or v1 (HMAC-SHA256)', 'v1a');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        switch ($input->getArgument('scheme')) {
            case 'v1a':
                $key = Ed25519SecretKey::generate();
                $output->writeln('Secret key, for the sender only: '.$key->toString());
                $output->writeln('Public key, for the receivers: '.$key->publicKey()->toString());

                return Command::SUCCESS;

            case 'v1':
                $output->writeln('Shared secret, for the sender and one receiver: '.HmacSecret::generate()->toString());

                return Command::SUCCESS;

            default:
                $output->writeln('<error>The scheme is v1a or v1.</error>');

                return Command::INVALID;
        }
    }
}
