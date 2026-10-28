<?php

namespace XyloIsCoding\CoconutCms\Routing;

final readonly class Request
{
    private function __construct(
        private RequestMethod $method,
        private string $url,
        private array $urlParams,
        private string $body,
    ) {
    }

    public static function fromGlobals(): self {
        return new self(
            RequestMethod::from($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            self::pathFromGlobals(),
            $_GET,
            (string) file_get_contents('php://input'),
        );
    }

    /** A request built by hand: for tests, and for code calling a route itself. */
    public static function of(RequestMethod $method, string $url, array $urlParams = [], string $body = ''): self {
        return new self($method, $url, $urlParams, $body);
    }

    /*================================================================================================================*/
    // Request Interface

    public function method(): RequestMethod {
        return $this->method;
    }

    public function url(): string {
        return $this->url;
    }

    public function urlParams(): array {
        return $this->urlParams;
    }

    /** The raw body, as sent: "" when there's none. */
    public function body(): string {
        return $this->body;
    }

    // ~Request Interface
    /*================================================================================================================*/

    private static function pathFromGlobals(): string {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return parse_url($uri, PHP_URL_PATH) ?? '/';
    }
}
