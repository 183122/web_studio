<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * In-memory stand-in for Google OAuth, Sheets v4 and Drive v3 (only the calls the app makes).
 * Records requests so tests can assert on them (e.g. RAW value input, PKCE verifier).
 */
class FakeGoogle
{
    /** @var array<string, array{title: string, tabs: array<string, list<list<mixed>>>}> */
    public array $spreadsheets = [];

    /** @var array<string, array{name: string, mime: string, parents: list<string>, content: string, trashed: bool}> */
    public array $files = [];

    public array $requests = [];

    public bool $revoked = false;

    public bool $refreshRevoked = false;

    public int $rateLimitNext = 0;

    public string $grantedScope = 'openid email https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive.file';

    public function install(): self
    {
        Http::fake(fn (Request $r) => $this->handle($r));

        return $this;
    }

    private function json(mixed $data, int $status = 200)
    {
        return Http::response($data, $status);
    }

    private function bearer(Request $r): ?string
    {
        $h = $r->header('Authorization')[0] ?? '';

        return str_starts_with($h, 'Bearer ') ? substr($h, 7) : null;
    }

    private function handle(Request $r)
    {
        $this->requests[] = ['method' => $r->method(), 'url' => $r->url(), 'body' => $r->body()];
        $url = parse_url($r->url());
        $host = $url['host'];
        $path = rawurldecode($url['path'] ?? '');
        parse_str($url['query'] ?? '', $query);

        if ($host === 'oauth2.googleapis.com' && $path === '/token') {
            $data = $r->data();
            if (($data['grant_type'] ?? '') === 'refresh_token') {
                return $this->refreshRevoked ? $this->json(['error' => 'invalid_grant'], 400) : $this->json(['access_token' => 'at-'.Str::random(8), 'expires_in' => 3600]);
            }
            if (($data['code'] ?? '') !== 'good-code' || strlen($data['code_verifier'] ?? '') < 43) {
                return $this->json(['error' => 'invalid_grant'], 400);
            }

            return $this->json(['access_token' => 'at-initial', 'refresh_token' => 'rt-secret-value', 'expires_in' => 3600, 'scope' => $this->grantedScope, 'token_type' => 'Bearer']);
        }
        if ($host === 'oauth2.googleapis.com' && $path === '/revoke') {
            $this->revoked = true;

            return $this->json([]);
        }
        if ($host === 'openidconnect.googleapis.com') {
            return $this->json(['sub' => '1234567890', 'email' => 'owner@gmail.example']);
        }
        if ($this->bearer($r) === null || $this->revoked) {
            return $this->json(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 401);
        }
        if ($this->rateLimitNext > 0) {
            $this->rateLimitNext--;

            return $this->json(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429);
        }
        if ($host === 'sheets.googleapis.com') {
            return $this->sheets($r, $path, $query);
        }
        if ($host === 'www.googleapis.com') {
            return $this->drive($r, $path, $query);
        }

        return $this->json(['error' => 'unexpected'], 500);
    }

