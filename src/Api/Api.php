<?php

namespace XyloIsCoding\CoconutCms\Api;

use JsonException;
use stdClass;
use Throwable;
use XyloIsCoding\CoconutCms\Api\Auth\Authenticator;
use XyloIsCoding\CoconutCms\Api\Auth\CsrfGuard;
use XyloIsCoding\CoconutCms\Api\Auth\CsrfRejected;
use XyloIsCoding\CoconutCms\Api\Auth\SessionCsrf;
use XyloIsCoding\CoconutCms\Api\Auth\User;
use XyloIsCoding\CoconutCms\Core\Kernel;
use XyloIsCoding\CoconutCms\Api\Error\ApiError;
use XyloIsCoding\CoconutCms\Api\Error\InvalidInput;
use XyloIsCoding\CoconutCms\Api\Error\NotFound;
use XyloIsCoding\CoconutCms\Api\Input\InputReader;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;
use XyloIsCoding\CoconutCms\Api\Operation\OperationDefinition;
use XyloIsCoding\CoconutCms\Api\Operation\OperationRegistry;
use XyloIsCoding\CoconutCms\Api\Output\ResultWriter;
use XyloIsCoding\CoconutCms\Persistence\Module;
use XyloIsCoding\CoconutCms\Persistence\Permission\Actor;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Routing\Request;
use XyloIsCoding\CoconutCms\Routing\RequestMethod;
use XyloIsCoding\CoconutCms\Routing\Route;
use XyloIsCoding\CoconutCms\Routing\Router;

/**
 * The API: every module's operations, served over HTTP and the command line
 * with one contract. Boot it once per request, next to persistence:
 *
 *     $api = Api::boot($persistence, $authenticator, $kernel);
 *     $api->routes($router, prefix: '/api');   // POST /api/{operation}
 *
 * Every call over HTTP sends the session's CSRF token, which the page
 * serving the client gets from csrfToken().
 *
 * run() is what every transport calls: it never throws, every failure comes
 * back as an ApiError.
 */
final class Api
{
    private readonly InputReader $inputs;
    private readonly ResultWriter $results;

    private function __construct(
        private readonly Persistence $persistence,
        private readonly Authenticator $authenticator,
        private readonly Kernel $kernel,
        private readonly CsrfGuard $csrf,
        private readonly OperationRegistry $operations,
        private readonly bool $debug,
    ) {
        $this->inputs = new InputReader();
        $this->results = new ResultWriter();
    }

    /**
     * Registers every operation of $persistence's modules, the core's first,
     * each one checked: a broken one throws LogicException here, not on a
     * call. Touches no database.
     * @param Kernel $kernel The project's services, which operations reach through their context.
     * @param CsrfGuard $csrf Keeps the token every call over HTTP sends.
     * @param bool $debug Whether an internal error tells its message: never in production.
     */
    public static function boot(
        Persistence $persistence,
        Authenticator $authenticator,
        Kernel $kernel = new Kernel(),
        CsrfGuard $csrf = new SessionCsrf(),
        bool $debug = false,
    ): self {
        $operations = new OperationRegistry();
        foreach ($persistence->modules() as $module) {
            foreach ($module->operations() as $class) {
                $operations->register($class);
            }
        }

        return new self($persistence, $authenticator, $kernel, $csrf, $operations, $debug);
    }

    /** The token the page serving a client hands over, for its calls to send in the X-CSRF-Token header. */
    public function csrfToken(): string {
        return $this->csrf->token();
    }

    /** The user $login names, for acting as them: what the command line's `--as` uses. Null when there's none. */
    public function userNamed(string $login): ?User {
        return $this->authenticator->userNamed($login);
    }

    /** @return list<OperationDefinition> Every operation served, sorted by identifier. */
    public function operations(): array {
        return $this->operations->all();
    }

    /** @return list<Module> Every module booted, in order, the core module first. */
    public function modules(): array {
        return $this->persistence->modules();
    }

    /** The operation named $identifier; null when there's none. */
    public function operation(string $identifier): ?OperationDefinition {
        return $this->operations->find($identifier);
    }

    /**
     * Runs the operation named $operation as $actor, its input built from
     * $input: what JSON decoded to, or, with $fromStrings, what the command
     * line gave.
     */
    public function run(string $operation, mixed $input, Actor $actor, bool $fromStrings = false): OperationOutcome {
        try {
            $definition = $this->operations->find($operation) ?? throw new NotFound('operation', $operation);
            $typed = $this->inputs->read($definition->input, $input, $fromStrings);
            $context = new OperationContext($actor, $this->persistence->as($actor), $this->persistence, $this->authenticator, $this->kernel);

            return OperationOutcome::succeeded((new ($definition->class)())->run($typed, $context));
        } catch (Throwable $failure) {
            return OperationOutcome::failed(ApiError::of($failure, $this->debug));
        }
    }

    /**
     * Answers an HTTP call to $operation: its body is the input, as JSON; its
     * caller is who the Authenticator says. Refused unless it sends the
     * session's CSRF token.
     */
    public function handle(Request $request, string $operation): HttpReply {
        if (!$this->csrf->isValid($request->header(CsrfGuard::HEADER))) {
            $outcome = OperationOutcome::failed(ApiError::of(new CsrfRejected(), $this->debug));

            return new HttpReply($outcome->httpStatus(), $this->json($outcome));
        }

        try {
            $input = trim($request->body()) === '' ? [] : json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            $outcome = OperationOutcome::failed(ApiError::of(InvalidInput::at('', sprintf('The body isn\'t JSON: %s.', $invalid->getMessage())), $this->debug));

            return new HttpReply($outcome->httpStatus(), $this->json($outcome));
        }

        $outcome = $this->run($operation, $input, $this->authenticator->actorFor($request));

        return new HttpReply($outcome->httpStatus(), $this->json($outcome));
    }

    /** Registers POST {$prefix}/{operation} on $router. */
    public function routes(Router $router, string $prefix = '/api'): void {
        $router->register(Route::simple(
            rtrim($prefix, '/') . '/{operation}',
            rule: static fn (Request $request): bool => $request->method() === RequestMethod::POST,
            handler: function (Request $request, string $operation): void {
                $this->handle($request, $operation)->send();
            },
        ));
    }

    /**
     * $outcome as plain data: `{"result": ...}` or `{"error": {"code", "message", "details"}}`.
     * @return array<string, mixed>
     */
    public function written(OperationOutcome $outcome): array {
        if ($outcome->error === null) {
            return ['result' => $this->results->write($outcome->result)];
        }

        $details = $this->results->write($outcome->error->details);

        return ['error' => [
            'code' => $outcome->error->code->value,
            'message' => $outcome->error->message,
            'details' => $details === [] ? new stdClass() : $details,
        ]];
    }

    /** $outcome as the JSON an HTTP call answers. */
    public function json(OperationOutcome $outcome, bool $pretty = false): string {
        return json_encode($this->written($outcome), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0));
    }
}
