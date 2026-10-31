<?php

namespace XyloIsCoding\CoconutCms\Tests\Admin;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Admin\Admin;
use XyloIsCoding\CoconutCms\Admin\AdminCode;
use XyloIsCoding\CoconutCms\Admin\BundledTheme;
use XyloIsCoding\CoconutCms\Api\Api;
use XyloIsCoding\CoconutCms\Api\Auth\AnonymousAuthenticator;
use XyloIsCoding\CoconutCms\Api\Auth\CsrfGuard;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Module;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Routing\Request;
use XyloIsCoding\CoconutCms\Routing\RequestMethod;
use XyloIsCoding\CoconutCms\Routing\Router;

/** A token that would break out of a script element, if it weren't escaped. */
final readonly class AdminTestCsrf implements CsrfGuard
{
    public const TOKEN = '</script><b>"x"</b>';

    public function token(): string {
        return self::TOKEN;
    }

    public function isValid(?string $token): bool {
        return $token === self::TOKEN;
    }
}

/** A module with admin code, the build having listed its entry. */
final readonly class AdminTestModule implements Module
{
    public function name(): string {
        return 'admin-test';
    }

    public function classes(): array {
        return [];
    }

    public function steps(): MigrationSteps {
        return MigrationSteps::none();
    }

    public function operations(): array {
        return [];
    }

    public function converters(): array {
        return [];
    }

    public function adminCode(): ?AdminCode {
        return AdminCode::source(__DIR__ . '/admin-test.ts');
    }
}

/** Admin: the page loading the built admin, its plugins, its import map and its config. */
final class AdminTest extends TestCase
{
    private const BUILD = __DIR__ . '/fixtures/build';

    private function api(): Api {
        // Booting touches no database: the connection is never opened.
        $persistence = Persistence::boot(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), [new AdminTestModule()]);

        return Api::boot($persistence, new AnonymousAuthenticator(), csrf: new AdminTestCsrf());
    }

    /** The config the page hands the app. @return array<string, mixed> */
    private static function configOf(string $page): array {
        preg_match('#<script type="application/json" id="coconut-admin-config">(.*?)</script>#', $page, $config);

        return json_decode($config[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testThePageLoadsTheBuiltAdminFromItsManifest(): void {
        $page = Admin::boot($this->api(), self::BUILD, assetsUrl: '/dist/admin', apiUrl: '/backend')->page();

        self::assertStringContainsString('<link rel="stylesheet" href="/dist/admin/assets/admin-C.css">', $page);
        self::assertStringContainsString('<script type="importmap">{"imports":{"@coconut-cms/admin":"/dist/admin/assets/index-B.js"}}</script>', $page);
        self::assertStringContainsString('<script type="module" src="/dist/admin/assets/main-A.js"></script>', $page);
        self::assertStringNotContainsString('light-D.css', $page);
        // The import map comes before the app: a module script resolves names through it.
        self::assertLessThan(strpos($page, 'main-A.js'), strpos($page, 'importmap'));
    }

    public function testTheConfigCarriesTheApiUrlTheTokenEscapedAndThePlugins(): void {
        $page = Admin::boot($this->api(), self::BUILD, apiUrl: '/backend')->page();

        self::assertSame([
            'apiUrl' => '/backend',
            'csrfToken' => AdminTestCsrf::TOKEN,
            'plugins' => [['module' => 'admin-test', 'script' => '/dist/admin/assets/plugin-admin-test-E.js']],
        ], self::configOf($page));
        self::assertStringNotContainsString('<b>', $page);
    }

    public function testAPluginsStylesheetsLoadWithThePageAndAModuleGoneIsLeftOut(): void {
        $page = Admin::boot($this->api(), self::BUILD)->page();

        self::assertStringContainsString('<link rel="stylesheet" href="/dist/admin/assets/plugin-admin-test-F.css">', $page);
        self::assertStringNotContainsString('plugins/gone', $page);
    }

    public function testAThemeLoadsAfterTheBase(): void {
        $bundled = Admin::boot($this->api(), self::BUILD, theme: BundledTheme::Light)->page();
        self::assertLessThan(strpos($bundled, 'light-D.css'), strpos($bundled, 'admin-C.css'));

        $own = Admin::boot($this->api(), self::BUILD, theme: '/css/our-theme.css')->page();
        self::assertStringContainsString('<link rel="stylesheet" href="/css/our-theme.css">', $own);
    }

    public function testAnAdminNotBuiltSaysHowToBuildIt(): void {
        $page = Admin::boot($this->api(), __DIR__ . '/fixtures/missing')->page();

        self::assertStringContainsString('The admin isn\'t built', $page);
        self::assertStringContainsString('build:admin', $page);
    }

    public function testRoutesServeThePageOnGet(): void {
        $router = new Router();
        Admin::boot($this->api(), self::BUILD)->routes($router, prefix: '/panel/');

        self::assertFalse($router->dispatch(Request::of(RequestMethod::POST, '/panel')));

        ob_start();
        self::assertTrue($router->dispatch(Request::of(RequestMethod::GET, '/panel')));
        self::assertStringContainsString('main-A.js', (string) ob_get_clean());
    }
}
