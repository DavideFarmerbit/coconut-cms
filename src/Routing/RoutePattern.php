<?php

namespace XyloIsCoding\CoconutCms\Routing;

final readonly class RoutePattern
{
    private string $pattern;
    
    private string $regex;

    /** @var string[] */
    private array $paramNames;

    public function __construct(
        string $pattern,
    ) {
        $this->pattern = $pattern;
        [$this->regex, $this->paramNames] = self::compile($this->pattern);
    }

    /*================================================================================================================*/
    // RoutePattern Interface
    
    public function pattern(): string
    {
        return $this->pattern;
    }

    /** @return string[] */
    public function paramNames(): array
    {
        return $this->paramNames;
    }

    /**
     * @return array<string, string>|null
     */
    public function match(string $path): ?array
    {
        if (preg_match($this->regex, $path, $matches) !== 1) {
            return null;
        }

        $captures = [];
        foreach ($this->paramNames as $name) {
            $captures[$name] = $matches[$name];
        }

        return $captures;
    }

    // ~RoutePattern Interface
    /*================================================================================================================*/

    /**
     * @return array{0: string, 1: string[]}
     */
    private static function compile(string $pattern): array
    {
        $paramNames = [];

        $regex = preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $matches) use (&$paramNames): string {
                $paramNames[] = $matches[1];
                return sprintf('(?P<%s>[^/]+)', $matches[1]);
            },
            $pattern,
        );

        return ['#^' . $regex . '$#', $paramNames];
    }
}
