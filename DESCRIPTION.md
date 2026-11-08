# Google Drive Remote Storage

Google Drive Remote Storage is an **open-source (MIT)** Plesk extension that adds Google Drive as a remote backup storage in Plesk Backup Manager.

Features:

- Server-wide Google Drive configuration with your own Google OAuth client.
- Manual server-wide and per-subscription backups.
- Server-wide scheduled backups to Google Drive.
- Streaming resumable uploads with bounded memory use.
- Restore through HTTP range requests.
- MD5 verification and Drive quota reporting.
- Credentials stored encrypted in Plesk's key-value storage.
- Uses the non-sensitive `drive.file` OAuth scope; no public OAuth callback is required.

Limitations:

- Google Drive My Drive only; Workspace Shared Drives are not supported yet.
- Existing user-created folders cannot be selected with `drive.file`; the extension creates and manages its own folder.
- Plesk requires Backup to Cloud Pro for scheduled subscription/customer/reseller backups to remote cloud storage. Server-wide scheduling and manual backups are unaffected.

Project: <https://github.com/feruzabad/plesk-google-drive-backup>

License: MIT.
