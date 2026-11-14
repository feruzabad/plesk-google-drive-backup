<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
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

    public function __construct()
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

        $this->settings = new ReflectionClass(Modules_GdriveBackup_Settings::class)->newInstanceWithoutConstructor();
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

    public function request(int $index): RequestInterface
    {
        return $this->requests[$index] ?? throw new LogicException('No request #' . $index . ' was sent.');
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }
}
