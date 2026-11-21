<?php

declare(strict_types=1);

enum Modules_GdriveBackup_Drive_ObjectType: string
{
    case Server = 'server';
    case Subscription = 'subscription';
    case Customer = 'customer';
    case Reseller = 'reseller';

    /**
     * Accepts the panel's object types and the command line's names for them
     * ("domain" and "client" in ext://<extension>/<type>/<id>/ URLs).
     *
     * @throws InvalidArgumentException
     */
    public static function fromPlesk(string $objectType): self
    {
        return match ($objectType) {
            'domain' => self::Subscription,
            'client' => self::Customer,
            default => self::tryFrom($objectType),
        } ?? throw new InvalidArgumentException(sprintf('Unsupported Plesk object type "%s".', $objectType));
    }

    public function objectKey(?int $objectId): string
    {
        return match ($this) {
            self::Server => 'server',
            self::Subscription => 'subscription-' . (int)$objectId,
            self::Customer => 'customer-' . (int)$objectId,
            self::Reseller => 'reseller-' . (int)$objectId,
        };
    }
}
