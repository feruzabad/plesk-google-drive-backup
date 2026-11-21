<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ObjectTypeTest extends TestCase
{
    public function testAcceptsTheObjectTypesThePanelUses(): void
    {
        $this->assertSame(Modules_GdriveBackup_Drive_ObjectType::Server, Modules_GdriveBackup_Drive_ObjectType::fromPlesk('server'));
        $this->assertSame(Modules_GdriveBackup_Drive_ObjectType::Subscription, Modules_GdriveBackup_Drive_ObjectType::fromPlesk('subscription'));
        $this->assertSame(Modules_GdriveBackup_Drive_ObjectType::Customer, Modules_GdriveBackup_Drive_ObjectType::fromPlesk('customer'));
        $this->assertSame(Modules_GdriveBackup_Drive_ObjectType::Reseller, Modules_GdriveBackup_Drive_ObjectType::fromPlesk('reseller'));
    }

    public function testAcceptsTheObjectTypesTheCommandLineUses(): void
    {
        // plesk bin pleskbackup -output-file ext://gdrive-backup/{server|reseller|client|domain}/<id>/
        $this->assertSame(Modules_GdriveBackup_Drive_ObjectType::Subscription, Modules_GdriveBackup_Drive_ObjectType::fromPlesk('domain'));
        $this->assertSame(Modules_GdriveBackup_Drive_ObjectType::Customer, Modules_GdriveBackup_Drive_ObjectType::fromPlesk('client'));
    }

    public function testCommandLineTypesUseTheSameFolderAsThePanel(): void
    {
        $this->assertSame('subscription-33', Modules_GdriveBackup_Drive_ObjectType::fromPlesk('domain')->objectKey(33));
        $this->assertSame('customer-5', Modules_GdriveBackup_Drive_ObjectType::fromPlesk('client')->objectKey(5));
    }

    public function testRejectsUnknownObjectTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Modules_GdriveBackup_Drive_ObjectType::fromPlesk('mailbox');
    }
}
