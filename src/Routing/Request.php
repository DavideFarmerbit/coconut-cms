<?php

namespace XyloIsCoding\CoconutCms\Routing;

final readonly class Request
{
    /** @param array<string, string> $headers By lowercase name. */
    private function __construct(
        private RequestMethod $method,
        private string $url,
        private array $urlParams,
        private string $body,
        private array $headers,
    ) {
    }

    public static function fromGlobals(): self {
        return new self(
            RequestMethod::from($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            self::pathFromGlobals(),
            $_GET,
            (string) file_get_contents('php://input'),
            self::headersFromGlobals(),
        );
    }

    /**
     * A request built by hand: for tests, and for code calling a route itself.
     * @param array<string, string> $headers By name, in any case.
     */
    public static function of(RequestMethod $method, string $url, array $urlParams = [], string $body = '', array $headers = []): self {
        return new self($method, $url, $urlParams, $body, array_change_key_case($headers, CASE_LOWER));
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

    /** The header named $name, in any case; null when it wasn't sent. */
    public function header(string $name): ?string {
        return $this->headers[strtolower($name)] ?? null;
    }

    // ~Request Interface
    /*================================================================================================================*/

    /**
     * The request's headers, from what PHP puts in $_SERVER: "HTTP_X_CSRF_TOKEN"
     * is "x-csrf-token".
     * @return array<string, string>
     */
    private static function headersFromGlobals(): array {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with((string) $key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
            }
        }

        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $key) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $headers[strtolower(str_replace('_', '-', $key))] = $_SERVER[$key];
            }
        }

        return $headers;
    }

    private static function pathFromGlobals(): string {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return parse_url($uri, PHP_URL_PATH) ?? '/';
    }
}
