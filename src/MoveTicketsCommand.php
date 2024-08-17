<?php

namespace App;

use JiraRestApi\Configuration\ArrayConfiguration;
use JiraRestApi\Issue\Comment;
use JiraRestApi\Issue\IssueField;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\Issue\Transition;
use JiraRestApi\JiraException;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class MoveTicketsCommand extends Command
{
    protected static $defaultName = 'move-tickets';

    private $logger;

    public function __construct()
    {
        parent::__construct();

        // Set up Monolog logger
        $this->logger = new Logger('move_tickets');
        $this->logger->pushHandler(new StreamHandler(STDOUT, Level::Info));
    }

    protected function configure()
    {
        $this
            ->setDescription('Moves Jira tickets mentioned in commits to "To Test" status')
            ->addArgument('start-hash', InputArgument::OPTIONAL, 'The start commit hash')
            ->addArgument('end-hash', InputArgument::OPTIONAL, 'The end commit hash')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'If set, the task will only simulate the moves without changing any ticket statuses.')
            ->addOption('jira-url', null, InputOption::VALUE_REQUIRED, 'The Jira base URL')
            ->addOption('jira-user', null, InputOption::VALUE_REQUIRED, 'The Jira username')
            ->addOption('jira-password', null, InputOption::VALUE_REQUIRED, 'The Jira password');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $startHash = $input->getArgument('start-hash') ?? $this->getLastTagCommitHash();
        $endHash = $input->getArgument('end-hash') ?? $this->getCurrentHeadCommitHash();
        $dryRun = $input->getOption('dry-run');

        // Get the Git tag for the end-hash
        $gitTag = $this->getGitTagForCommit($endHash);

        // Get commit messages between two hashes
        $commits = $this->getCommitsBetweenHashes($startHash, $endHash);

        // Extract Jira tickets and simulate/move them
        $tickets = $this->extractTicketsFromCommits($commits);
        $this->moveTicketsToTest($tickets, $gitTag, $input, $dryRun);

        return Command::SUCCESS;
    }

    private function getLastTagCommitHash(): string
    {
        $process = new Process(['git', 'describe', '--tags', '--match', 'D.*', '--abbrev=0']);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Failed to retrieve the last tag: ' . $process->getErrorOutput());
        }

        $tag = trim($process->getOutput());

        // Retrieve the commit hash for the last tag
        $process = new Process(['git', 'rev-list', '-n', '1', $tag]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Failed to retrieve the commit hash for tag ' . $tag . ': ' . $process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    private function getCurrentHeadCommitHash(): string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD']);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Failed to retrieve the current HEAD commit hash: ' . $process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    private function getGitTagForCommit(string $commitHash): ?string
    {
        $process = new Process(['git', 'describe', '--tags', '--exact-match', $commitHash]);
        $process->run();

        if ($process->isSuccessful()) {
            return trim($process->getOutput());
        }

        // No tag found for the commit
        return null;
    }

    private function getCommitsBetweenHashes(string $startHash, string $endHash): array
    {
        $process = new Process(['git', 'log', '--pretty=format:%H%n%B', "$startHash..$endHash"]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }

        $commits = explode("\n", $process->getOutput());

        return $commits;
    }

    private function extractTicketsFromCommits(array $commits): array
    {
        $tickets = [];

        foreach ($commits as $commit) {
            if (preg_match_all('/INV-\d+/', $commit, $matches)) {
                $tickets = array_merge($tickets, $matches[0]);
            }
        }

        return array_unique($tickets);
    }

    private function moveTicketsToTest(array $tickets, ?string $gitTag, InputInterface $input, bool $dryRun): void
    {
        $issueService = new IssueService(new ArrayConfiguration([
            'jiraHost'     => $input->getOption('jira-url'),
            'jiraUser'     => $input->getOption('jira-user'),
            'jiraPassword' => $input->getOption('jira-password'),
        ]));

        foreach ($tickets as $ticket) {
            try {
                $issue = $issueService->get($ticket);
                $ticketUrl = trim($input->getOption('jira-url'), '/') . "/browse/" . $ticket;
                $statusMessage = "Ticket: $ticket (Current status: " . $issue->fields->status->name . ") URL: $ticketUrl";
                if ($this->shouldSkipChangeForStatus($issue->fields->status->name)) {
                    $this->logger->info("Skipping $statusMessage");
                    continue;
                }

                if ($dryRun) {
                    $this->logger->info("[Dry Run] $statusMessage");
                    if ($gitTag) {
                        $this->logger->info("[Dry Run] Would add Git tag $gitTag as a label to ticket $ticket.");
                    }
                } else {
                    // Assuming "To Test" status has a specific transition ID, replace '31' with the actual transition ID
                    $transition = new Transition();
                    $transition->setTransitionName("TO TEST");

                    $issueService->transition($ticket, $transition);
                    if ($gitTag) {
                        $this->addLabelToTicket($ticket, $gitTag);
                    }
                    $this->addCommentToTicket($issueService, $ticket, $gitTag);

                    $this->logger->info("Moved $ticket to 'To Test' status. URL: $ticketUrl");
                }
            } catch (JiraException $e) {
                $this->logger->error("Failed to move ticket $ticket: " . $e->getMessage());
            }
        }
    }

    private function addLabelToTicket(string $ticket, string $label)
    {
        try {
            $issueService = new IssueService();

            // Fetch the current issue
            $issue = $issueService->get($ticket);

            // Get current labels and add the new one
            $labels = $issue->fields->labels ?? [];
            if (!in_array($label, $labels)) {
                $labels[] = $label;

                // Update the issue with the new label
                $issueField = new IssueField(true);
                $issueField->labels[] = $labels;
                $issueService->update($ticket, $issueField);

                $this->logger->info("Added Git tag $label as a label to ticket $ticket.");
            }
        } catch (JiraException $e) {
            $this->logger->error("Failed to add label $label to ticket $ticket: " . $e->getMessage());
        }
    }

    private function addCommentToTicket(IssueService $issueService, string $ticket, ?string $gitTag)
    {
        $comment = new Comment();

        $commentBody = "This issue has been moved to the \"To Test\" status by an automated deployment tool.\n\n";
        if ($gitTag) {
            $commentBody .= "Release tag: $gitTag\n\n";
        }
        $commentBody .= "Please review the changes and proceed with the testing phase.";

        $comment->setBody($commentBody);

        try {
            $issueService->addComment($ticket, $comment);
        } catch (JiraException $e) {
            $this->logger->error("Failed to add comment to ticket $ticket: " . $e->getMessage());
        }
    }


    private function shouldSkipChangeForStatus(string $status)
    {
        return in_array($status, ['To Test', 'Done', 'Closed']);

    }
}
