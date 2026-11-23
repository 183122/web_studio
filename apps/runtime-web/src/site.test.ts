import { afterEach, describe, expect, it, vi } from 'vitest';
import { liveDataHost, readBootstrap, resolveAsset, siteHasAccounts, staticDataHost } from './site.ts';

afterEach(() => vi.restoreAllMocks());

describe('runtime site helpers', () => {
  it('reads the inert JSON bootstrap and ignores malformed data', () => {
    document.body.innerHTML = '<script id="__SITE__" type="application/json">{"path":"/","release":{"version":1,"hash":"x"},"document":{"pages":[]}}</script>';
    expect(readBootstrap()?.release.version).toBe(1);
    document.body.innerHTML = '<script id="__SITE__" type="application/json">{oops</script>';
    expect(readBootstrap()).toBeNull();
  });

  it('maps only well-formed asset refs to release asset URLs', () => {
    expect(resolveAsset('asset:01JABCDEFGHJKMNPQRSTVWXYZ0')).toBe('/_assets/01JABCDEFGHJKMNPQRSTVWXYZ0');
    expect(resolveAsset('asset:../../etc/passwd')).toBeUndefined();
    expect(resolveAsset('https://example.com/a.png')).toBeUndefined();
    // Static export maps ids to exported files; unknown ids resolve to nothing.
    expect(resolveAsset('asset:01JABCDEFGHJKMNPQRSTVWXYZ0', { '01JABCDEFGHJKMNPQRSTVWXYZ0': '/_assets/01JABCDEFGHJKMNPQRSTVWXYZ0.png' })).toBe('/_assets/01JABCDEFGHJKMNPQRSTVWXYZ0.png');
    expect(resolveAsset('asset:01JABCDEFGHJKMNPQRSTVWXYZ1', {})).toBeUndefined();
  });

  it('offers accounts only when members or admins have permissions', () => {
    const doc = (rules: object) => ({ permissions: [{ modelId: 'm', rules }] }) as never;
    expect(siteHasAccounts(doc({ public: ['create'], member: [], admin: [] }))).toBe(false);
    expect(siteHasAccounts(doc({ public: [], member: ['read'], admin: [] }))).toBe(true);
  });

  it('static export data host never calls the network', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch');
    expect(await staticDataHost.submit('m', {}, { hp: '', elapsedMs: 3000 })).toMatchObject({ ok: false });
    await expect(staticDataHost.list('m', { page: 1, perPage: 10, search: '' })).rejects.toThrow('static');
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('sends the CSRF header and maps validation and permission errors', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: { id: 'r1' } }), { status: 201 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ error: { code: 'validation_failed', message: 'x', details: { fields: { f_email: 'already exists' } } } }), { status: 422 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ error: { code: 'app_permission_denied', message: 'x' } }), { status: 403 }));
    expect(await liveDataHost.submit('m_a', { f: 1 }, { hp: '', elapsedMs: 3000 })).toEqual({ ok: true });
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('/api/runtime/v1/models/m_a/records');
    expect((init.headers as Record<string, string>)['X-Runtime-Request']).toBe('1');
    expect(init.credentials).toBe('same-origin');
    expect(await liveDataHost.submit('m_a', {}, { hp: '', elapsedMs: 3000 })).toMatchObject({ ok: false, fieldErrors: { f_email: 'already exists' } });
    expect(await liveDataHost.submit('m_a', {}, { hp: '', elapsedMs: 3000 })).toMatchObject({ ok: false, message: 'กรุณาเข้าสู่ระบบก่อนส่งข้อมูล' });
  });
});
