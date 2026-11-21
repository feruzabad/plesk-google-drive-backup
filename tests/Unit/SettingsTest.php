<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    public function testNamesTheSubscriptionFolderAfterTheDomain(): void
    {
        $settings = new Modules_GdriveBackup_Settings('subscription', 33, static fn(int $id): string => 'example.com');

        $this->assertSame('example.com', $settings->getObjectFolderName());
        $this->assertSame('subscription-33', $settings->getObjectKey());
    }

    public function testFallsBackToTheObjectKeyWhenTheDomainIsUnknown(): void
    {
        $settings = new Modules_GdriveBackup_Settings('subscription', 33, static function (int $id): string {
            throw new RuntimeException('Domain not found.');
        });

        $this->assertSame('subscription-33', $settings->getObjectFolderName());
    }

    public function testFallsBackToTheObjectKeyForAnUnusableDomainName(): void
    {
        $settings = new Modules_GdriveBackup_Settings('subscription', 33, static fn(int $id): string => ' ');

        $this->assertSame('subscription-33', $settings->getObjectFolderName());
    }

    public function testKeepsTheObjectKeyForOtherObjectTypes(): void
    {
        $lookup = static fn(int $id): string => throw new LogicException('Only subscriptions have a domain.');

        $this->assertSame('server', new Modules_GdriveBackup_Settings('server', null, $lookup)->getObjectFolderName());
        $this->assertSame('customer-5', new Modules_GdriveBackup_Settings('customer', 5, $lookup)->getObjectFolderName());
        $this->assertSame('reseller-7', new Modules_GdriveBackup_Settings('reseller', 7, $lookup)->getObjectFolderName());
    }

    public function testShowsTheDomainFolderInTheDisplayPath(): void
    {
        $settings = new Modules_GdriveBackup_Settings('subscription', 33, static fn(int $id): string => 'example.com');
        $settings->updateConfig(['folder' => 'Plesk Backups']);

        $this->assertSame('Plesk Backups/example.com', $settings->getDisplayPath());
    }
}
