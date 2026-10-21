<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows;

use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\Row;

final class RowTest extends TestCase
{
    public function testEachValueBindsAsItsOwnKind(): void {
        $row = new Row('car', ['flag' => false, 'count' => 3, 'name' => 'mine', 'ratio' => 1.5, 'maker' => null]);

        self::assertSame(
            ['flag' => ParameterType::BOOLEAN, 'count' => ParameterType::INTEGER, 'name' => ParameterType::STRING, 'ratio' => ParameterType::STRING, 'maker' => ParameterType::NULL],
            $row->types(),
        );
    }
}
