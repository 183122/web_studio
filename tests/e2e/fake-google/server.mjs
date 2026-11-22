// Fake Google (OAuth + Sheets v4 + Drive v3 subset) and a webhook receiver for E2E only.
// The API is pointed here with GOOGLE_FAKE_BASE; it is refused in production by the app.
import { createServer } from 'node:http';
import { createHash, createHmac, randomBytes } from 'node:crypto';

const PORT = Number(process.env.FAKE_GOOGLE_PORT ?? 8002);
const SHEET_ID = 'e2eSheet00000000000000000000000001';
let state;
function reset() {
  state = {
    codes: {}, tokens: {}, revoked: [], revokeAll: false, hooks: [],
    spreadsheets: {
      [SHEET_ID]: {
        title: 'ผู้สมัครจากแบบฟอร์มเดิม',
        tabs: {
          'ผู้สมัคร': [
            ['ชื่อ–นามสกุล', 'อีเมล', 'หลักสูตร', 'หมายเหตุจากทีม'],
            ['กมล ใจงาม', 'kamol@example.com', 'การใช้ AI เพื่อการทำงาน', 'โทรยืนยันแล้ว'],
            ['ดารา สดใส', 'dara@example.com', 'การวิเคราะห์ข้อมูลเบื้องต้น', ''],
            ['ไม่มีหลักสูตร', 'nocourse@example.com', 'หลักสูตรที่ไม่มี', ''],
          ],
          'รายงาน': [],
        },
      },
    },
    files: {},
  };
}
reset();

const json = (res, status, body) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)); };
const readBody = (req) => new Promise((resolve) => { const chunks = []; req.on('data', (c) => chunks.push(c)); req.on('end', () => resolve(Buffer.concat(chunks))); });
const b64url = (buf) => buf.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const authed = (req) => {
  const t = (req.headers.authorization ?? '').replace(/^Bearer /, '');
  return !state.revokeAll && state.tokens[t] ? state.tokens[t] : null;
};
const colIndex = (l) => [...l].reduce((c, ch) => c * 26 + ch.charCodeAt(0) - 64, 0) - 1;
const parseRange = (r) => {
  const m = /^'((?:[^']|'')+)'(?:!([A-Z]+)(\d+)(?::([A-Z]+)(\d+))?)?$/.exec(r);
  if (!m) throw new Error('bad range ' + r);
  return { tab: m[1].replace(/''/g, "'"), col: m[2] ? colIndex(m[2]) : 0, row: m[3] ? Number(m[3]) - 1 : 0 };
};
const setCell = (rows, r, c, v) => { while (rows.length <= r) rows.push([]); while (rows[r].length <= c) rows[r].push(''); rows[r][c] = v; };