    private static function parseRange(string $range): array
    {
        // 'Tab Name'!B3 or 'Tab'!A1:C1 or 'Tab'
        if (! preg_match("/^'((?:[^']|'')+)'(?:!([A-Z]+)(\d+)(?::([A-Z]+)(\d+))?)?$/", $range, $m)) {
            throw new \RuntimeException("bad range {$range}");
        }
        $col = fn ($l) => array_reduce(str_split($l), fn ($c, $ch) => $c * 26 + ord($ch) - 64, 0) - 1;

        return ['tab' => str_replace("''", "'", $m[1]), 'col' => isset($m[2]) && $m[2] !== '' ? $col($m[2]) : 0, 'row' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] - 1 : 0];
    }

    private function sheets(Request $r, string $path, array $query)
    {
        if (! preg_match('#^/v4/spreadsheets/([^/]+)(.*)$#', $path, $m) || ! isset($this->spreadsheets[$m[1]])) {
            return $this->json(['error' => ['code' => 404, 'status' => 'NOT_FOUND']], 404);
        }
        $id = $m[1];
        $rest = $m[2];
        $s = &$this->spreadsheets[$id];
        if ($rest === '' && $r->method() === 'GET') {
            return $this->json(['properties' => ['title' => $s['title']], 'sheets' => array_map(fn ($t) => ['properties' => ['title' => $t, 'gridProperties' => ['rowCount' => 1000, 'columnCount' => 26]]], array_keys($s['tabs']))]);
        }
        if ($rest === '/values:batchUpdate') {
            if (($r->data()['valueInputOption'] ?? '') !== 'RAW') {
                return $this->json(['error' => 'must be RAW'], 400);
            }
            foreach ($r->data()['data'] as $d) {
                $p = self::parseRange($d['range']);
                foreach ($d['values'] as $ri => $row) {
                    foreach ($row as $ci => $v) {
                        $this->set($s['tabs'][$p['tab']], $p['row'] + $ri, $p['col'] + $ci, $v);
                    }
                }
            }

            return $this->json(['totalUpdatedCells' => 1]);
        }
        if (preg_match('#^/values/(.+?)(:append)?$#', $rest, $vm)) {
            $p = self::parseRange($vm[1]);
            if (! isset($s['tabs'][$p['tab']])) {
                return $this->json(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Unable to parse range']], 400);
            }
            if (! empty($vm[2])) {
                if (($query['valueInputOption'] ?? '') !== 'RAW') {
                    return $this->json(['error' => 'must be RAW'], 400);
                }
                foreach ($r->data()['values'] as $row) {
                    $s['tabs'][$p['tab']][] = $row;
                }

                return $this->json(['updates' => []]);
            }
            $rows = $s['tabs'][$p['tab']];
            while ($rows !== [] && array_filter(end($rows), fn ($v) => $v !== '' && $v !== null) === []) {
                array_pop($rows);
            }

            return $this->json(['range' => $vm[1], 'values' => array_map(fn ($row) => array_values(self::trimRow($row)), $rows)]);
        }

        return $this->json(['error' => 'unsupported'], 400);
    }

    private static function trimRow(array $row): array
    {
        while ($row !== [] && (end($row) === '' || end($row) === null)) {
            array_pop($row);
        }

        return $row;
    }

    private function set(array &$rows, int $r, int $c, mixed $v): void
    {
        while (count($rows) <= $r) {
            $rows[] = [];
        }
        while (count($rows[$r]) <= $c) {
            $rows[$r][] = '';
        }
        $rows[$r][$c] = $v;
    }

    private function drive(Request $r, string $path, array $query)
    {
        if ($path === '/drive/v3/files' && $r->method() === 'GET') {
            return $this->json(['files' => array_values(array_map(fn ($id) => ['id' => $id, 'name' => $this->files[$id]['name']], array_keys(array_filter($this->files, fn ($f) => $f['mime'] === 'application/vnd.google-apps.folder' && ! $f['trashed']))))]);
        }
        if ($path === '/drive/v3/files' && $r->method() === 'POST') {
            $id = 'fld'.Str::random(20);
            $this->files[$id] = ['name' => $r->data()['name'], 'mime' => $r->data()['mimeType'], 'parents' => [], 'content' => '', 'trashed' => false];

            return $this->json(['id' => $id, 'name' => $r->data()['name']]);
        }
        if ($path === '/upload/drive/v3/files' && ($query['uploadType'] ?? '') === 'multipart') {
            if (! preg_match('/boundary=(\S+)/', $r->header('Content-Type')[0] ?? '', $bm)) {
                return $this->json(['error' => 'no boundary'], 400);
            }
            $parts = explode('--'.$bm[1], $r->body());
            $meta = json_decode(trim(explode("\r\n\r\n", $parts[1], 2)[1]), true);
            $content = substr(explode("\r\n\r\n", $parts[2], 2)[1], 0, -2);
            if (! isset($this->files[$meta['parents'][0]])) {
                return $this->json(['error' => ['code' => 404, 'status' => 'NOT_FOUND']], 404);
            }
            $id = 'file'.Str::random(20);
            $this->files[$id] = ['name' => $meta['name'], 'mime' => $meta['mimeType'], 'parents' => $meta['parents'], 'content' => $content, 'trashed' => false];

            return $this->json(['id' => $id, 'name' => $meta['name'], 'mimeType' => $meta['mimeType'], 'size' => (string) strlen($content)]);
        }
        if (preg_match('#^/drive/v3/files/([^/]+)$#', $path, $m)) {
            $f = $this->files[$m[1]] ?? null;
            if ($f === null) {
                return $this->json(['error' => ['code' => 404, 'status' => 'NOT_FOUND']], 404);
            }
            if ($r->method() === 'DELETE') {
                unset($this->files[$m[1]]);

                return Http::response('', 204);
            }
            if (($query['alt'] ?? '') === 'media') {
                return Http::response($f['content'], 200, ['Content-Type' => $f['mime']]);
            }

            return $this->json(['id' => $m[1], 'name' => $f['name'], 'mimeType' => $f['mime'], 'size' => (string) strlen($f['content']), 'trashed' => $f['trashed']]);
        }

        return $this->json(['error' => 'unsupported'], 400);
    }
}
