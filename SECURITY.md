# Security Policy

## Supported versions

The latest 1.x release is supported with security fixes.

## Reporting a vulnerability

Please do **not** open a public issue for a security vulnerability.

Use GitHub's private vulnerability reporting:

1. Open the repository's **Security** tab.
2. Choose **Advisories** → **Report a vulnerability**.

Include:

- A description of the issue and its impact.
- Steps to reproduce or a proof of concept.
- Affected extension version, Plesk version, and panel PHP version.
- Any suggested mitigation.

Never include real client secrets, refresh tokens, access tokens, or customer backup data. Use redacted examples.

## Credential handling

The extension stores the Google client secret, refresh token, and cached access token encrypted in Plesk's key-value storage. The account email is stored in the extension configuration but is not displayed in the UI.

If a credential is exposed:

1. Revoke the Google OAuth access at <https://myaccount.google.com/permissions>.
2. Generate a new refresh token.
3. Save the new credentials in Plesk Remote Storage Settings.
4. Review Plesk and web-server logs for unexpected API activity.
