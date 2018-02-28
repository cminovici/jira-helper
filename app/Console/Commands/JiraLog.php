<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;
use Teapot\StatusCode;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\Issue\Worklog;
use JiraRestApi\JiraException;

class JiraLog extends Command
{

    const DEFAULT_WORKDAY_TOTAL_SECONDS = 28800; //8h

    /** @var int $workedTime :in seconds */
    private $workedTime = 0;

    /** @var int $missingTime :in seconds */
    private $missingTime = 0;

    /** @var string $date */
    private $date;

    /** @var string $storyKey */
    private $storyKey;

    /** @var string $issueKey */
    private $issueKey;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature
        = 'jira:log 
            {--d|date= : The date you want to add work log on to.}
            {--s|story= : The story you want to add work log on to.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Log work in Jira';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->date     = $this->option('date') ?? date('Y-m-d');
        $this->storyKey = $this->option('story') ?? '';

        $this->getWorkedTimeForDate();

        if ($this->calculateMissingTime()) {
            // Set current active story from backlog and right sub-task to add work log on
            if ($this->setCurrentStory() && $this->setStoryTask()) {
                // Work log
                $this->addWorkLogIssue();
            }
        } else {
            $text = ">> [$this->date] No need to continue. Log action was skipped.";
            Log::info($text);
            $this->info("$text");
        }
    }

    private function getWorkedTimeForDate(): int
    {
        $timeTrackingHours   = 0;
        $timeTrackingMinutes = 0;

        try {

            /** @var Client $client */
            $client = new Client();
            $res    = $client->request(
                'POST',
                'http://eis.emag.local/jira_log.php',
                [
                    'form_params' => [
                        'username'   => 'catalin.minovici',
                        'submit_day' => 1,
                        'date'       => $this->date
                    ]
                ]
            );

            if (StatusCode::OK == $res->getStatusCode()) {
                $html     = $res->getBody()->getContents();
                $crawler  = new Crawler($html);
                $nodeText = $crawler->filterXPath('//body/div/p[contains(@class, "outerLogInfo_")]')->text();
                if (!empty(trim($nodeText))) {
                    if (preg_match_all('/(\d+)/', $nodeText, $matches)) {
                        list($timeTrackingHours, $timeTrackingMinutes) = current(array_unique($matches, SORT_REGULAR));
                        $text = ">> [$this->date] You already worked $timeTrackingHours hours and $timeTrackingMinutes minutes";
                        Log::info($text);
                        $this->info($text);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::critical(
                $e->getMessage(),
                [
                    'trace' => $e->getTraceAsString()
                ]
            );
            $this->error("{$e->getMessage()}: {$e->getTraceAsString()}");
        }

        return $this->workedTime = $this->convertTimeToSeconds($timeTrackingHours, $timeTrackingMinutes);
    }

    private function setCurrentStory(): ?string
    {
        if (!empty($this->storyKey)) {
            return $this->storyKey;
        }

        $jql = 'project = SCM AND status = Open AND cf[10840] = SCM-25028 AND text ~ "SCM-Δ" AND sprint in openSprints ()';
        try {
            $issueService = new IssueService();
            /** @var \JiraRestApi\Issue\IssueSearchResult $result */
            $result = $issueService->search($jql);
            if (!empty($issues = $result->getIssues())) {
                $currentStory = current($issues);
                $text         = sprintf(
                    '>> [%2$s] Current active story %1$s (http://jira.emag.network:8080/browse/%1$s)',
                    $currentStory->key,
                    $this->date
                );
                Log::info($text);
                $this->info($text);

                return $this->storyKey = $currentStory->key;
            }
        } catch (JiraException $e) {
            Log::critical(
                $e->getMessage(),
                [
                    'trace' => $e->getTraceAsString()
                ]
            );
            $this->error("{$e->getMessage()}: {$e->getTraceAsString()}");
        }

        return null;
    }

    private function setStoryTask(): ?string
    {
        if (!empty($this->issueKey)) {
            return $this->issueKey;
        }

        $jql = "parent = $this->storyKey and assignee = currentUser()";
        try {
            $issueService = new IssueService();
            /** @var \JiraRestApi\Issue\IssueSearchResult $result */
            $result = $issueService->search($jql);
            if (!empty($issues = $result->getIssues())) {
                $task = current($issues);
                $text = sprintf('>> [%2$s] Current sub-task to be used %1$s (http://jira.emag.network:8080/browse/%1$s)', $task->key, $this->date);
                Log::info($text);
                $this->info($text);

                return $this->issueKey = $task->key;
            }
        } catch (JiraException $e) {
            Log::critical(
                $e->getMessage(),
                [
                    'trace' => $e->getTraceAsString()
                ]
            );
            $this->error("{$e->getMessage()}: {$e->getTraceAsString()}");
        }

        return null;
    }

    private function addWorkLogIssue(): ?int
    {
        try {
            $workLog = new Worklog();
            $workLog->setStarted($this->date);
            $workLog->setTimeSpentSeconds($this->missingTime);

            $issueService = new IssueService();
            /** @var Worklog $result */
            $result = $issueService->addWorklog($this->issueKey, $workLog);
            $text   = sprintf(
                '>> [%s] Successfully added work log (id:%s) on %s: {Missing time was: %s seconds}',
                $this->date,
                $result->id,
                $this->issueKey,
                $this->missingTime
            );
            Log::info($text);
            $this->info($text);

            return $result->id;
        } catch (JiraException $e) {
            Log::critical(
                $e->getMessage(),
                [
                    'trace' => $e->getTraceAsString()
                ]
            );
            $this->error("{$e->getMessage()}: {$e->getTraceAsString()}");
        }

        return null;
    }

    private function convertTimeToSeconds(int $hours, int $minutes): int
    {
        return $this->workedTime = $hours * 3600 + $minutes * 60;
    }

    private function calculateMissingTime(): int
    {
        return $this->missingTime = self::DEFAULT_WORKDAY_TOTAL_SECONDS - $this->workedTime;
    }
}