<?php

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiCall;
use App\Domain\Ai\AiCompletion;
use App\Domain\Ai\AiProviderException;

/**
 * Offline, deterministic planner used when no AI provider is configured or the provider is down
 * (spec §11 "safe fallback"). It recognises common Thai/English app types by keyword and returns
 * a plan in the same schema as the LLM, so the rest of the pipeline is identical.
 * It is clearly labelled as a template-based fallback, not AI.
 */
class LocalRulesProvider implements AiProvider
{
    public function name(): string
    {
        return 'local';
    }

    public function model(): string
    {
        return 'rules-v1';
    }

    public function isExternal(): bool
    {
        return false;
    }

    public function complete(AiCall $call): AiCompletion
    {
        $data = match ($call->purpose) {
            'plan' => $this->plan($call->prompt),
            'patch' => $this->edit($call->prompt, $call->context),
            default => throw new AiProviderException('ai_unsupported', 'โหมดออฟไลน์ไม่รองรับคำขอนี้', false),
        };

        return new AiCompletion($data, 'local', 'rules-v1');
    }

    private static function has(string $text, array $words): bool
    {
        foreach ($words as $w) {
            if (mb_stripos($text, $w) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function sec(string $kind, string $heading = '', string $text = '', array $items = [], string $buttonLabel = '', string $buttonHref = '', string $entity = '', array $fields = []): array
    {
        return compact('kind', 'heading', 'text', 'items', 'buttonLabel', 'buttonHref', 'entity', 'fields');
    }

    private static function f(string $name, string $label, string $type, bool $required = false, bool $unique = false, array $options = []): array
    {
        return compact('name', 'label', 'type', 'required', 'unique', 'options');
    }

    private static function siteName(string $prompt, string $fallback): string
    {
        if (preg_match('/(?:ชื่อ|ชื่อว่า|ชื่อเว็บ(?:ไซต์)?|named|called)\s*["“]?([^"”\n,]{2,60})/u', $prompt, $m)) {
            return trim($m[1]);
        }

        return $fallback;
    }

    public function plan(string $prompt): array
    {
        $p = mb_strtolower($prompt);
        if (self::has($p, ['อบรม', 'สัมมนา', 'ลงทะเบียน', 'คอร์ส', 'หลักสูตร', 'training', 'workshop', 'register'])) {
            $name = self::siteName($prompt, 'ระบบลงทะเบียนอบรม');
            $entity = ['name' => 'registrations', 'label' => 'ผู้ลงทะเบียน', 'fields' => [
                self::f('full_name', 'ชื่อ–นามสกุล', 'text', true),
                self::f('email', 'อีเมล', 'email', true, true),
                self::f('phone', 'เบอร์โทรศัพท์', 'phone'),
                self::f('organization', 'หน่วยงาน', 'text'),
                self::f('status', 'สถานะ', 'select', false, false, ['รอตรวจสอบ', 'ยืนยันแล้ว', 'ยกเลิก']),
            ]];

            return $this->assemble($name, 'เว็บไซต์ลงทะเบียนอบรม พร้อมฟอร์มบันทึกข้อมูลผู้สมัครและอีเมลยืนยันอัตโนมัติ', [
                ['name' => 'หน้าแรก', 'path' => '/', 'title' => $name, 'description' => 'ลงทะเบียนเข้าร่วมอบรม', 'sections' => [
                    self::sec('hero', $name, 'ลงทะเบียนเข้าร่วมอบรมออนไลน์ได้ง่ายภายในไม่กี่นาที', [], 'ลงทะเบียนเลย', '/register'),
                    self::sec('features', 'ทำไมต้องอบรมกับเรา', '', [['title' => 'วิทยากรมืออาชีพ', 'text' => 'ประสบการณ์ตรงในสายงาน'], ['title' => 'ใบประกาศนียบัตร', 'text' => 'เมื่อผ่านเกณฑ์การอบรม'], ['title' => 'เรียนได้ทุกที่', 'text' => 'ทั้งออนไลน์และในสถานที่']]),
                    self::sec('cta', 'พร้อมเริ่มเรียนหรือยัง?', 'ที่นั่งมีจำนวนจำกัด', [], 'ลงทะเบียน', '/register'),
                ]],
                ['name' => 'ลงทะเบียน', 'path' => '/register', 'title' => 'ลงทะเบียน', 'description' => 'แบบฟอร์มลงทะเบียน', 'sections' => [
                    self::sec('form', 'แบบฟอร์มลงทะเบียน', 'กรอกข้อมูลให้ครบถ้วน เจ้าหน้าที่จะติดต่อกลับทางอีเมล', [], 'ส่งใบสมัคร', '', 'registrations', ['full_name', 'email', 'phone', 'organization']),
                ]],
                ['name' => 'รายชื่อผู้ลงทะเบียน', 'path' => '/registrants', 'title' => 'รายชื่อผู้ลงทะเบียน', 'description' => 'สำหรับผู้ดูแล', 'sections' => [
                    self::sec('table', 'รายชื่อผู้ลงทะเบียน', 'แสดงเฉพาะผู้ที่มีสิทธิ์อ่านข้อมูล', [], '', '', 'registrations', ['full_name', 'organization', 'status']),
                ]],
            ], [$entity], [['entity' => 'registrations', 'public' => ['create'], 'member' => []]], [
                ['name' => 'ยืนยันการลงทะเบียน', 'entity' => 'registrations', 'event' => 'record.created', 'setFieldName' => 'status', 'setFieldValue' => 'รอตรวจสอบ', 'emailTo' => 'field:email', 'emailSubject' => 'ได้รับการลงทะเบียนของ {{full_name}} แล้ว', 'emailBody' => "เรียน {{full_name}}\nเราได้รับการลงทะเบียนของคุณแล้ว"],
            ], ['วันเวลาและสถานที่อบรมแต่ละรอบ', 'อีเมลผู้ดูแลที่ต้องการรับแจ้งเตือน'], 0.7);
        }
        if (self::has($p, ['จอง', 'นัดหมาย', 'คิว', 'booking', 'appointment', 'reservation'])) {
            $name = self::siteName($prompt, 'ระบบจองคิว');

            return $this->assemble($name, 'เว็บไซต์รับจองคิว/นัดหมายออนไลน์ พร้อมรายการจองสำหรับผู้ดูแล', [
                ['name' => 'หน้าแรก', 'path' => '/', 'title' => $name, 'description' => 'จองคิวออนไลน์', 'sections' => [
                    self::sec('hero', $name, 'เลือกวันเวลาที่สะดวกและจองได้ทันที', [], 'จองเลย', '/booking'),
                    self::sec('list', 'ขั้นตอนการจอง', '', [['title' => 'เลือกบริการ', 'text' => ''], ['title' => 'เลือกวันและเวลา', 'text' => ''], ['title' => 'รอรับการยืนยันทางอีเมล', 'text' => '']]),
                ]],
                ['name' => 'จองคิว', 'path' => '/booking', 'title' => 'จองคิว', 'description' => 'แบบฟอร์มจองคิว', 'sections' => [
                    self::sec('form', 'จองคิว', '', [], 'ยืนยันการจอง', '', 'bookings', []),
                ]],
            ], [['name' => 'bookings', 'label' => 'การจอง', 'fields' => [
                self::f('customer_name', 'ชื่อผู้จอง', 'text', true),
                self::f('email', 'อีเมล', 'email', true),
                self::f('phone', 'เบอร์โทร', 'phone', true),
                self::f('service', 'บริการ', 'select', true, false, ['บริการ A', 'บริการ B']),
                self::f('booking_date', 'วันที่ต้องการ', 'date', true),
                self::f('note', 'หมายเหตุ', 'longtext'),
            ]]], [['entity' => 'bookings', 'public' => ['create'], 'member' => []]], [
                ['name' => 'ยืนยันการจอง', 'entity' => 'bookings', 'event' => 'record.created', 'setFieldName' => '', 'setFieldValue' => '', 'emailTo' => 'field:email', 'emailSubject' => 'ได้รับการจองของคุณแล้ว', 'emailBody' => "เรียน {{customer_name}}\nเราได้รับการจองวันที่ {{booking_date}} แล้ว"],
            ], ['รายการบริการและช่วงเวลาที่เปิดจอง', 'จำนวนคิวสูงสุดต่อวัน'], 0.6);
        }
        if (self::has($p, ['ร้าน', 'สินค้า', 'ขาย', 'แคตตาล็อก', 'shop', 'store', 'product', 'catalog'])) {
            $name = self::siteName($prompt, 'ร้านค้าออนไลน์');

            return $this->assemble($name, 'เว็บไซต์แคตตาล็อกสินค้า พร้อมฟอร์มสอบถาม/สั่งซื้อ (ยังไม่รวมการชำระเงิน)', [
                ['name' => 'หน้าแรก', 'path' => '/', 'title' => $name, 'description' => 'สินค้าคุณภาพ', 'sections' => [
                    self::sec('hero', $name, 'สินค้าคุณภาพ ส่งตรงถึงบ้านคุณ', [], 'ดูสินค้า', '/products'),
                    self::sec('features', 'จุดเด่นของเรา', '', [['title' => 'คุณภาพดี', 'text' => ''], ['title' => 'ราคาคุ้มค่า', 'text' => ''], ['title' => 'จัดส่งรวดเร็ว', 'text' => '']]),
                ]],
                ['name' => 'สินค้า', 'path' => '/products', 'title' => 'สินค้า', 'description' => 'รายการสินค้า', 'sections' => [
                    self::sec('table', 'รายการสินค้า', '', [], '', '', 'products', ['product_name', 'price', 'category']),
                ]],
                ['name' => 'สั่งซื้อ/สอบถาม', 'path' => '/order', 'title' => 'สั่งซื้อ', 'description' => 'ฟอร์มสั่งซื้อ', 'sections' => [
                    self::sec('form', 'ฟอร์มสั่งซื้อหรือสอบถาม', 'ทีมงานจะติดต่อกลับเพื่อยืนยันคำสั่งซื้อ', [], 'ส่งคำสั่งซื้อ', '', 'orders', []),
                ]],
            ], [
                ['name' => 'products', 'label' => 'สินค้า', 'fields' => [self::f('product_name', 'ชื่อสินค้า', 'text', true, true), self::f('price', 'ราคา (บาท)', 'number', true), self::f('category', 'หมวดหมู่', 'text'), self::f('in_stock', 'มีสินค้า', 'boolean')]],
                ['name' => 'orders', 'label' => 'คำสั่งซื้อ', 'fields' => [self::f('customer_name', 'ชื่อลูกค้า', 'text', true), self::f('email', 'อีเมล', 'email', true), self::f('phone', 'เบอร์โทร', 'phone', true), self::f('items', 'สินค้าที่ต้องการ', 'longtext', true), self::f('address', 'ที่อยู่จัดส่ง', 'longtext')]],
            ], [['entity' => 'products', 'public' => ['read'], 'member' => ['read']], ['entity' => 'orders', 'public' => ['create'], 'member' => []]], [], ['วิธีชำระเงินและการจัดส่ง', 'รายการสินค้าเริ่มต้น'], 0.55);
        }
        $name = self::siteName($prompt, 'เว็บไซต์ของฉัน');

        return $this->assemble($name, 'เว็บไซต์องค์กร/ธุรกิจทั่วไป พร้อมฟอร์มติดต่อที่บันทึกลงฐานข้อมูล', [
            ['name' => 'หน้าแรก', 'path' => '/', 'title' => $name, 'description' => mb_substr($prompt, 0, 150), 'sections' => [
                self::sec('hero', $name, mb_substr($prompt, 0, 200), [], 'ติดต่อเรา', '/contact'),
                self::sec('features', 'บริการของเรา', '', [['title' => 'บริการที่ 1', 'text' => ''], ['title' => 'บริการที่ 2', 'text' => ''], ['title' => 'บริการที่ 3', 'text' => '']]),
            ]],
            ['name' => 'ติดต่อ', 'path' => '/contact', 'title' => 'ติดต่อเรา', 'description' => 'ช่องทางติดต่อ', 'sections' => [
                self::sec('form', 'ส่งข้อความถึงเรา', '', [], 'ส่งข้อความ', '', 'messages', []),
            ]],
        ], [['name' => 'messages', 'label' => 'ข้อความติดต่อ', 'fields' => [self::f('name', 'ชื่อ', 'text', true), self::f('email', 'อีเมล', 'email', true), self::f('message', 'ข้อความ', 'longtext', true)]]],
            [['entity' => 'messages', 'public' => ['create'], 'member' => []]], [], ['ประเภทธุรกิจและบริการหลัก', 'ข้อมูลติดต่อ'], 0.35);
    }

    private function assemble(string $name, string $summary, array $pages, array $entities, array $permissions, array $workflows, array $missing, float $confidence): array
    {
        return [
            'summary' => $summary.' (สร้างด้วยแม่แบบอัตโนมัติแบบออฟไลน์ ไม่ได้ใช้ AI)',
            'confidence' => $confidence,
            'missingRequirements' => $missing,
            'assumptions' => ['ใช้ภาษาไทยเป็นหลัก', 'ผู้ดูแลจัดการข้อมูลผ่านหน้า "ข้อมูล" ของ Studio'],
            'siteName' => $name,
            'theme' => ['primary' => '#0f766e', 'secondary' => '#1e3a5f', 'accent' => '#f59e0b', 'headingFont' => 'Kanit', 'bodyFont' => 'Sarabun'],
            'pages' => $pages,
            'entities' => $entities,
            'permissions' => $permissions,
            'workflows' => $workflows,
        ];
    }

    /** Small command vocabulary for offline edits (colour, text of the selection, add/remove section). */
    public function edit(string $prompt, array $context): array
    {
        $ops = [];
        $op = fn (string $o, string $targetId = '', string $key = '', string $value = '', ?array $section = null) => ['op' => $o, 'targetId' => $targetId, 'key' => $key, 'value' => $value, 'breakpoint' => 'base', 'section' => $section, 'field' => null];
        if (preg_match('/(สีหลัก|primary|สีรอง|secondary|สีเน้น|accent)[^#]*(#[0-9a-fA-F]{6})/u', $prompt, $m)) {
            $key = match (true) {
                in_array($m[1], ['สีรอง', 'secondary'], true) => 'secondary',
                in_array($m[1], ['สีเน้น', 'accent'], true) => 'accent',
                default => 'primary',
            };
            $ops[] = $op('setTheme', '', $key, $m[2]);
        }
        $selected = $context['selected'] ?? null;
        if ($selected && preg_match('/(?:เป็น|to)\s*["“](.+?)["”]/u', $prompt, $m)) {
            $key = $selected['type'] === 'Button' ? 'label' : 'text';
            $ops[] = $op('setText', $selected['id'], $key, $m[1]);
        }
        if (self::has($prompt, ['เพิ่มคำถาม', 'faq'])) {
            $ops[] = $op('addSection', '', '', '', self::sec('faq', 'คำถามที่พบบ่อย', '', [['title' => 'คำถามที่ 1', 'text' => 'คำตอบ'], ['title' => 'คำถามที่ 2', 'text' => 'คำตอบ']]));
        } elseif (self::has($prompt, ['เพิ่มส่วนติดต่อ', 'เพิ่มช่องทางติดต่อ', 'add contact'])) {
            $ops[] = $op('addSection', '', '', '', self::sec('contact', 'ติดต่อเรา', 'อีเมล: hello@example.com', [], 'ส่งอีเมล', 'mailto:hello@example.com'));
        }
        if ($selected && self::has($prompt, ['ลบส่วนนี้', 'ลบอันนี้', 'remove this', 'delete this'])) {
            $ops[] = $op('removeComponent', $selected['id']);
        }

        return [
            'summary' => $ops === [] ? 'โหมดออฟไลน์เข้าใจเฉพาะคำสั่งพื้นฐาน เช่น "เปลี่ยนสีหลักเป็น #2563eb", "เปลี่ยนข้อความเป็น "..."", "เพิ่มคำถามที่พบบ่อย", "ลบส่วนนี้"' : 'แก้ไขตามคำสั่ง (โหมดออฟไลน์)',
            'operations' => $ops,
        ];
    }
}
