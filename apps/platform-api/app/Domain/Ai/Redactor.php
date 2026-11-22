<?php

namespace App\Domain\Ai;

/** Masks personal data before prompts are stored in logs (PDPA). */
final class Redactor
{
    public static function redact(string $text, int $max = 2000): string
    {
        $text = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u', '[email]', $text) ?? $text;
        $text = preg_replace('/(?<!\d)(?:\+?66|0)[\d\- ]{8,11}\d(?!\d)/u', '[phone]', $text) ?? $text;
        $text = preg_replace('/(?<!\d)\d{13}(?!\d)/u', '[id-number]', $text) ?? $text; // Thai national ID
        $text = preg_replace('/\b(sk-[A-Za-z0-9_-]{8,}|sk_[a-z0-9]{12}_[A-Za-z0-9]{20,})\b/', '[secret]', $text) ?? $text;

        return mb_substr($text, 0, $max);
    }
}
