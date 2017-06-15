# Toggl → JIRA worklogs

Time tracking in JIRA sucks for various reasons and I'm quite used to Toggl.
But manually filling worklogs in JIRA can be irritating (considering how slow JIRA is).
I prepared script to address those issues and sync Toggl time entries directly to JIRA worklogs.

## What do I have to do?

Deploy this app on Heroku.

[![Deploy](https://www.herokucdn.com/deploy/button.svg)](https://heroku.com/deploy?template=https://github.com/fmasa/toggl-jira/tree/master)

or host the app yourself (PHP 7.1+ and cURL required).

## How can I configure the app?
You can use environment variables to configure app with your Toggl and JIRA credentials (Heroku will ask you for those).

### Environment variables:

- **JIRA_HOST** - JIRA host for your organization (including https://).
- **JIRA_USERNAME** - Your JIRA username.
- **JIRA_PASSWORD** - Your JIRA password.
- **SECURITY_TOKEN** - Token used for app authorization.
- **TOGGL_CLIENT_ID** - Your client ID in Toggl.
- **TOGGL_TOKEN** - Your auth token for Toggl.
- **ERROR_WEBHOOK** (optional) - URL to be called on error.

## How can I sync those entries to JIRA?
Because Toggl can be used for more than just work, every entry you wan't to sync must have **JIRA** tag, name beginning with issue key
and (any) project associated.

Now just call the app: *https://{app_location}?token={SECURITY_TOKEN}* and your time entries will be synced.

If you wan't to automate syncing even more take a look at [IFTT Maker](https://ifttt.com/maker).
