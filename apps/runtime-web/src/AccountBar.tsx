import { useState, type CSSProperties, type FormEvent } from 'react';
import { call, fieldErrors, RuntimeError, type AppUser } from './site.ts';

const bar: CSSProperties = {
  position: 'sticky', top: 0, zIndex: 50, display: 'flex', justifyContent: 'flex-end', alignItems: 'center', gap: 8,
  padding: '6px 12px', background: 'rgba(15,23,42,.92)', color: '#fff', fontSize: 14, fontFamily: 'system-ui, sans-serif',
};
const btn: CSSProperties = { background: 'transparent', color: '#fff', border: '1px solid rgba(255,255,255,.4)', borderRadius: 999, padding: '4px 12px', cursor: 'pointer', font: 'inherit' };
const input: CSSProperties = { width: '100%', padding: '10px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 16, boxSizing: 'border-box', font: 'inherit' };

type Mode = 'login' | 'register' | null;

/** Sign-in / sign-up for end users of this published app (separate from studio accounts). */
export function AccountBar({ user, onChange }: { user: AppUser | null | undefined; onChange: (u: AppUser | null) => void }) {
  const [mode, setMode] = useState<Mode>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = Object.fromEntries(new FormData(e.currentTarget)) as Record<string, string>;
    setBusy(true);
    setErrors({});
    setMessage('');
    try {
      const res = await call<{ data: AppUser }>(mode === 'register' ? '/auth/register' : '/auth/login', { method: 'POST', body: form });
      onChange(res.data);
      setMode(null);
    } catch (err) {
      if (err instanceof RuntimeError && err.status === 422) {
        setErrors(fieldErrors(err));
        setMessage(mode === 'login' ? 'อีเมลหรือรหัสผ่านไม่ถูกต้อง' : 'กรุณาตรวจสอบข้อมูล');
      } else if (err instanceof RuntimeError && err.status === 429) {
        setMessage('ลองหลายครั้งเกินไป กรุณารอสักครู่');
      } else {
        setMessage('เชื่อมต่อไม่สำเร็จ กรุณาลองใหม่');
      }
    } finally {
      setBusy(false);
    }
  }

  async function logout() {
    await call('/auth/logout', { method: 'POST', body: {} }).catch(() => undefined);
    onChange(null);
  }

  const field = (name: string, label: string, type = 'text', autoComplete?: string) => (
    <label style={{ display: 'block', marginBottom: 12 }}>
      <span style={{ display: 'block', fontSize: 14, marginBottom: 4 }}>{label}</span>
      <input name={name} type={type} required autoComplete={autoComplete} style={input} aria-invalid={!!errors[name]} />
      {errors[name] ? <span role="alert" style={{ color: '#b91c1c', fontSize: 13 }}>{errors[name]}</span> : null}
    </label>
  );

  return (
    <>
      <div style={bar} role="navigation" aria-label="บัญชีผู้ใช้">
        {user === undefined ? null : user ? (
          <>
            <span>สวัสดี {user.name}</span>
            <button type="button" style={btn} onClick={logout}>ออกจากระบบ</button>
          </>
        ) : (
          <>
            <button type="button" style={btn} onClick={() => setMode('login')}>เข้าสู่ระบบ</button>
            <button type="button" style={{ ...btn, background: '#fff', color: '#0f172a' }} onClick={() => setMode('register')}>สมัครสมาชิก</button>
          </>
        )}
      </div>
      {mode ? (
        <div role="dialog" aria-modal="true" aria-labelledby="acct-title" style={{ position: 'fixed', inset: 0, zIndex: 60, background: 'rgba(15,23,42,.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={(e) => e.target === e.currentTarget && setMode(null)}>
          <form onSubmit={submit} style={{ background: '#fff', color: '#0f172a', borderRadius: 16, padding: 24, width: '100%', maxWidth: 380, fontFamily: 'system-ui, sans-serif' }}>
            <h2 id="acct-title" style={{ marginTop: 0 }}>{mode === 'login' ? 'เข้าสู่ระบบ' : 'สมัครสมาชิก'}</h2>
            {mode === 'register' ? field('name', 'ชื่อ', 'text', 'name') : null}
            {field('email', 'อีเมล', 'email', 'email')}
            {field('password', 'รหัสผ่าน', 'password', mode === 'login' ? 'current-password' : 'new-password')}
            {mode === 'register' ? field('password_confirmation', 'ยืนยันรหัสผ่าน', 'password', 'new-password') : null}
            {mode === 'register' ? <p style={{ fontSize: 13, color: '#475569', marginTop: 0 }}>รหัสผ่านอย่างน้อย 10 ตัว มีทั้งตัวอักษรและตัวเลข</p> : null}
            {message ? <p role="alert" style={{ color: '#b91c1c' }}>{message}</p> : null}
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
              <button type="button" onClick={() => setMode(null)} style={{ ...btn, color: '#0f172a', borderColor: '#cbd5e1' }}>ยกเลิก</button>
              <button type="submit" disabled={busy} style={{ ...btn, background: '#0f172a', borderColor: '#0f172a' }}>{busy ? 'กำลังดำเนินการ…' : mode === 'login' ? 'เข้าสู่ระบบ' : 'สมัคร'}</button>
            </div>
            <p style={{ fontSize: 14, textAlign: 'center', marginBottom: 0 }}>
              {mode === 'login' ? 'ยังไม่มีบัญชี? ' : 'มีบัญชีแล้ว? '}
              <button type="button" onClick={() => { setErrors({}); setMessage(''); setMode(mode === 'login' ? 'register' : 'login'); }} style={{ background: 'none', border: 0, color: '#2563eb', cursor: 'pointer', font: 'inherit', textDecoration: 'underline' }}>
                {mode === 'login' ? 'สมัครสมาชิก' : 'เข้าสู่ระบบ'}
              </button>
            </p>
          </form>
        </div>
      ) : null}
    </>
  );
}
