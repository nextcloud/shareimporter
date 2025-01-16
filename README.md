<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Share-Importer

*Share-Importer* is a Nextcloud app which automatically mounts SMB shares for a user at login time. Information about which shares are assigned to a user is provided by an external web service. This web services is not part of the app.

## Method of Operation

The Share-Importer app uses an *Event-Listener* for the *UserLoggedIn* event, to be activated whenever a user logs in. A HTTP request is then sent to the web service to get a list of SMB shares which are assigned to the user. This list is used to mount the shares within Nextcloud so that they are automatically accessible to the user after logging in.

## Installation

The directory *shareimporter* should be placed in the Nextcloud base directory under *apps*. Some changes to the configuration variables are required for the app to work (see below).

## Configuration

Configuration is done in the main Nextcloud configuration file *config.php*.

| Configuration Variable | Description | Type | Default |
|------------------------|-------------|------|---------|
| share_importer_exclude_users | List of users which are ignored by the share importer. | Array | <empty> |
| share_importer_webservice_url | Full URL for accessing the web service. **Required**. | String | <empty> |
| share_importer_webservice_api_key | API key for accessing the web service. **Required**. | String | <empty> |
| share_importer_webservice_verify_certificate | *true* if the TLS certificate of the web service should be verified, otherwise *false* | Boolean | *true* |
| share_importer_webservice_timeout | Timeout in seconds for a reply of the web service. | Integer | 5 |
| share_importer_webservice_connect_timeout | Timeout in seconds for the connection setup to the web service. | Integer | 5 |
| share_importer_auth_mech | Name of the Nextcloud-internal authentication mechanism for the SMB share. The default value "*password:sessioncredentials*" means that the saved login credentials are being used. | String | *password:sessioncredentials* |

## Webservice

The share importer sends a HTTP GET request to the configured URL. The username is passed as an URL-encoded parameter in the following form: `?username=<username>` The API key is sent as an HTTP header named "*ApiKey*".

**Example JSON Response:**

```
{ "username": "testuser", "shares" : [ { "mountpoint": "T:test", "share": "test", "host": "localhost","domain":"WORKGROUP","type":"smb" } ]}
```
