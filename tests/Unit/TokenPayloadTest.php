<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class TokenPayloadTest extends TestCase
{
    private const string PAYLOAD = <<<'JSON'
        {
          "access_token": "ya29.a0AfH6SMBexample",
          "expires_in": 3599,
          "refresh_token": "1//0gExampleRefreshToken",
          "scope": "https://www.googleapis.com/auth/drive.file",
          "token_type": "Bearer",
          "refresh_token_expires_in": 604799
        }
        JSON;

    public function testExtractsRefreshTokenFromJsonPayload(): void
    {
        $this->assertSame('1//0gExampleRefreshToken', Modules_GdriveBackup_Drive_TokenPayload::refreshToken(self::PAYLOAD));
    }

    public function testIgnoresHttpHeadersAroundThePayload(): void
    {
        $input = "HTTP/1.1 200 OK\nContent-type: application/json; charset=utf-8\n\n" . self::PAYLOAD . "\n";

        $this->assertSame('1//0gExampleRefreshToken', Modules_GdriveBackup_Drive_TokenPayload::refreshToken($input));
    }

    public function testAcceptsPayloadWithNewlinesStripped(): void
    {
        $input = str_replace("\n", '', self::PAYLOAD);

        $this->assertSame('1//0gExampleRefreshToken', Modules_GdriveBackup_Drive_TokenPayload::refreshToken($input));
    }

    public function testAcceptsBareRefreshToken(): void
    {
        $this->assertSame('1//0gBare', Modules_GdriveBackup_Drive_TokenPayload::refreshToken("  1//0gBare \n"));
    }

    public function testEmptyInputMeansKeepExisting(): void
    {
        $this->assertSame('', Modules_GdriveBackup_Drive_TokenPayload::refreshToken(" \n "));
    }

    public function testRejectsBareAccessToken(): void
    {
        $this->expectExceptionCode(Modules_GdriveBackup_Drive_TokenPayload::ACCESS_TOKEN);
        Modules_GdriveBackup_Drive_TokenPayload::refreshToken('ya29.a0AfH6SMBexample');
    }

    public function testRejectsPayloadWithoutRefreshToken(): void
    {
        $this->expectExceptionCode(Modules_GdriveBackup_Drive_TokenPayload::MISSING_REFRESH_TOKEN);
        Modules_GdriveBackup_Drive_TokenPayload::refreshToken('{"access_token": "ya29.x", "expires_in": 3599}');
    }

    public function testRejectsBrokenJson(): void
    {
        $this->expectExceptionCode(Modules_GdriveBackup_Drive_TokenPayload::INVALID_JSON);
        Modules_GdriveBackup_Drive_TokenPayload::refreshToken('{"refresh_token": "1//0g"');
    }

    public function testRejectsErrorPayload(): void
    {
        $this->expectExceptionCode(Modules_GdriveBackup_Drive_TokenPayload::MISSING_REFRESH_TOKEN);
        Modules_GdriveBackup_Drive_TokenPayload::refreshToken('{"error": "invalid_grant", "error_description": "Bad Request"}');
    }
}
