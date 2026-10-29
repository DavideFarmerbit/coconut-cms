<?php

namespace XyloIsCoding\CoconutCms\Admin;

use JsonException;
use RuntimeException;
use XyloIsCoding\CoconutCms\Api\Api;
use XyloIsCoding\CoconutCms\Persistence\Module;
use XyloIsCoding\CoconutCms\Routing\Request;
use XyloIsCoding\CoconutCms\Routing\RequestMethod;
use XyloIsCoding\CoconutCms\Routing\Route;
use XyloIsCoding\CoconutCms\Routing\Router;
use XyloIsCoding\CoconutCms\Utils\AssetTracker;

/**
 * The admin app's page: what a project serves at /admin, next to the API.
 * The page loads the admin a project built into its public folder, with
 * every module's admin code (see the package's build:admin script), hands it
 * the API's URL, the session's CSRF token and its plugins' entries, and maps
 * "@coconut-cms/admin" to the running app's public module:
 *
 *     $admin = Admin::boot($api, buildDir: __DIR__ . '/public/dist/admin');
 *     $admin->routes($router, prefix: '/admin');
 */
final readonly class Admin
{
    /** The app's entry in the admin's Vite manifest. */
    public const ENTRY_APP = 'src/main.ts';

    /** The public module's entry: what "@coconut-cms/admin" resolves to. */
    public const ENTRY_PUBLIC = 'src/public/index.ts';

    /** The base stylesheet's entry. */
    public const ENTRY_STYLES = 'src/styles/admin.scss';

    /** Where the bundled themes' entries are. */
    public const ENTRY_THEMES = 'src/styles/themes/';

    /** The bare name plugins import the public module by. */
    public const PUBLIC_MODULE = '@coconut-cms/admin';

    /** The id of the script element the app reads its config from. */
    public const CONFIG_ELEMENT = 'coconut-admin-config';

    /** Where in the build folder Vite's manifest is. */
    private const MANIFEST = '.vite/manifest.json';

    /** Where in the build folder the build lists the modules' admin code. */
    private const PLUGINS = 'plugins.json';

    /** How JSON is written inside a script element: nothing in it can close the element. */
    private const SCRIPT_JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    private function __construct(
        private Api $api,
        private string $buildDir,
        private string $assetsUrl,
        private AssetTracker $assets,
        private string $apiUrl,
        private string|BundledTheme|null $theme,
    ) {
    }

    /**
     * Wires the page; reads nothing until it's served.
     * @param string $buildDir Where `npm run build:admin` built the admin, on disk.
     * @param string $assetsUrl Where the web server serves that folder.
     * @param string $apiUrl Where the API's routes are mounted.
     * @param string|BundledTheme|null $theme A theme loaded after the base
     *        stylesheet: a bundled one, or a stylesheet's URL; none for the
     *        base alone.
     */
    public static function boot(
        Api $api,
        string $buildDir,
        string $assetsUrl = '/dist/admin/',
        string $apiUrl = '/api',
        string|BundledTheme|null $theme = null,
    ): self {
        $buildDir = rtrim($buildDir, '/');
        $assetsUrl = rtrim($assetsUrl, '/') . '/';

        return new self($api, $buildDir, $assetsUrl, new AssetTracker($buildDir . '/' . self::MANIFEST, $assetsUrl), $apiUrl, $theme);
    }

    /** Registers GET {$prefix} on $router: the page. */
    public function routes(Router $router, string $prefix = '/admin'): void {
        $router->register(Route::simple(
            '/' . trim($prefix, '/'),
            rule: static fn (Request $request): bool => $request->method() === RequestMethod::GET,
            handler: function (): void {
                header('Content-Type: text/html; charset=utf-8');
                // It holds the session's CSRF token: never cached.
                header('Cache-Control: no-store');
                echo $this->page();
            },
        ));
    }

    /** The page's HTML; a page saying how to build the admin when it isn't built. */
    public function page(): string {
        try {
            $head = $this->head();
        } catch (RuntimeException $missing) {
            return self::document(
                '<meta name="robots" content="noindex">',
                sprintf(
                    '<p>The admin isn\'t built: run <code>npm --prefix vendor/xylo-is-coding/coconut-cms run build:admin -- --project "$PWD"</code> in the project.</p><p>%s</p>',
                    self::escaped($missing->getMessage()),
                ),
            );
        }

        return self::document($head, '');
    }

    /** The head's tags: stylesheets, the import map, the config, the app. */
    private function head(): string {
        $plugins = $this->plugins();
        $styles = [$this->assets->url(self::ENTRY_STYLES)];
        if ($this->theme !== null) {
            $styles[] = $this->theme instanceof BundledTheme ? $this->assets->url($this->theme->entry()) : $this->theme;
        }

        // Plugins' stylesheets load with the page, before their scripts run: nothing shows unstyled.
        foreach ($plugins as $plugin) {
            array_push($styles, ...$plugin['css']);
        }

        $tags = array_map(static fn (string $url): string => sprintf('<link rel="stylesheet" href="%s">', self::escaped($url)), $styles);
        $tags[] = sprintf('<script type="importmap">%s</script>', json_encode(['imports' => [self::PUBLIC_MODULE => $this->assets->url(self::ENTRY_PUBLIC)]], self::SCRIPT_JSON));
        $tags[] = sprintf('<script type="application/json" id="%s">%s</script>', self::CONFIG_ELEMENT, json_encode([
            'apiUrl' => $this->apiUrl,
            'csrfToken' => $this->api->csrfToken(),
            'plugins' => array_map(static fn (array $plugin): array => ['module' => $plugin['module'], 'script' => $plugin['script']], $plugins),
        ], self::SCRIPT_JSON));
        $tags[] = sprintf('<script type="module" src="%s"></script>', self::escaped($this->assets->url(self::ENTRY_APP)));

        return '<meta name="robots" content="noindex">' . implode('', $tags);
    }

    /**
     * The plugin entries the build listed, with their URLs, for the modules
     * booted now: a module since removed from the code is left out, even
     * before the admin is built again. None when the build listed none.
     * @return list<array{module: string, script: string, css: list<string>}>
     */
    private function plugins(): array {
        $file = $this->buildDir . '/' . self::PLUGINS;
        if (!is_file($file)) {
            return [];
        }

        try {
            $listed = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new RuntimeException(sprintf('%s isn\'t JSON: build the admin again.', $file), previous: $invalid);
        }

        $booted = array_map(static fn (Module $module): string => $module->name(), $this->api->modules());
        $plugins = [];
        foreach ($listed['plugins'] ?? [] as $plugin) {
            if (in_array($plugin['module'] ?? null, $booted, true)) {
                $plugins[] = [
                    'module' => $plugin['module'],
                    'script' => $this->assetsUrl . $plugin['script'],
                    'css' => array_map(fn (string $css): string => $this->assetsUrl . $css, $plugin['css'] ?? []),
                ];
            }
        }

        return $plugins;
    }

    /** A whole HTML document with $head's tags and $body. */
    private static function document(string $head, string $body): string {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Coconut CMS</title>' . $head . '</head><body>' . $body . '</body></html>';
    }

    /** $text, safe inside HTML text and attributes. */
    private static function escaped(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
