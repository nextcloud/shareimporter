# Share-Importer

Der Share-Importer ist eine Nextcloud-App, die automatisiert SMB-Shares eines Benutzers einbindet. Die Shares, die einem Benutzer zugeordnet sind, werden durch einen externen Webservice geliefert, der nicht Bestandteil der App ist.

## Arbeitsweise

Die Share-Importer-App nutzt einen *Event-Listener* für das *UserLoggedIn*\-Event, um bei jeder Anmeldung eines Benutzers aufgerufen zu werden. Dabei wird eine HTTP-Request an den Webservice gestellt, um zu dem Benutzernamen eine Liste von SMB-Shares zu erhalten. Mit dieser Liste wird dann ein oder mehrere SMB-Mounts innerhalb von Nextcloud ausgeführt. Damit sind die dem Benutzer zugeordneten Shares nach dem Login automatisch verfügbar.

## Installation

Das Verzeichnis *shareimporter* muss im Nextcloud-Basisverzeichnis unter *apps* abgelegt werden. Einige Konfigurationsvariablen müssen zwingend gesetzt werden (siehe unten).

## Konfiguration

Die Konfiguration wird in der Nextcloud-Konfigurationsdatei *config.php* vorgenommen.

| Konfigurationsvariable | Beschreibung | Typ | Default |
|------------------------|--------------|-----|---------|
| share_importer_exclude_users | Liste von Benutzern, für die vom Share-Importer ignoriert werden | Array | <leer> |
| share_importer_webservice_url | vollständige URL für den Zugriff auf den Webservice, muss gesetzt sein | String | <leer> |
| share_importer_webservice_api_key | API-Key für den Zugriff auf den Webservice, muss gesetzt sein | String | <leer> |
| share_importer_webservice_verify_certificate | *true*, wenn das Server-Zertifikat des Webservice überprüft werden soll, sonst *false* | Boolean | *true* |
| share_importer_webservice_timeout | Timeout in Sekunden für die Antwort vom Webservice | Integer | 5 |
| share_importer_webservice_connect_timeout | Timeout in Sekunden für den Verbindungsaufbau zum Webservice | Integer | 5 |
| share_importer_auth_mech | Name des Nextcloud-internen Authentifizierungsmechanismus für das SMB-Share. Mit dem Default-Wert "*password:sessioncredentials*" wird das Nextcloud-Anmeldepasswort" durchgereicht. | String | *password:sessioncredentials* |

## Webservice

Der Shareimporter führt eine HTTP-GET-Request auf die konfigurierte URL aus.  Als URL-encoded-Parameter wird der Benutzername in der Form `?username=<username>` mitgegeben. Der API-Key wird in dem HTTP-Header "*ApiKey*" übertragen.

**Beispiel JSON-Antwort:**

```
{ "username": "testuser", "shares" : [ { "mountpoint": "T:test", "share": "test", "host": "localhost","domain":"WORKGROUP","type":"smb" } ]}
```