createServer(async (req, res) => {
  const url = new URL(req.url, `http://127.0.0.1:${PORT}`);
  const path = decodeURIComponent(url.pathname);
  const body = await readBody(req);
  try {
    // ---- test controls ----
    if (path === '/__reset') { reset(); return json(res, 200, {}); }
    if (path === '/__state') return json(res, 200, { spreadsheets: state.spreadsheets, files: Object.fromEntries(Object.entries(state.files).map(([k, f]) => [k, { ...f, content: f.content.length }])), revoked: state.revoked.length, hooks: state.hooks.length });
    if (path === '/__revoke-all') { state.revokeAll = true; return json(res, 200, {}); }
    if (path === '/__hooks') return json(res, 200, state.hooks);
    if (path === '/hook' && req.method === 'POST') {
      state.hooks.push({ headers: { signature: req.headers['x-studio-signature'], event: req.headers['x-studio-event'], eventId: req.headers['x-studio-event-id'] }, body: body.toString('utf8') });
      return json(res, 200, { ok: true });
    }
    if (path === '/hook/verify' && req.method === 'POST') {
      const { secret, index } = JSON.parse(body.toString('utf8'));
      const h = state.hooks[index];
      const [, t, v1] = /^t=(\d+),v1=([0-9a-f]+)$/.exec(h.headers.signature);
      return json(res, 200, { valid: createHmac('sha256', secret).update(`${t}.${h.body}`).digest('hex') === v1 });
    }

    // ---- OAuth ----
    if (path === '/o/oauth2/v2/auth') {
      const code = 'code-' + randomBytes(8).toString('hex');
      state.codes[code] = { challenge: url.searchParams.get('code_challenge'), scope: url.searchParams.get('scope') };
      const back = new URL(url.searchParams.get('redirect_uri'));
      back.searchParams.set('code', code);
      back.searchParams.set('state', url.searchParams.get('state'));
      res.writeHead(302, { Location: back.toString() });
      return res.end();
    }
    if (path === '/token') {
      const p = new URLSearchParams(body.toString('utf8'));
      if (p.get('grant_type') === 'authorization_code') {
        const c = state.codes[p.get('code')];
        delete state.codes[p.get('code')];
        // Real PKCE verification: S256(code_verifier) must equal the challenge from the auth request.
        if (!c || b64url(createHash('sha256').update(p.get('code_verifier') ?? '').digest()) !== c.challenge) return json(res, 400, { error: 'invalid_grant' });
        const access = 'at-' + randomBytes(12).toString('hex');
        const refresh = 'rt-' + randomBytes(12).toString('hex');
        state.tokens[access] = { scope: c.scope, refresh };
        return json(res, 200, { access_token: access, refresh_token: refresh, expires_in: 3600, scope: c.scope, token_type: 'Bearer' });
      }
      return json(res, 400, { error: 'unsupported_grant_type' });
    }
    if (path === '/revoke') {
      const token = new URLSearchParams(body.toString('utf8')).get('token');
      state.revoked.push(token);
      for (const [k, v] of Object.entries(state.tokens)) if (v.refresh === token || k === token) delete state.tokens[k];
      return json(res, 200, {});
    }
    if (path === '/v1/userinfo') return authed(req) ? json(res, 200, { sub: '42', email: 'e2e.owner@gmail.example' }) : json(res, 401, {});

    const session = authed(req);
    if (!session) return json(res, 401, { error: { code: 401, status: 'UNAUTHENTICATED' } });

    // ---- Sheets ----
    let m = /^\/sheets\/v4\/spreadsheets\/([^/]+)(.*)$/.exec(path);
    if (m) {
      const s = state.spreadsheets[m[1]];
      if (!s) return json(res, 404, { error: { code: 404, status: 'NOT_FOUND' } });
      const rest = m[2];
      if (rest === '' && req.method === 'GET') return json(res, 200, { properties: { title: s.title }, sheets: Object.keys(s.tabs).map((t) => ({ properties: { title: t, gridProperties: { rowCount: 1000, columnCount: 26 } } })) });
      if (rest === '/values:batchUpdate') {
        const d = JSON.parse(body.toString('utf8'));
        if (d.valueInputOption !== 'RAW') return json(res, 400, { error: 'RAW required' });
        for (const u of d.data) { const r = parseRange(u.range); u.values.forEach((row, i) => row.forEach((v, j) => setCell(s.tabs[r.tab], r.row + i, r.col + j, v))); }
        return json(res, 200, {});
      }
      const vm = /^\/values\/(.+?)(:append)?$/.exec(rest);
      if (vm) {
        const r = parseRange(vm[1]);
        if (!s.tabs[r.tab]) return json(res, 400, { error: { code: 400, status: 'INVALID_ARGUMENT' } });
        if (vm[2]) { JSON.parse(body.toString('utf8')).values.forEach((row) => s.tabs[r.tab].push(row)); return json(res, 200, {}); }
        return json(res, 200, { values: s.tabs[r.tab] });
      }
    }
    // ---- Drive ----
    if (path === '/drive/v3/files' && req.method === 'GET') return json(res, 200, { files: Object.entries(state.files).filter(([, f]) => f.mime === 'application/vnd.google-apps.folder').map(([id, f]) => ({ id, name: f.name })) });
    if (path === '/drive/v3/files' && req.method === 'POST') {
      const d = JSON.parse(body.toString('utf8'));
      const id = 'folder' + randomBytes(10).toString('hex');
      state.files[id] = { name: d.name, mime: d.mimeType, content: Buffer.alloc(0) };
      return json(res, 200, { id, name: d.name });
    }
    if (path === '/upload/drive/v3/files' && req.method === 'POST') {
      const boundary = /boundary=(\S+)/.exec(req.headers['content-type'] ?? '')[1];
      const raw = body.toString('latin1');
      const parts = raw.split('--' + boundary);
      const meta = JSON.parse(Buffer.from(parts[1].split('\r\n\r\n')[1].trim(), 'latin1').toString('utf8'));
      const content = Buffer.from(parts[2].split('\r\n\r\n').slice(1).join('\r\n\r\n').slice(0, -2), 'latin1');
      if (!state.files[meta.parents[0]]) return json(res, 404, { error: { code: 404 } });
      const id = 'file' + randomBytes(10).toString('hex');
      state.files[id] = { name: meta.name, mime: meta.mimeType, content, parent: meta.parents[0] };
      return json(res, 200, { id, name: meta.name, mimeType: meta.mimeType, size: String(content.length) });
    }
    m = /^\/drive\/v3\/files\/([^/]+)$/.exec(path);
    if (m) {
      const f = state.files[m[1]];
      if (!f) return json(res, 404, { error: { code: 404, status: 'NOT_FOUND' } });
      if (req.method === 'DELETE') { delete state.files[m[1]]; res.writeHead(204); return res.end(); }
      if (url.searchParams.get('alt') === 'media') { res.writeHead(200, { 'Content-Type': f.mime }); return res.end(f.content); }
      return json(res, 200, { id: m[1], name: f.name, mimeType: f.mime, size: String(f.content.length), trashed: false });
    }
    return json(res, 404, { error: 'not found' });
  } catch (e) {
    return json(res, 500, { error: String(e) });
  }
}).listen(PORT, '127.0.0.1', () => console.log(`fake google on ${PORT}`));
