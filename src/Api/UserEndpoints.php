<?php

namespace Ernestdefoe\Backdrop\Api;

use Ernestdefoe\Backdrop\Covers;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Illuminate\Support\Arr;

class UserEndpoints
{
    /**
     * @return array<Endpoint\Endpoint>
     */
    public function __invoke(): array
    {
        return [
            // Multipart: a "cover" file plus optional x, y and zoom.
            Endpoint\Endpoint::make('backdrop.upload')
                ->route('POST', '/{id}/backdrop')
                ->authenticated()
                ->action(fn (Context $context) => resolve(Covers::class)->upload(
                    $context->getActor(),
                    $context->model,
                    Arr::get($context->request->getUploadedFiles(), 'cover'),
                    (array) $context->request->getParsedBody(),
                )),

            // JSON: preset (a gallery id) plus optional x, y and zoom.
            Endpoint\Endpoint::make('backdrop.choose')
                ->route('POST', '/{id}/backdrop/preset')
                ->authenticated()
                ->action(fn (Context $context) => resolve(Covers::class)->choose(
                    $context->getActor(),
                    $context->model,
                    (array) $context->request->getParsedBody(),
                )),

            // JSON: x, y and zoom. Moves the existing cover without re-uploading.
            Endpoint\Endpoint::make('backdrop.frame')
                ->route('PATCH', '/{id}/backdrop')
                ->authenticated()
                ->action(fn (Context $context) => resolve(Covers::class)->reframe(
                    $context->getActor(),
                    $context->model,
                    (array) $context->request->getParsedBody(),
                )),

            Endpoint\Endpoint::make('backdrop.remove')
                ->route('DELETE', '/{id}/backdrop')
                ->authenticated()
                ->action(fn (Context $context) => resolve(Covers::class)->remove(
                    $context->getActor(),
                    $context->model,
                )),
        ];
    }
}
