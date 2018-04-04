<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JiraRestApi\Issue\IssueField;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\JiraException;

class UpdateIssue extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature
        = 'jira:update-issue 
            {--i|issue= : The issue you want to update.}
            {--use_jql : Use jql script to identify issue(s).}
            {--jql_script_path= : JQL script path.}
            {--custom_field_id= : If you want to change a custom field set custom field\'s id.}
            {--use_template : If template should be used.}
            {--template_path= : Template path. By default, path is relative to storage/app directory}';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update issue';

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
        if (!empty($issueKey = $this->option('issue'))) {
            $this->updateIssue($issueKey);
        } elseif (true === $this->option('use_jql') && !empty($this->option('jql_script_path'))) {
            $issues = $this->findIssuesFromJQL();
            if (!empty($issues)) {
                foreach ($issues as $issueKey => $issueDetails) {
                    $this->updateIssue($issueKey);
                }
            } else {
                $this->comment("No issues were updated!");
            }
        }
    }

    private function updateIssue($issueKey)
    {

        $fieldId = $this->option('custom_field_id');

        $templatePath = $this->option('template_path');
        $template     = Storage::disk('local')->get($templatePath);

        if ($issueKey) {
            try {
                $issueField = new IssueField(true);
                $issueField->addCustomField($fieldId, $template);

                $issueService = new IssueService();
                $result       = $issueService->update($issueKey, $issueField);

                $text = sprintf(
                    '>> Successfully updated story %1$s (http://jira.emag.network:8080/browse/%1$s)',
                    $issueKey
                );
                Log::info($text);
                $this->info($text);

            } catch (JiraException $e) {
                Log::critical(
                    $e->getMessage(),
                    [
                        'trace' => $e->getTraceAsString()
                    ]
                );
                $this->error("{$e->getMessage()}: {$e->getTraceAsString()}");
            }
        }
    }

    private function findIssuesFromJQL(): ?array
    {
        $issueKeys     = [];
        $jqlScriptPath = $this->option('jql_script_path');
        $jqlScript     = Storage::disk('local')->get($jqlScriptPath);

        try {
            $issueService = new IssueService();
            /** @var \JiraRestApi\Issue\IssueSearchResult $result */
            $result = $issueService->search($jqlScript);
            if (!empty($issues = $result->getIssues())) {
                $outputTableHeader = ['Issue Key', 'Summary', 'Sprint'];
                foreach ($issues as $issue) {
                    preg_match('/(?<=name=)(.*)(?=,startDate)/', $issue->fields->customfield_10440[0], $matches);
                    $issueKeys[$issue->key] = [$issue->key, $issue->fields->summary, $matches[0]];
                }
                $this->table($outputTableHeader, $issueKeys);
                if ($this->confirm('All these issues will be updated. Do you wish to continue?')) {
                    return $issueKeys;
                }
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
}