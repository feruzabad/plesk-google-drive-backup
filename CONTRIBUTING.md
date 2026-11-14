# Contributing

Thanks for taking the time to contribute.

## Reporting bugs

Open a GitHub issue with:

- Plesk version and panel PHP version (`plesk version`, `/usr/local/psa/admin/bin/php -v`).
- Extension version.
- Steps to reproduce.
- Relevant sanitized log lines from `/var/log/plesk/panel.log`.
- The error message shown in Plesk.

Never include client secrets, refresh tokens, access tokens, or backup contents in an issue.

## Pull requests

1. Fork the repository and create a branch.
2. Install dependencies:

   ```bash
   composer install
   ```

3. Make your change. Keep the existing Plesk class naming conventions and PHP 8.4 type style.
4. Run the checks:

   ```bash
   composer phpstan
   composer test
   composer run build
   ```

5. Update `README.md` and `CHANGES.md` for user-visible changes.
6. Open a pull request describing the change and the testing performed.

## Code style

- PHP 8.4, `declare(strict_types=1)`, typed parameters and returns.
- Plesk extension classes use the `Modules_GdriveBackup_...` naming convention.
- Keep the transport boundary defensive: Plesk can pass numeric values as strings.
- Never log or commit credentials.
- Prefer small, focused pull requests.

## Security

Do not report security vulnerabilities in public issues. See [SECURITY.md](SECURITY.md).

## License

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE).
