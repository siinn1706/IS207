<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Http
{
    /** raise HTTPException(status, detail) */
    public static function fail(int $status, string $detail, array $headers = []): never
    {
        throw new HttpException($status, $detail, null, $headers);
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        if ($data === null) {
            return JsonResponse::fromJsonString('null', $status, $headers);
        }

        return new JsonResponse($data, $status, $headers, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function noContent(): Response
    {
        return response('', 204, ['Content-Type' => 'application/json']);
    }

    /** FastAPI path parameter declared as int. */
    public static function pathInt(string $value, string $name): int
    {
        if (! preg_match('/^\s*[+-]?\d+\s*$/', $value)) {
            throw new ValidationFailed([[
                'type' => 'int_parsing', 'loc' => ['path', $name],
                'msg' => 'Input should be a valid integer, unable to parse string as an integer', 'input' => $value,
            ]]);
        }

        return (int) $value;
    }

    /** Paginate like search_service.paginate_query. */
    public static function paginate($query, ?int $page, ?int $pageSize, callable $map): mixed
    {
        if ($page === null && $pageSize === null) {
            return array_map($map, $query->get()->all());
        }
        $page = $page ?: 1;
        $pageSize = min($pageSize ?: 20, 200);
        $total = (clone $query)->count();
        $rows = $query->offset(($page - 1) * $pageSize)->limit($pageSize)->get()->all();

        return ['data' => array_map($map, $rows), 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
    }

    public static function likeEscape(string $q): string
    {
        return '%'.$q.'%';
    }
}
