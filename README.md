<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="Mailtrap for Craft CMS icon"></p>

<h1 align="center">Mailtrap for Craft CMS</h1>

<p align="center">
  <a href="https://github.com/yournextagency/craft-mailtrap/actions/workflows/tests.yml"><img
    src="https://github.com/yournextagency/craft-mailtrap/actions/workflows/tests.yml/badge.svg"
    alt="Tests"></a>
</p>

This plugin provides a [Mailtrap](https://mailtrap.io/) integration for [Craft CMS](https://craftcms.com/).
Mail is sent through the Mailtrap Email API over HTTPS, not SMTP.

## Requirements

This plugin requires Craft CMS 4.0.0+ or 5.0.0+, and PHP 8.0.2+.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “Mailtrap”.
Then click on the “Install” button in its modal window.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require yournextagency/craft-mailtrap

# tell Craft to install the plugin
./craft install/plugin mailtrap
```

## Setup

Once Mailtrap is installed, go to Settings → Email and change the “Transport Type”
setting to “Mailtrap”. Enter the API token for your sending domain and click Save.

Tokens are created per sending domain, and a token needs admin permission on that
domain to send. **The full token is shown only once**, when it is created or reset —
so put it in your `.env` file right away.

| Setting | Required | Description |
| --- | --- | --- |
| **API Token** | Yes | The API token for your sending domain. |
| **Endpoint** | No | Defaults to `https://send.api.mailtrap.io`. Set it to `https://bulk.api.mailtrap.io` to send over the bulk stream. |
| **Inbox ID** | No | When set, mail is delivered to that Mailtrap sandbox inbox instead of to real recipients. |

> **Tip:** All three settings can be set to environment variables. See
> [Environmental Configuration](https://craftcms.com/docs/5.x/configure.html) in the
> Craft docs to learn more about that.

## Sandbox

Set **Inbox ID** to have Craft deliver into a Mailtrap inbox rather than to real
recipients. That is useful on staging, where you want to see the mail your site sends
without anyone receiving it.

Because the setting accepts an environment variable, one plugin configuration covers
every environment:

```bash
# .env on staging — mail lands in inbox 1234567
MAILTRAP_TOKEN=your-token
MAILTRAP_INBOX_ID=1234567

# .env in production — mail is delivered for real
MAILTRAP_TOKEN=your-token
MAILTRAP_INBOX_ID=
```

An unset or empty `MAILTRAP_INBOX_ID` means live sending.

## Trademarks

Mailtrap is a trademark of its owner. This plugin is an unofficial integration and is
not affiliated with, endorsed by, or sponsored by Mailtrap.
