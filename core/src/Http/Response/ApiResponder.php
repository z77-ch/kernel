<?php

namespace Z77\Core\Http\Response;

use Z77\Shared\Api\ApiRequest,
    Z77\Shared\Api\ApiResult
;

/**
 * Maps an {@see ApiResult} onto the wire per api-envelope-v1: the ONE place
 * that knows the API's status codes, headers, and error body shape.
 *
 *   payload + client's If-None-Match names the ETag ({@see Etag}) → 304, empty body
 *   payload                                           → 200, ETag when set
 *   error                                             → status + frozen error
 *                                                       body, Retry-After when set
 *
 * A payload's own headers ({@see ApiResult::$headers}) ride on 200 and 304
 * alike — the service says what it means, the responder only carries it.
 * `Cache-Control` and `ETag` are set AFTER them, so a service can never
 * override the two the envelope owns.
 *
 * Every response carries `Cache-Control: no-store` — per-tenant payloads
 * behind one URL must never be cached by an intermediary.
 */
final class ApiResponder
{
    public static function respond(ApiRequest $request, ApiResult $result): JsonResponse
    {
        if ($result->isError()) {
            $headers = ['Cache-Control' => 'no-store'];
            if ($result->retryAfter !== null) {
                $headers['Retry-After'] = (string) $result->retryAfter;
            }
            return new JsonResponse(
                ['error' => ['code' => $result->errorCode, 'message' => $result->errorMessage]],
                $result->status,
                $headers
            );
        }

        $headers = $result->headers;
        $headers['Cache-Control'] = 'no-store';
        if ($result->etag !== null) {
            $headers['ETag'] = Etag::header($result->etag);

            if (Etag::matches($request->ifNoneMatch, $result->etag)) {
                return new JsonResponse([], 304, $headers);
            }
        }

        return new JsonResponse($result->data, 200, $headers);
    }
}
