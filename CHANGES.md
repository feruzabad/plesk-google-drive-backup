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
