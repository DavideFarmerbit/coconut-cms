<?php

namespace XyloIsCoding\CoconutCms\Admin;

use RuntimeException;
use XyloIsCoding\CoconutCms\Api\Api;
use XyloIsCoding\CoconutCms\Routing\Request;
use XyloIsCoding\CoconutCms\Routing\RequestMethod;
use XyloIsCoding\CoconutCms\Routing\Route;
use XyloIsCoding\CoconutCms\Routing\Router;
use XyloIsCoding\CoconutCms\Utils\AssetTracker;

/**
 * The admin app's page: what a project serves at /admin, next to the API.
 * The page loads the admin a project built into its public folder (see
 * the package's build:admin script), hands it the API's URL and the
 * session's CSRF token, and maps "@coconut-cms/admin" to the running app's
 * public module for plugins:
 *
 *     $admin = Admin::boot($api, manifest: __DIR__ . '/public/dist/admin/.vite/manifest.json');
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

    /** How JSON is written inside a script element: nothing in it can close the element. */
    private const SCRIPT_JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    private function __construct(
        private Api $api,
        private AssetTracker $assets,
        private string $apiUrl,
        private string|BundledTheme|null $theme,
    ) {
    }

    /**
     * Wires the page; reads nothing until it's served.
     * @param string $manifest The built admin's Vite manifest, on disk.
     * @param string $assetsUrl Where the web server serves the built admin.
     * @param string $apiUrl Where the API's routes are mounted.
     * @param string|BundledTheme|null $theme A theme loaded after the base
     *        stylesheet: a bundled one, or a stylesheet's URL; none for the
     *        base alone.
     */
    public static function boot(
        Api $api,
        string $manifest,
        string $assetsUrl = '/dist/admin/',
        string $apiUrl = '/api',
        string|BundledTheme|null $theme = null,
    ): self {
        return new self($api, new AssetTracker($manifest, rtrim($assetsUrl, '/') . '/'), $apiUrl, $theme);
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
                    '<p>The admin isn\'t built: run <code>npm --prefix vendor/xylo-is-coding/coconut-cms run build:admin -- --outDir "$PWD/public/dist/admin"</code> in the project.</p><p>%s</p>',
                    self::escaped($missing->getMessage()),
                ),
            );
        }

        return self::document($head, '');
    }

    /** The head's tags: stylesheets, the import map, the config, the app. */
    private function head(): string {
        $styles = [$this->assets->url(self::ENTRY_STYLES)];
        if ($this->theme !== null) {
            $styles[] = $this->theme instanceof BundledTheme ? $this->assets->url($this->theme->entry()) : $this->theme;
        }

        $tags = array_map(static fn (string $url): string => sprintf('<link rel="stylesheet" href="%s">', self::escaped($url)), $styles);
        $tags[] = sprintf('<script type="importmap">%s</script>', json_encode(['imports' => [self::PUBLIC_MODULE => $this->assets->url(self::ENTRY_PUBLIC)]], self::SCRIPT_JSON));
        $tags[] = sprintf(
            '<script type="application/json" id="%s">%s</script>',
            self::CONFIG_ELEMENT,
            json_encode(['apiUrl' => $this->apiUrl, 'csrfToken' => $this->api->csrfToken()], self::SCRIPT_JSON),
        );
        $tags[] = sprintf('<script type="module" src="%s"></script>', self::escaped($this->assets->url(self::ENTRY_APP)));

        return '<meta name="robots" content="noindex">' . implode('', $tags);
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
