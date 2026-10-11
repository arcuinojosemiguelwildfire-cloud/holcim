<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\RandomizerBackgroundService;

/**
 * Randomizer background image. Viewing: admin + event operator (whoever can
 * run the Randomizer). Upload / restore default: admin only (routes.php).
 */
final class RandomizerBackgroundController
{
    /** GET /randomizers/background - the active background (custom or default). */
    public static function show(Request $request): Response
    {
        return Response::success(['background' => RandomizerBackgroundService::current()]);
    }

    /** GET /randomizers/background/image - the custom image itself (404 = default). */
    public static function image(Request $request): Response
    {
        return RandomizerBackgroundService::image();
    }

    /** POST /settings/randomizer-background (admin, multipart "image") */
    public static function upload(Request $request): Response
    {
        $file = $_FILES['image'] ?? null;
        // Body larger than post_max_size: PHP drops the whole upload.
        if ($file === null && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw HttpException::validation(['image' => ['The image is larger than the server allows (maximum 10 MB).']],
                'The image is larger than the server allows (maximum 10 MB).');
        }

        return Response::success(
            ['background' => RandomizerBackgroundService::upload($request, is_array($file) ? $file : null)],
            200,
            'Randomizer background updated.'
        );
    }

    /** DELETE /settings/randomizer-background (admin) - restore the default background. */
    public static function restore(Request $request): Response
    {
        return Response::success(['background' => RandomizerBackgroundService::restoreDefault($request)], 200, 'Default randomizer background restored.');
    }
}
