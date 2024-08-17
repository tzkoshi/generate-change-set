<?php

namespace App;

use Monolog\Level;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class CreateTagCommand extends Command
{
    protected static $defaultName = 'create-tag';

    private $logger;

    public function __construct()
    {
        parent::__construct();

        // Set up Monolog logger to output to STDOUT and STDERR
        $this->logger = new Logger('create_tag');
        $this->logger->pushHandler(new StreamHandler('php://stdout', Level::Info));
        $this->logger->pushHandler(new StreamHandler('php://stderr', Level::Error));
    }

    protected function configure()
    {
        $this
            ->setDescription('Automatically creates a new Git tag based on the previous tag or a default one if none is found.')
            ->addArgument('commit', InputArgument::OPTIONAL, 'The commit hash to tag. Defaults to HEAD.')
            ->addOption('origin', 'o', InputOption::VALUE_REQUIRED, 'The remote repository to push the tag to.', 'origin')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'If set, the command will only simulate the tag creation without actually creating it.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $commit = $input->getArgument('commit') ?? $this->getCurrentHeadCommitHash();
        $dryRun = $input->getOption('dry-run');
        $origin = $input->getOption('origin');

        $newTag = $this->generateNextTag();

        if ($newTag) {
            if ($this->tagExists($newTag)) {
                $this->logger->info("Tag $newTag already exists. Skipping tagging.");
            } else {
                $this->createGitTag($origin, $newTag, $commit, $dryRun);
            }
        } else {
            $this->logger->error('Failed to generate a new tag.');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function generateNextTag(): ?string
    {
        // Get the last tag that matches the pattern D.XXXX
        $process = new Process(['git', 'describe', '--tags', '--match', 'D.*', '--abbrev=0']);
        $process->run();

        if ($process->isSuccessful()) {
            $lastTag = trim($process->getOutput());

            // Increment the tag number
            if (preg_match('/^D\.(\d+)$/', $lastTag, $matches)) {
                $nextNumber = (int)$matches[1] + 1;;
                return "D.$nextNumber";
            }
        }

        // If no tag is found or no matching tag, start with D.0001
        return "D.1";
    }

    private function getCurrentHeadCommitHash(): string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD']);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Failed to retrieve the current HEAD commit hash: ' . $process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    private function tagExists(string $tag): bool
    {
        $process = new Process(['git', 'tag', '-l', $tag]);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) === $tag;
    }

    private function createGitTag(string $origin, string $tag, string $commit, bool $dryRun)
    {
        if ($dryRun) {
            $this->logger->info("[Dry Run] Would create tag: $tag on commit $commit");
            return;
        }

        // Create the new Git tag
        $process = new Process(['git', 'tag', $tag, $commit]);
        $process->run();

        if ($process->isSuccessful()) {
            $this->logger->info("Created new tag: $tag on commit $commit");

            // Optionally, push the tag to the remote repository
            $pushProcess = new Process(['git', 'push', $origin, $tag]);
            $pushProcess->run();

            if ($pushProcess->isSuccessful()) {
                $this->logger->info("Pushed tag $tag to remote repository.");
            } else {
                $this->logger->error("Failed to push tag $tag to remote repository: " . $pushProcess->getErrorOutput());
            }
        } else {
            $this->logger->error("Failed to create tag $tag on commit $commit: " . $process->getErrorOutput());
        }
    }
}
