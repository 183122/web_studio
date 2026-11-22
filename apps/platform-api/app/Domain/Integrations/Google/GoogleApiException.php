<?php

namespace App\Domain\Integrations\Google;

use RuntimeException;

/**
 * Typed Google API failure. `kind` drives behaviour:
 * - auth: token revoked/expired beyond refresh → connection marked revoked, user must reconnect
 * - permission: the account lacks access to that file/sheet
 * - not_found: file/sheet/tab deleted or wrong id
 * - rate_limited / transient: retried with backoff
 * - invalid: request rejected (bad range etc.)
 * - not_configured / not_connected / scope_missing: setup problems shown to the user
 */
class GoogleApiException extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public function retryable(): bool
    {
        return in_array($this->kind, ['rate_limited', 'transient'], true);
    }

    public function userMessage(): string
    {
        return match ($this->kind) {
            'auth' => 'การเชื่อมต่อ Google ถูกเพิกถอนหรือหมดอายุ กรุณาเชื่อมต่อใหม่',
            'permission' => 'บัญชี Google ที่เชื่อมต่อไม่มีสิทธิ์เข้าถึงไฟล์นี้',
            'not_found' => 'ไม่พบไฟล์หรือแท็บนี้ใน Google (อาจถูกลบหรือเปลี่ยนชื่อ)',
            'rate_limited' => 'Google จำกัดจำนวนคำขอชั่วคราว ระบบจะลองใหม่อัตโนมัติ',
            'transient' => 'Google ไม่ตอบสนองชั่วคราว ระบบจะลองใหม่อัตโนมัติ',
            'not_configured' => 'ผู้ดูแลระบบยังไม่ได้ตั้งค่า Google OAuth',
            'not_connected' => 'Workspace นี้ยังไม่ได้เชื่อมต่อบัญชี Google',
            'scope_missing' => 'การเชื่อมต่อนี้ไม่ได้อนุญาตฟีเจอร์ที่ต้องใช้ กรุณาเชื่อมต่อใหม่และเลือกฟีเจอร์นี้',
            default => 'Google ปฏิเสธคำขอ: '.$this->getMessage(),
        };
    }
}
