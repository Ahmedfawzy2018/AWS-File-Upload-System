<?php

use App\Domain\Files\Exceptions\DomainError;
use App\Http\Middleware\AssignRequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e): bool => $request->is('api/*', 'health*') || $request->expectsJson()
        );

        $envelope = static fn (string $code, string $message, int $status, array $details = []): JsonResponse => response()->json([
            'error' => ['code' => $code, 'message' => $message, 'details' => (object) $details],
        ], $status);

        $exceptions->render(function (Throwable $e, Request $request) use ($envelope): ?JsonResponse {
            if (! ($request->is('api/*', 'health*') || $request->expectsJson())) {
                return null;
            }

            return match (true) {
                $e instanceof DomainError => $envelope($e->errorCode(), $e->getMessage(), $e->httpStatus(), $e->details()),
                $e instanceof ValidationException => $envelope('VALIDATION_FAILED', $e->getMessage(), 422, $e->errors()),
                $e instanceof AuthenticationException => $envelope('UNAUTHENTICATED', 'Unauthenticated.', 401),
                $e instanceof AuthorizationException,
                $e instanceof AccessDeniedHttpException,
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => $envelope('NOT_FOUND', 'Not found.', 404),
                $e instanceof ThrottleRequestsException,
                $e instanceof TooManyRequestsHttpException => $envelope('RATE_LIMITED', 'Too many requests.', 429),
                $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => $envelope(
                    'HTTP_ERROR',
                    HttpResponse::$statusTexts[$e->getStatusCode()] ?? 'Client error',
                    $e->getStatusCode(),
                ),
                default => $envelope(
                    'SERVER_ERROR',
                    config('app.debug') ? $e->getMessage() : 'Server error',
                    $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500,
                ),
            };
        });
    })->create();
