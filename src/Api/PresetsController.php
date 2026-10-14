<?php

namespace Ernestdefoe\Backdrop\Api;

use Ernestdefoe\Backdrop\Presets;
use Flarum\Http\Exception\MethodNotAllowedException;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The gallery. Any member may list it (the images are public anyway); only
 * admins change it:
 *
 * - GET    /backdrop/presets        the gallery, in order
 * - POST   /backdrop/presets        add one (multipart "cover", optional x, y, zoom)
 * - PUT    /backdrop/presets        reorder ({"order": [ids]})
 * - PATCH  /backdrop/presets/{id}   reframe ({x, y, zoom})
 * - DELETE /backdrop/presets/{id}   remove it; members using it fall back to the default
 *
 * Every response is the whole gallery, so the admin never holds a stale copy.
 */
class PresetsController implements RequestHandlerInterface
{
    public function __construct(protected Presets $presets)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $method = $request->getMethod();
        $input = (array) $request->getParsedBody();
        $id = (string) Arr::get($request->getQueryParams(), 'id', '');

        if ($method === 'GET') {
            $actor->assertRegistered();
        } else {
            $actor->assertAdmin();

            match ($method) {
                'POST' => $this->presets->add(Arr::get($request->getUploadedFiles(), 'cover'), $input),
                'PUT' => $this->presets->reorder((array) ($input['order'] ?? [])),
                'PATCH' => $this->presets->reframe($id, $input),
                'DELETE' => $this->presets->remove($id),
                default => throw new MethodNotAllowedException(),
            };
        }

        return new JsonResponse(['data' => $this->presets->forClient()]);
    }
}
