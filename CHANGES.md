# Unreleased

* The settings form now asks for the whole token response from Google (for example, the JSON shown by the OAuth 2.0 Playground) instead of the refresh token alone, and takes the `refresh_token` from it. Pasted HTTP headers are ignored, and a bare refresh token is still accepted.

# 1.2.0

* Removed the host name level from the Drive layout: backups are now stored in `<folder>/server`, `<folder>/subscription-<id>`, and so on. Backups under the old `<folder>/<host name>/` level are not moved; move those folders up in Google Drive to keep them visible in Plesk.
* Fixed restores: downloads now send a valid `Range` header, resume after an interrupted response without duplicating data, and fail instead of returning a short read.
* Network errors during uploads and downloads are now retried.
* Uploads ask Google Drive how much it received before resending a chunk, so a retry neither duplicates nor drops data.
* Finishing an upload is safe to retry: the final chunk is kept until Drive confirms it, and a failed rename does not resend data.
* Leftover temporary upload files older than one hour are removed after a successful upload.
* Folder lookups are limited to the expected parent folder, settle on the oldest folder when duplicates exist, and remember folders created during a backup.

# 1.1.3

* Updated Guzzle to 8.x with PHPStan max-level compatibility.

# 1.1.2

* Normalized Plesk string offsets and read sizes at the transport boundary.
* Open-sourced under the MIT license with GitHub Actions CI and release workflows.

# 1.1.1

* Fixed Plesk object ID normalization for the strict typed Settings constructor.

# 1.1.0

* Raised the runtime requirement to PHP 8.4 and modernized the code with typed properties, enums, readonly/asymmetric visibility, property hooks, and PHP 8.4 array helpers.
* Added PHPStan at maximum level (`phpstan.neon`).
* Removed the Google account email from remote storage display labels.

# 1.0.3

* Removed the Google account email from remote storage display labels.

# 1.0.2

* Documented per-subscription activation and Plesk scheduling caveats.

# 1.0.1

* Improved token error messages for invalid or revoked Google refresh tokens.
* Replaced the placeholder screenshot with a live Plesk form screenshot.

# 1.0.0

* Initial release.
* Adds a Google Drive remote storage transport for Plesk Backup Manager.
* Uses the `drive.file` OAuth scope and an app-managed folder.
* Streamed resumable uploads, range-based restores, MD5 verification, and Drive quota reporting.
* Server-wide configuration with read-only inheritance for manual subscription backups.
