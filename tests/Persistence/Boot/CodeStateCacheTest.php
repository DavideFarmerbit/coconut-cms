<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Boot;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Boot\CodeStateCache;

final class CodeStateCacheTest extends TestCase
{
    private string $directory;
    private string $watched;

    protected function setUp(): void {
        $this->directory = sys_get_temp_dir() . '/coconut-cms-cache-test-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->watched = $this->directory . '/Car.php';
        file_put_contents($this->watched, '<?php // v1');
    }

    protected function tearDown(): void {
        foreach (glob($this->directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function cache(): CodeStateCache {
        return new CodeStateCache($this->directory);
    }

    public function testWhatWasWrittenIsReadBackWhileNothingChanged(): void {
        $this->cache()->write(['AppModule'], ['key' => 'key-1', 'enums' => ['App\Fuel']], [$this->watched]);

        self::assertSame(['key' => 'key-1', 'enums' => ['App\Fuel']], $this->cache()->read(['AppModule']));
    }

    public function testAWatchedFileChangingInvalidatesIt(): void {
        $this->cache()->write(['AppModule'], ['key' => 'key-1'], [$this->watched]);
        file_put_contents($this->watched, '<?php // v2, longer');
        clearstatcache();

        self::assertNull($this->cache()->read(['AppModule']));
    }

    public function testAWatchedFileGoneInvalidatesIt(): void {
        $this->cache()->write(['AppModule'], ['key' => 'key-1'], [$this->watched]);
        unlink($this->watched);
        clearstatcache();

        self::assertNull($this->cache()->read(['AppModule']));
    }

    public function testAnotherSetOfModulesInvalidatesIt(): void {
        $this->cache()->write(['AppModule'], ['key' => 'key-1'], [$this->watched]);

        self::assertNull($this->cache()->read(['AppModule', 'ShopModule']));
    }

    public function testWithoutADirectoryNothingIsRemembered(): void {
        $cache = new CodeStateCache(null);
        $cache->write(['AppModule'], ['key' => 'key-1'], [$this->watched]);

        self::assertNull($cache->read(['AppModule']));
        self::assertSame([$this->watched], glob($this->directory . '/*'));
    }

    public function testAnUnreadableCacheIsNothing(): void {
        file_put_contents($this->directory . '/coconut-cms-state.json', '{broken');

        self::assertNull($this->cache()->read(['AppModule']));
    }
}
