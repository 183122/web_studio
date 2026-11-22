<?php

namespace App\Domain\Data;

use App\Support\ApiException;

final class DataException
{
    /** @param array<string, string> $fieldErrors fieldId => message */
    public static function invalid(array $fieldErrors): ApiException
    {
        return ApiException::unprocessable('record_invalid', 'ข้อมูลไม่ถูกต้อง', ['fields' => $fieldErrors]);
    }

    public static function forbidden(string $action): ApiException
    {
        return new ApiException(403, 'app_permission_denied', "บทบาทนี้ไม่มีสิทธิ์ {$action} ข้อมูลในตารางนี้");
    }

    public static function modelNotFound(): ApiException
    {
        return new ApiException(404, 'not_found', 'ไม่พบตารางข้อมูล');
    }
}
