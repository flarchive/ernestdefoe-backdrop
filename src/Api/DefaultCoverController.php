<?php

namespace Ernestdefoe\Backdrop\Api;

use Ernestdefoe\Backdrop\CoverStore;
use Ernestdefoe\Backdrop\CoverValidator;
use Ernestdefoe\Backdrop\Frame;
use Ernestdefoe\Backdrop\Settings;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The forum-wide cover shown to members without their own. POST uploads it
 * (multipart), PATCH reframes it (JSON), DELETE clears it. Admins only.
 */
class DefaultCoverController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected CoverStore $store,
        protected CoverValidator $validator,
        protected TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $current = $this->settings->get(Settings::DEFAULT);
        $input = (array) $request->getParsedBody();

        switch ($request->getMethod()) {
            case 'POST':
                $file = Arr::get($request->getUploadedFiles(), 'cover');

                if (! $file) {
                    throw new ValidationException(['cover' => $this->translator->trans('ernestdefoe-backdrop.api.no_file')]);
                }

                $this->validator->assertImageValid('cover', $file);
                $frame = Frame::fromInput($input);
                $stored = $this->store->store($file);

                try {
                    $this->saveFrame($frame);
                    $this->settings->set(Settings::DEFAULT, $stored);
                } catch (\Throwable $e) {
                    $this->store->delete($stored);

                    throw $e;
                }

                $this->store->delete($current);
                break;

            case 'PATCH':
                if (! $current) {
                    throw new ValidationException(['cover' => $this->translator->trans('ernestdefoe-backdrop.api.no_cover')]);
                }

                $this->saveFrame(Frame::fromInput($input));
                break;

            default:
                $this->settings->delete(Settings::DEFAULT);
                $this->saveFrame(new Frame());
                $this->store->delete($current);
        }

        return new JsonResponse(['data' => resolve(Settings::class)->defaultCover()]);
    }

    protected function saveFrame(Frame $frame): void
    {
        $this->settings->set(Settings::DEFAULT_FRAME, json_encode($frame->toArray()));
    }
}
