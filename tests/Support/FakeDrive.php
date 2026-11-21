<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A gateway and file system wired to a Guzzle MockHandler, with a valid access
 * token and no Plesk settings, so transport code runs without the network.
 */
final class FakeDrive
{
    public readonly MockHandler $mock;

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<int> */
    public array $sleeps = [];

    public readonly Modules_GdriveBackup_Settings $settings;

    public readonly Modules_GdriveBackup_Drive_Gateway $gateway;

    public readonly Modules_GdriveBackup_Drive_FileSystem $fs;

    /**
     * @param (Closure(int): string)|null $domainName looks up a subscription's domain name
     */
    public function __construct(string $objectType = 'server', ?int $objectId = null, ?Closure $domainName = null)
    {
        $this->mock = new MockHandler();
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $this->requests[] = $request;
            return $request;
        }));
        $http = new GuzzleClient(['handler' => $stack, 'http_errors' => false, 'allow_redirects' => false]);

        $client = new \Google\Client(['client_id' => 'id', 'client_secret' => 'secret']);
        $client->setHttpClient($http);
        $client->setAccessToken(['access_token' => 'test-token', 'expires_in' => 3600, 'created' => time()]);

        // The Plesk API stubs return null for every stored setting.
        $this->settings = new Modules_GdriveBackup_Settings($objectType, $objectId, $domainName);
        $this->configure([]);
        $this->gateway = new Modules_GdriveBackup_Drive_Gateway($this->settings, false);
        $gatewayReflection = new ReflectionClass($this->gateway);
        $gatewayReflection->getProperty('client')->setValue($this->gateway, $client);
        $gatewayReflection->getProperty('http')->setValue($this->gateway, $http);

        $this->fs = new Modules_GdriveBackup_Drive_FileSystem(
            $this->settings,
            $this->gateway,
            null,
            function (int $microseconds): void {
                $this->sleeps[] = $microseconds;
            },
        );
    }

    /**
     * Add saved settings for a server-level backup.
     *
     * @param array<string, string> $config
     */
    public function configure(array $config): void
    {
        $this->settings->updateConfig($config + ['folder' => 'Plesk Backups']);
    }

    public function resolver(bool $persist): Modules_GdriveBackup_Drive_FolderResolver
    {
        return new Modules_GdriveBackup_Drive_FolderResolver($this->gateway, $this->settings, $persist);
    }

    /**
     * Make the file system treat $rootId as the object folder without any requests.
     */
    public function useObjectRoot(string $rootId): void
    {
        $folders = new ReflectionProperty(Modules_GdriveBackup_Drive_FileSystem::class, 'folders')->getValue($this->fs);
        if (!$folders instanceof Modules_GdriveBackup_Drive_FolderResolver) {
            throw new LogicException('The file system has no folder resolver.');
        }
        new ReflectionProperty(Modules_GdriveBackup_Drive_FolderResolver::class, 'cache')->setValue($folders, [
            'base:Plesk Backups' => 'base-1',
            'object:base-1:server' => $rootId,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $files
     */
    public static function fileList(array $files): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['files' => $files], JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $parents
     * @return array<string, mixed>
     */
    public static function folder(string $id, string $name = 'folder', array $parents = []): array
    {
        return ['id' => $id, 'name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => $parents];
    }

    /**
     * @param array<string, mixed> $file
     */
    public static function json(array $file): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($file, JSON_THROW_ON_ERROR));
    }

    /**
     * The decoded query string of request $index.
     */
    public function query(int $index): string
    {
        return urldecode($this->request($index)->getUri()->getQuery());
    }

    public function request(int $index): RequestInterface
    {
        return $this->requests[$index] ?? throw new LogicException('No request #' . $index . ' was sent.');
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }
}
