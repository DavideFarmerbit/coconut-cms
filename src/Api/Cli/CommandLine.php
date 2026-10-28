<?php

namespace XyloIsCoding\CoconutCms\Api\Cli;

use JsonException;
use XyloIsCoding\CoconutCms\Api\Api;
use XyloIsCoding\CoconutCms\Api\Error\ErrorCode;
use XyloIsCoding\CoconutCms\Api\Input\InputParameter;
use XyloIsCoding\CoconutCms\Api\Input\InputShape;
use XyloIsCoding\CoconutCms\Api\Output\ForHumans;
use XyloIsCoding\CoconutCms\Persistence\Permission\SystemActor;

/**
 * The API on the command line, what `vendor/bin/coconut` runs:
 *
 *     coconut                                        lists every operation
 *     coconut coconut-cms:entities.get --id=...      runs one
 *     coconut coconut-cms:entities.query --type=shop:car --where.0.path=price \
 *         --where.0.operator=greater --where.0.values.0=100
 *     coconut coconut-cms:entities.query --json='{"type": "shop:car"}'
 *
 * Each `--path=value` sets the input at that dotted path, a number for a
 * list's index; `--json` gives the whole input as JSON instead.
 * `--output=json` prints JSON even for a result shown as a table. Runs as
 * SystemActor: whoever runs it already holds the server.
 */
final readonly class CommandLine
{
    /** Options the command line reads itself, never part of an input. */
    private const JSON = 'json';
    private const OUTPUT = 'output';

    public function __construct(
        private Api $api,
    ) {
    }

    /**
     * Runs what $arguments ask, printing to $out and $err.
     * @param list<string> $arguments Without the script's name.
     * @param resource $out
     * @param resource $err
     * @return int The exit status: 0 on success, the error code's otherwise.
     */
    public function run(array $arguments, mixed $out, mixed $err): int {
        $operation = null;
        $options = [];
        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '--')) {
                if ($operation !== null) {
                    fwrite($err, sprintf('One operation at a time: "%s" follows "%s".%s', $argument, $operation, PHP_EOL));

                    return ErrorCode::InvalidInput->exitStatus();
                }

                $operation = $argument;
                continue;
            }

            [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, 'true');
            $options[$key] = $value;
        }

        if ($operation === null) {
            fwrite($out, $this->listing());

            return 0;
        }

        $asJson = ($options[self::OUTPUT] ?? 'table') === 'json';
        unset($options[self::OUTPUT]);

        if (isset($options[self::JSON])) {
            if (count($options) > 1) {
                fwrite($err, 'Give the input either as --json or as --path=value options, not both.' . PHP_EOL);

                return ErrorCode::InvalidInput->exitStatus();
            }

            try {
                $input = json_decode($options[self::JSON], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $invalid) {
                fwrite($err, sprintf('--json isn\'t JSON: %s.%s', $invalid->getMessage(), PHP_EOL));

                return ErrorCode::InvalidInput->exitStatus();
            }
            $outcome = $this->api->run($operation, $input, new SystemActor());
        } else {
            $outcome = $this->api->run($operation, self::nested($options), new SystemActor(), fromStrings: true);
        }

        if ($outcome->error !== null) {
            fwrite($err, $this->api->json($outcome, pretty: true) . PHP_EOL);

            return $outcome->error->code->exitStatus();
        }

        if ($outcome->result instanceof ForHumans && !$asJson) {
            fwrite($out, $outcome->result->table()->render());
        } else {
            fwrite($out, json_encode($this->api->written($outcome)['result'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
        }

        return 0;
    }

    /** Every operation, with the input it takes. */
    private function listing(): string {
        $lines = ['Operations:'];
        foreach ($this->api->operations() as $definition) {
            $parameters = array_map(
                static fn (InputParameter $parameter): string => $parameter->optional ? '[--' . $parameter->name . ']' : '--' . $parameter->name,
                InputShape::of($definition->input)->parameters,
            );
            $lines[] = '  ' . trim($definition->identifier . ' ' . implode(' ', $parameters));
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * $options set at their dotted paths: "where.0.path" into
     * ['where' => [['path' => ...]]]. An array keyed 0, 1… becomes a list.
     * @param array<string, string> $options
     * @return array<string, mixed>
     */
    private static function nested(array $options): array {
        $input = [];
        foreach ($options as $path => $value) {
            $at = &$input;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($at)) {
                    $at = [];
                }
                $at = &$at[ctype_digit($segment) ? (int) $segment : $segment];
            }
            $at = $value;
            unset($at);
        }

        return self::listed($input);
    }

    /** $value with every array keyed 0, 1… in any order made a list, in index order. */
    private static function listed(mixed $value): mixed {
        if (!is_array($value)) {
            return $value;
        }

        $value = array_map(self::listed(...), $value);
        $keys = array_keys($value);
        sort($keys);
        if ($keys === range(0, count($value) - 1)) {
            ksort($value);

            return array_values($value);
        }

        return $value;
    }
}
