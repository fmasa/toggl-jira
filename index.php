<?php

use GuzzleHttp\Client;

error_reporting(E_ALL);

ini_set('display_errors', 1);
set_time_limit(300);
date_default_timezone_set('Europe/Prague');

require_once __DIR__ . '/vendor/autoload.php';

if(is_file(__DIR__ . "/.env")) { // Dev
	(new \Dotenv\Dotenv(__DIR__))->load();
}

if(!isset($_GET['token']) || $_GET['token'] !== getenv('SECURITY_TOKEN')) {
	http_response_code(401);
	echo 'Unauthorized';
	exit;
}

$togglToken = getenv('TOGGL_TOKEN');
$togglClientId = getenv('TOGGL_CLIENT_ID');

(new class {

	/** @var Client */
	private $toggl;

	/** @var string */
	private $togglClientId;

	/** @var Client */
	private $jira;

	/**
	 *  constructor.
	 */
	public function __construct()
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
	}

	private function getDate(int $daysBack) : string
	{
		$dt = new DateTime();
		if($daysBack > 0) {
			$dt->modify('-' . $daysBack . 'days');
		}
		return $dt->format('c');
	}

	private function getTogglIssueEntries(int $daysBack)
	{
		$options = [];

		if($daysBack !== 0) {
			$options["query"] = [
				'start_date' => $this->getDate($daysBack),
				'end_date' => $this->getDate($daysBack - 14),
			];
		}

		$body = $this->toggl->get('time_entries', $options)->getBody();
		$entries = json_decode($body);

		$issueEntries = [];

		foreach ($entries as $entry) {
			$description = $entry->description ?? '(no description)';
			$duration = $entry->duration;
			$projectId = $entry->pid ?? NULL;

			if ($duration < 0 // Entry still running
				|| $projectId === NULL // No project filled
				|| !isset($entry->tags) || !in_array('JIRA', $entry->tags) // Only 'JIRA' tagged issues
			) { // Different project
				continue;
			}

			preg_match('#([A-Z]{2,3}-[0-9]*) #', $description, $matches);

			if ($matches) {
				$issueKey = $matches[1];
				$issueEntries[$issueKey] = array_merge($issueEntries[$issueKey] ?? [], [$entry]);
			}
		}
		return $issueEntries;
	}

	private function logEntries(array $issueEntries)
	{
		foreach ($issueEntries as $issueKey => $entries) {
			try {
				$issue = json_decode($this->jira->get("issue/$issueKey/worklog")->getBody());
			} catch (\GuzzleHttp\Exception\ClientException $e) {
				if ($e->getCode() == 404) {
					echo "Issue $issueKey not found.";
					continue;
				} else {
					throw $e;
				}
			}

			$loggedEntries = [];
			foreach ($issue->worklogs as $logEntry) {
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

				$comment = ($entry->description ?? '') . " (Toggl #$entryId)";
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
	 * @param string|NULL $errorWebhook
	 * @param int $daysBack specifies logging interval
	 * @throws Exception
	 */
	public function sync(string $errorWebhook, int $daysBack)
	{
		try {
			$this->logEntries($this->getTogglIssueEntries($daysBack));
		} catch(\Exception $e) {
			if($errorWebhook) {
				file_get_contents($errorWebhook);
			}
			throw $e;
		}
	}

})->sync(getenv('ERROR_WEBHOOK'), (int) ($_GET['days_back'] ?? 0));
