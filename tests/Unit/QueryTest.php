<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class QueryTest extends TestCase
{
    public function testEscapesQuotesAndBackslashes(): void
    {
        $this->assertSame("a\\'b", Modules_GdriveBackup_Drive_Query::escapeValue("a'b"));
        $this->assertSame('a\\\\b', Modules_GdriveBackup_Drive_Query::escapeValue('a\\b'));
    }

    public function testBuildsNameAndParentClauses(): void
    {
        $this->assertSame("name = 'a\\'b'", Modules_GdriveBackup_Drive_Query::name("a'b"));
        $this->assertSame("'root' in parents", Modules_GdriveBackup_Drive_Query::parent('root'));
    }

    public function testBuildsAppPropertyClause(): void
    {
        $this->assertSame(
            "appProperties has { key='pleskRoot' and value='abc' }",
            Modules_GdriveBackup_Drive_Query::appProperty('pleskRoot', 'abc')
        );
    }
}
