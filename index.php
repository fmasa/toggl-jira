<?php

use GuzzleHttp\Client;

require_once __DIR__ . '/vendor/autoload.php';

$togglToken = getenv('TOGGL_TOKEN');
$togglClientId = getenv('TOGGL_CLIENT_ID');

$client = new Client([
	'base_uri' => 'https://www.toggl.com/api/v8/',
	'auth' => [$togglToken, 'api_token']
]);

$projectsData = json_decode($client->get('clients/' . $togglClientId . '/projects')->getBody());

$projects = [];
foreach ($projectsData as $project) {
	$projects[$project->id] = $project->name;
}

$projectIds = array_keys($projects);

$entries = json_decode($client->get('time_entries')->getBody());

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

$jiraHost = 'https://egenteam.atlassian.net';

$jiraClient = new Client([
	'base_uri' => $jiraHost.'/rest/api/2/',
	'auth' => [getenv('JIRA_USERNAME'), getenv('JIRA_PASSWORD')],
]);

foreach ($issueEntries as $issueKey => $entries) {
	$issue = json_decode($jiraClient->get('issue/' . $issueKey)->getBody());

	$loggedEntries = [];
	foreach ($issue->fields->worklog->worklogs as $logEntry) {
		if(!isset($logEntry->comment)) {
			continue; // Skip entries without comment
		}

		preg_match('/#([0-9]*)/', $logEntry->comment, $matches);
		if($matches) {
			$loggedEntries[] = (int)$matches[1];
		}
	}

	foreach ($entries as $entry) {
		list($entryId, $duration) = [$entry->id, $entry->duration];

		if(in_array($entryId, $loggedEntries)) {
			// Skip already logged entries
			echo "Entry #$entryId already logged, skipping...<br>";
			continue;
		}

		$jiraClient->post('issue/' . $issueKey . '/worklog', [
			'json' => [
				'timeSpentSeconds' => $duration,
				'comment' => "Autologged from Toggl (#$entryId)"
			]
		]);
		echo "Logged #$entryId in issue <a target='_blank' href='$jiraHost/browse/$issueKey'>$issueKey</a>...<br>";
	}
}