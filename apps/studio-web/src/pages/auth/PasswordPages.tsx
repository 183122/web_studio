import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, useSearchParams } from 'react-router';
import { useState } from 'react';
import { api, type Envelope } from '../../lib/api.ts';
import { Button } from '../../components/ui/Button.tsx';
import { Field } from '../../components/ui/Field.tsx';
import { ErrorState } from '../../components/ui/misc.tsx';
import { AuthLayout } from './AuthLayout.tsx';

export function ForgotPassword() {
  const [done, setDone] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const { register, handleSubmit, formState } = useForm<{ email: string }>({
    resolver: zodResolver(z.object({ email: z.string().email('กรุณากรอกอีเมลให้ถูกต้อง') })),
  });
  return (
    <AuthLayout title="ลืมรหัสผ่าน" subtitle="เราจะส่งลิงก์สำหรับตั้งรหัสผ่านใหม่ไปที่อีเมลของคุณ">
      {done ? (
        <div className="space-y-4 text-sm">
          <p className="rounded-xl bg-brand-50 p-4 text-brand-900 dark:bg-brand-900/30 dark:text-brand-100">{done}</p>
          <Link to="/login" className="font-medium text-brand-700 hover:underline">กลับไปหน้าเข้าสู่ระบบ</Link>
        </div>
      ) : (
        <form
          className="space-y-4"
          noValidate
          onSubmit={handleSubmit(async (v) => {
            setError(null);
            try {
              setDone((await api<Envelope<{ message: string }>>('/auth/forgot-password', { method: 'POST', body: v })).data.message);
            } catch (e) {
              setError((e as Error).message);
            }
          })}
        >
          {error ? <ErrorState message={error} /> : null}
          <Field label="อีเมล" type="email" autoComplete="email" error={formState.errors.email?.message} {...register('email')} />
          <Button type="submit" className="w-full justify-center" loading={formState.isSubmitting}>ส่งลิงก์</Button>
        </form>
      )}
    </AuthLayout>
  );
}

const resetSchema = z
  .object({
    password: z.string().min(10, 'รหัสผ่านอย่างน้อย 10 ตัวอักษร').regex(/[A-Za-z]/, 'ต้องมีตัวอักษร').regex(/\d/, 'ต้องมีตัวเลข'),
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, { message: 'รหัสผ่านไม่ตรงกัน', path: ['password_confirmation'] });

export function ResetPassword() {
  const [params] = useSearchParams();
  const [done, setDone] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const { register, handleSubmit, formState } = useForm<z.infer<typeof resetSchema>>({ resolver: zodResolver(resetSchema) });
  const token = params.get('token') ?? '';
  const email = params.get('email') ?? '';
  return (
    <AuthLayout title="ตั้งรหัสผ่านใหม่" subtitle={email}>
      {done ? (
        <div className="space-y-4 text-sm">
          <p className="rounded-xl bg-brand-50 p-4 text-brand-900 dark:bg-brand-900/30 dark:text-brand-100">{done}</p>
          <Link to="/login" className="font-medium text-brand-700 hover:underline">เข้าสู่ระบบ</Link>
        </div>
      ) : !token || !email ? (
        <ErrorState message="ลิงก์ไม่สมบูรณ์ กรุณาขอลิงก์ใหม่" />
      ) : (
        <form
          className="space-y-4"
          noValidate
          onSubmit={handleSubmit(async (v) => {
            setError(null);
            try {
              setDone((await api<Envelope<{ message: string }>>('/auth/reset-password', { method: 'POST', body: { ...v, token, email } })).data.message);
            } catch (e) {
              setError((e as Error).message);
            }
          })}
        >
          {error ? <ErrorState message={error} /> : null}
          <Field label="รหัสผ่านใหม่" type="password" autoComplete="new-password" error={formState.errors.password?.message} {...register('password')} />
          <Field label="ยืนยันรหัสผ่านใหม่" type="password" autoComplete="new-password" error={formState.errors.password_confirmation?.message} {...register('password_confirmation')} />
          <Button type="submit" className="w-full justify-center" loading={formState.isSubmitting}>บันทึกรหัสผ่าน</Button>
        </form>
      )}
    </AuthLayout>
  );
}

export function VerifyEmailResult() {
  const [params] = useSearchParams();
  const ok = params.get('status') === 'verified';
  return (
    <AuthLayout title={ok ? 'ยืนยันอีเมลเรียบร้อย' : 'ลิงก์ยืนยันไม่ถูกต้อง'}>
      <p className="text-sm text-slate-600 dark:text-slate-300">
        {ok ? 'บัญชีของคุณได้รับการยืนยันแล้ว' : 'ลิงก์อาจหมดอายุหรือถูกใช้ไปแล้ว คุณสามารถขอส่งลิงก์ใหม่ได้จากแดชบอร์ด'}
      </p>
      <Link to="/app" className="mt-4 inline-block font-medium text-brand-700 hover:underline">ไปที่แดชบอร์ด</Link>
    </AuthLayout>
  );
}
