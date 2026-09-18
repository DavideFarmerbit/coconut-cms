<?php

namespace XyloIsCoding\CoconutCms\Tests\Routing;

use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Routing\RequestMethod;
use XyloIsCoding\CoconutCms\Routing\Request;

#[BackupGlobals(true)]
final class RequestTest extends TestCase
{
    public function testFromGlobalsReadsMethodPathAndQueryParams(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/products/shoes/42?sort=asc&page=2';
        $_GET = ['sort' => 'asc', 'page' => '2'];

        $request = Request::fromGlobals();

        self::assertSame(RequestMethod::POST, $request->method());
        self::assertSame('/products/shoes/42', $request->url());
        self::assertSame(['sort' => 'asc', 'page' => '2'], $request->urlParams());
    }

    public function testFromGlobalsDefaultsMethodToGetWhenMissing(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        $_SERVER['REQUEST_URI'] = '/';
        $_GET = [];

        self::assertSame(RequestMethod::GET, Request::fromGlobals()->method());
    }

    public function testFromGlobalsDefaultsUrlToRootWhenMissing(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['REQUEST_URI']);
        $_GET = [];

        self::assertSame('/', Request::fromGlobals()->url());
    }
}
