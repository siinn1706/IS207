<?php

use App\Support\ValidationFailed;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$json = fn ($data, int $status, array $headers = []) => new JsonResponse(
    $data, $status, $headers, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Stateless JSON API: drop the default api throttle / bindings that change responses.
        $middleware->api(remove: [
            SubstituteBindings::class,
        ]);
        // FastAPI never trimmed or nulled input strings; keep request values verbatim.
        $middleware->remove([
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($json): void {
        $exceptions->dontReport([ValidationFailed::class]);
        // FastAPI error shape: {"detail": ...}
        $exceptions->render(function (ValidationFailed $e) use ($json) {
            return $json(['detail' => $e->errors], 422);
        });
        $exceptions->render(function (NotFoundHttpException $e, Request $r) use ($json) {
            $msg = $e->getMessage();

            return $json(['detail' => ($msg === '' || str_starts_with($msg, 'The route')) ? 'Not Found' : $msg], 404, $e->getHeaders());
        });
        $exceptions->render(function (MethodNotAllowedHttpException $e) use ($json) {
            return $json(['detail' => 'Method Not Allowed'], 405, $e->getHeaders());
        });
        $exceptions->render(function (HttpExceptionInterface $e) use ($json) {
            return $json(['detail' => $e->getMessage()], $e->getStatusCode(), $e->getHeaders());
        });
        $exceptions->render(function (Throwable $e, Request $r) {
            if (config('app.debug')) {
                return null; // Laravel debug page / JSON trace in development
            }

            return response('Internal Server Error', 500, ['Content-Type' => 'text/plain; charset=utf-8']);
        });
    })->create();
