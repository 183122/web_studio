import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { useState } from 'react';
import { api, ApiError, ensureCsrf } from '../../lib/api.ts';
import { safeNext, useAuthActions } from '../../lib/auth.tsx';
import { Button } from '../../components/ui/Button.tsx';
import { Field } from '../../components/ui/Field.tsx';
import { ErrorState } from '../../components/ui/misc.tsx';
import { AuthLayout } from './AuthLayout.tsx';

const schema = z.object({
  email: z.string().email('กรุณากรอกอีเมลให้ถูกต้อง'),
  password: z.string().min(1, 'กรุณากรอกรหัสผ่าน'),
  remember: z.boolean().optional(),
});
type Values = z.infer<typeof schema>;

export default function Login() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const { refresh } = useAuthActions();
  const [error, setError] = useState<string | null>(null);
  const { register, handleSubmit, formState } = useForm<Values>({ resolver: zodResolver(schema) });

  const onSubmit = async (values: Values) => {
    setError(null);
    try {
      await ensureCsrf(true);
      await api('/auth/login', { method: 'POST', body: values });
      await ensureCsrf(true);
      await refresh();
      navigate(safeNext(params.get('next')), { replace: true });
    } catch (e) {
      if (e instanceof ApiError && e.status === 429) setError('พยายามเข้าสู่ระบบบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่');
      else if (e instanceof ApiError && e.fields.email) setError(e.fields.email[0]);
      else setError(e instanceof Error ? e.message : 'เข้าสู่ระบบไม่สำเร็จ');
    }
  };

  return (
    <AuthLayout title="เข้าสู่ระบบ" subtitle="สร้างเว็บไซต์และแอปด้วย AI และการลากวาง">
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
        {error ? <ErrorState message={error} /> : null}
        <Field label="อีเมล" type="email" autoComplete="email" error={formState.errors.email?.message} {...register('email')} />
        <Field label="รหัสผ่าน" type="password" autoComplete="current-password" error={formState.errors.password?.message} {...register('password')} />
        <div className="flex items-center justify-between text-sm">
          <label className="flex items-center gap-2">
            <input type="checkbox" className="h-4 w-4 accent-brand-700" {...register('remember')} /> จดจำฉันไว้
          </label>
          <Link to="/forgot-password" className="text-brand-700 hover:underline dark:text-brand-300">
            ลืมรหัสผ่าน?
          </Link>
        </div>
        <Button type="submit" className="w-full justify-center" loading={formState.isSubmitting}>
          เข้าสู่ระบบ
        </Button>
        <p className="text-center text-sm text-slate-500">
          ยังไม่มีบัญชี?{' '}
          <Link to="/register" className="font-medium text-brand-700 hover:underline dark:text-brand-300">
            สมัครใช้งานฟรี
          </Link>
        </p>
      </form>
    </AuthLayout>
  );
}
