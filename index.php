<?php

use GuzzleHttp\Client;

set_time_limit(300);
date_default_timezone_set('Europe/Prague');

if(!isset($_GET['token']) || $_GET['token'] != getenv('SECURITY_TOKEN')) {
	http_response_code(401);
	echo 'Unauthorized';
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

$togglToken = getenv('TOGGL_TOKEN');
$togglClientId = getenv('TOGGL_CLIENT_ID');

(new class {

	/** @var Client */
	private $toggl;

	/** @var string */
	private $togglClientId;

	/** @var Client */
	private $jira;

	/** @var string|NULL */
	private $errorWebhook;

	/**
	 *  constructor.
	 * @param string|NULL $errorWebhook
	 */
	public function __construct($errorWebhook)
	{
		$togglToken = getenv('TOGGL_TOKEN');
		$togglClientId = getenv('TOGGL_CLIENT_ID');

		$jiraHost = getenv('JIRA_HOST');
		$jiraUsername = getenv('JIRA_USERNAME');
		$jiraPassword = getenv('JIRA_PASSWORD');

		$this->toggl = new Client([
			'base_uri' => 'https://www.toggl.com/api/v8/',
			'auth' => [$togglToken, 'api_token']
		]);
		$this->togglClientId = $togglClientId;

		$this->jira = new Client([
			'base_uri' => $jiraHost.'/rest/api/2/',
			'auth' => [$jiraUsername, $jiraPassword],
		]);

		$this->errorWebhook = getenv('ERROR_WEBHOOK');
	}

	private function getTogglIssueEntries()
	{
		$projectsData = json_decode($this->toggl->get('clients/' . $this->togglClientId . '/projects')->getBody());

		$projects = [];
		foreach ($projectsData as $project) {
			$projects[$project->id] = $project->name;
		}

		$projectIds = array_keys($projects);

		$entries = json_decode($this->toggl->get('time_entries')->getBody());

		$issueEntries = [];

		foreach ($entries as $entry) {
			$description = isset($entry->description) ? $entry->description : '(no description)';
			$duration = $entry->duration;
			$projectId = isset($entry->pid) ? $entry->pid : NULL;

			if ($duration < 0 // Entry still running
				|| $projectId === NULL // No project filled
				|| !in_array($entry->pid, $projectIds)
				|| !isset($entry->tags) || !in_array('JIRA', $entry->tags) // Only 'JIRA' tagged issues
			) { // Different project
				continue;
			}

			preg_match('#([A-Z]{2,3}-[0-9]*) #', $description, $matches);

			if ($matches) {
				$issueKey = $matches[1];
				$issueEntries[$issueKey] = array_merge(
					isset($issueEntries[$issueKey]) ? $issueEntries[$issueKey] : [],
					[$entry]
				);
			}
		}
		return $issueEntries;
	}

	private function logEntries(array $issueEntries)
	{
		foreach ($issueEntries as $issueKey => $entries) {
			try {
				$issue = json_decode($this->jira->get('issue/' . $issueKey)->getBody());
			} catch (\GuzzleHttp\Exception\ClientException $e) {
				if ($e->getCode() == 404) {
					echo "Issue $issueKey not found.";
					continue;
				} else {
					throw $e;
				}
			}

			$loggedEntries = [];
			foreach ($issue->fields->worklog->worklogs as $logEntry) {
				if (!isset($logEntry->comment)) {
					continue; // Skip entries without comment
				}

				preg_match('/#([0-9]*)/', $logEntry->comment, $matches);
				if ($matches) {
					$loggedEntries[] = (int)$matches[1];
				}
			}

			foreach ($entries as $entry) {
				list($entryId, $duration, $started) = [$entry->id, $entry->duration, $entry->start];

				if ($duration < 60) {
					echo "Entry below one minute, skipping...<br>";
					continue;
				}

				if (in_array($entryId, $loggedEntries)) {
					// Skip already logged entries
					echo "Entry #$entryId already logged, skipping...<br>";
					continue;
				}

				$comment = (isset($entry->description) ? $entry->description : '') . " (Toggl #$entryId)";
				$comment = trim($comment);


				$this->jira->post('issue/' . $issueKey . '/worklog', [
					'json' => [
						'timeSpentSeconds' => $duration,
						'comment' => $comment,
						'started' => DateTime::createFromFormat('Y-m-d\TH:i:sP', $started)->format('Y-m-d\TH:i:s.000O')
					]
				]);
				$host = $this->jira->getConfig('base_uri');
				echo "Logged #$entryId in issue <a target='_blank' href='$host/browse/$issueKey'>$issueKey</a>...<br>";
			}
		}
	}

	/**
	 * Sync Toggl entries with JIRA worklogs
	 * @throws Exception
	 */
	public function sync()
	{
		try {
			$this->logEntries($this->getTogglIssueEntries());
		} catch(\Exception $e) {
			if($this->errorWebhook) {
				file_get_contents($this->errorWebhook);
			}
			throw $e;
		}
	}

})->sync();
