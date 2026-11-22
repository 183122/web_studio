<?php

namespace App\Domain\Integrations\Google;

use App\Models\GoogleConnection;
use Illuminate\Http\Client\PendingRequest;

/** Minimal Sheets v4 wrapper. All writes use valueInputOption=RAW so cell text is never evaluated as a formula. */
class SheetsApi
{
    public const MAX_ROWS = 5000;

    public const MAX_COLUMNS = 52;

    public function __construct(private readonly GoogleClient $google) {}

    /** Accepts a spreadsheet URL or a bare id. */
    public static function parseId(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]{20,128})#', $input, $m)) {
            return $m[1];
        }

        return preg_match('/^[a-zA-Z0-9_-]{20,128}$/', $input) ? $input : null;
    }

    private static function range(string $tab, string $a1 = ''): string
    {
        return "'".str_replace("'", "''", $tab)."'".($a1 !== '' ? '!'.$a1 : '');
    }

    private static function base(string $id): string
    {
        return GoogleEndpoints::url('sheets').'/spreadsheets/'.rawurlencode($id);
    }

    /** @return array{title: string, tabs: list<array{title: string, rows: int, columns: int}>} */
    public function spreadsheet(GoogleConnection $c, string $id): array
    {
        $res = $this->google->call($c, fn (PendingRequest $r) => $r->get(self::base($id), ['fields' => 'properties.title,sheets.properties(title,gridProperties(rowCount,columnCount))']));

        return [
            'title' => (string) $res->json('properties.title'),
            'tabs' => array_map(fn ($s) => [
                'title' => (string) $s['properties']['title'],
                'rows' => (int) ($s['properties']['gridProperties']['rowCount'] ?? 0),
                'columns' => (int) ($s['properties']['gridProperties']['columnCount'] ?? 0),
            ], $res->json('sheets') ?? []),
        ];
    }

    /** @return list<list<string>> rows (first row = header), trimmed to limits */
    public function values(GoogleConnection $c, string $id, string $tab): array
    {
        $res = $this->google->call($c, fn (PendingRequest $r) => $r->get(self::base($id).'/values/'.rawurlencode(self::range($tab, 'A1:AZ'.(self::MAX_ROWS + 1))), ['valueRenderOption' => 'UNFORMATTED_VALUE', 'dateTimeRenderOption' => 'FORMATTED_STRING']));
        $rows = $res->json('values') ?? [];

        return array_map(fn ($row) => array_map(fn ($v) => is_bool($v) ? ($v ? 'TRUE' : 'FALSE') : (string) $v, array_slice((array) $row, 0, self::MAX_COLUMNS)), array_slice($rows, 0, self::MAX_ROWS + 1));
    }

    /** @param list<array{range: string, values: list<list<mixed>>}> $data  (range relative to the tab, A1) */
    public function batchUpdate(GoogleConnection $c, string $id, string $tab, array $data): void
    {
        if ($data === []) {
            return;
        }
        $body = ['valueInputOption' => 'RAW', 'data' => array_map(fn ($d) => ['range' => self::range($tab, $d['range']), 'values' => $d['values']], $data)];
        $this->google->call($c, fn (PendingRequest $r) => $r->post(self::base($id).'/values:batchUpdate', $body));
    }

    /** @param list<list<mixed>> $rows */
    public function append(GoogleConnection $c, string $id, string $tab, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $url = self::base($id).'/values/'.rawurlencode(self::range($tab, 'A1')).':append?'.http_build_query(['valueInputOption' => 'RAW', 'insertDataOption' => 'INSERT_ROWS']);
        $this->google->call($c, fn (PendingRequest $r) => $r->post($url, ['values' => $rows]));
    }

    public static function columnLetter(int $index): string
    {
        $s = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + (($n - 1) % 26)).$s;
        }

        return $s;
    }
}
