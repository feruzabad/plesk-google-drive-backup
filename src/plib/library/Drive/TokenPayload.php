<?php

declare(strict_types=1);

/**
 * Extracts the refresh token from what the administrator pastes into the
 * settings form: the JSON token response from Google (as shown by the OAuth
 * Playground, optionally with the HTTP headers around it) or a bare token.
 */
final class Modules_GdriveBackup_Drive_TokenPayload
{
    public const int INVALID_JSON = 1;
    public const int MISSING_REFRESH_TOKEN = 2;
    public const int ACCESS_TOKEN = 3;

    private function __construct()
    {
    }

    /**
     * Returns the refresh token, or an empty string when the input is blank.
     *
     * @throws InvalidArgumentException with one of the class constants as code
     */
    public static function refreshToken(#[\SensitiveParameter] string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }

        $start = strpos($input, '{');
        if ($start === false) {
            if (str_starts_with($input, 'ya29.')) {
                throw new InvalidArgumentException('An access token was given instead of a refresh token.', self::ACCESS_TOKEN);
            }
            return $input;
        }

        $end = strrpos($input, '}');
        /** @var mixed $payload */
        $payload = $end === false || $end < $start
            ? null
            : json_decode(substr($input, $start, $end - $start + 1), true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('The token response is not valid JSON.', self::INVALID_JSON);
        }

        $refreshToken = $payload['refresh_token'] ?? null;
        if (!is_string($refreshToken) || trim($refreshToken) === '') {
            throw new InvalidArgumentException('The token response has no refresh_token.', self::MISSING_REFRESH_TOKEN);
        }

        return trim($refreshToken);
    }
}
