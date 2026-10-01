<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AttendeeService;
use App\Services\QrCodeService;
use App\Utils\Validator;

final class QrCodeController
{
    /** GET /qr-codes/summary */
    public static function summary(Request $request): Response
    {
        return Response::success(QrCodeService::summary());
    }

    /** GET /qr-codes?search=&department=&qr_status=generated|missing|all&page=&per_page= (active attendees only) */
    public static function index(Request $request): Response
    {
        $qrStatus = (string) $request->query('qr_status', 'all');
        $department = $request->query('department');
        $search = $request->query('search');

        return Response::success(AttendeeService::list(
            is_string($search) ? $search : '',
            is_string($department) ? $department : null,
            'active',
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 25),
            in_array($qrStatus, ['generated', 'missing'], true) ? $qrStatus : null
        ));
    }

    /** POST /qr-codes/generate-missing (admin) */
    public static function generateMissing(Request $request): Response
    {
        $result = QrCodeService::generateMissing($request);

        return Response::success($result, message: "Generated {$result['created']} QR code(s).");
    }

    /** GET /qr-codes/print?ids=1,2,3 (omit ids = all) */
    public static function print(Request $request): Response
    {
        $raw = $request->query('ids');
        $ids = null;
        if (is_string($raw) && $raw !== '') {
            $parts = explode(',', $raw);
            if (count($parts) > 2000) {
                throw HttpException::badRequest('Too many attendees selected.');
            }
            $ids = array_values(array_unique(array_map('intval', array_filter($parts, 'ctype_digit'))));
        }

        return Response::success(QrCodeService::printData($ids));
    }

    /** GET /attendees/{id}/qr */
    public static function show(Request $request): Response
    {
        return Response::success(['qr' => QrCodeService::forAttendee(Validator::id($request->param('id'), 'Attendee'))]);
    }

    /** POST /attendees/{id}/qr (admin) - generate if missing */
    public static function generate(Request $request): Response
    {
        return Response::success(['qr' => QrCodeService::generate($request, Validator::id($request->param('id'), 'Attendee'))]);
    }

    /** POST /attendees/{id}/qr/regenerate (admin) */
    public static function regenerate(Request $request): Response
    {
        $qr = QrCodeService::regenerate($request, Validator::id($request->param('id'), 'Attendee'));

        return Response::success(['qr' => $qr], message: 'QR regenerated. The previous QR is no longer valid.');
    }
}
