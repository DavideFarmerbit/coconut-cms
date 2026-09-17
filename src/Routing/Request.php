<?php

namespace XyloIsCoding\CoconutCms\Routing;

final readonly class Request
{
    private function __construct(
        private RequestMethod $method,
        private string $url,
        private array $urlParams,
    
    ) {
        
    }

    public static function fromGlobals(): self {
        return new self(
            RequestMethod::from($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            self::pathFromGlobals(),
            $_GET,
        );
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

    // ~Request Interface
    /*================================================================================================================*/

    private static function pathFromGlobals(): string {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return parse_url($uri, PHP_URL_PATH) ?? '/';
    }
}