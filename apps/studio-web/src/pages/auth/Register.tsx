import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, useNavigate } from 'react-router';
import { useState } from 'react';
import { api, ApiError, ensureCsrf } from '../../lib/api.ts';
import { useAuthActions } from '../../lib/auth.tsx';
import { Button } from '../../components/ui/Button.tsx';
import { Field } from '../../components/ui/Field.tsx';
import { ErrorState } from '../../components/ui/misc.tsx';
import { AuthLayout } from './AuthLayout.tsx';

const schema = z
  .object({
    name: z.string().min(1, 'กรุณากรอกชื่อ').max(120),
    email: z.string().email('กรุณากรอกอีเมลให้ถูกต้อง'),
    organizationName: z.string().max(120).optional(),
    password: z.string().min(10, 'รหัสผ่านอย่างน้อย 10 ตัวอักษร').regex(/[A-Za-z]/, 'ต้องมีตัวอักษร').regex(/\d/, 'ต้องมีตัวเลข'),
    password_confirmation: z.string(),
    acceptTerms: z.literal(true, { message: 'กรุณายอมรับเงื่อนไขการใช้งานและนโยบายความเป็นส่วนตัว' }),
  })
  .refine((v) => v.password === v.password_confirmation, { message: 'รหัสผ่านไม่ตรงกัน', path: ['password_confirmation'] });
type Values = z.infer<typeof schema>;

export default function Register() {
  const navigate = useNavigate();
  const { refresh } = useAuthActions();
  const [error, setError] = useState<string | null>(null);
  const { register, handleSubmit, formState, setError: setFieldError } = useForm<Values>({ resolver: zodResolver(schema) });

  const onSubmit = async (values: Values) => {
    setError(null);
    try {
      await ensureCsrf(true);
      await api('/auth/register', { method: 'POST', body: values });
      await ensureCsrf(true);
      try {
        sessionStorage.setItem('studio-welcome', '1');
      } catch {
        /* storage unavailable: the dialog simply won't auto-open */
      }
      await refresh();
      navigate('/app', { replace: true });
    } catch (e) {
      if (e instanceof ApiError && e.code === 'validation_failed') {
        for (const [field, messages] of Object.entries(e.fields)) {
          setFieldError(field as keyof Values, { message: field === 'email' && messages[0]?.includes('taken') ? 'อีเมลนี้ถูกใช้แล้ว' : messages[0] });
        }
      } else setError(e instanceof Error ? e.message : 'สมัครใช้งานไม่สำเร็จ');
    }
  };

  return (
    <AuthLayout title="สมัครใช้งาน" subtitle="เริ่มต้นฟรี ไม่ต้องใช้บัตรเครดิต">
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
        {error ? <ErrorState message={error} /> : null}
        <Field label="ชื่อ–นามสกุล" autoComplete="name" error={formState.errors.name?.message} {...register('name')} />
        <Field label="อีเมล" type="email" autoComplete="email" error={formState.errors.email?.message} {...register('email')} />
        <Field label="ชื่อองค์กร/หน่วยงาน (ไม่บังคับ)" autoComplete="organization" error={formState.errors.organizationName?.message} {...register('organizationName')} />
        <Field label="รหัสผ่าน" type="password" autoComplete="new-password" hint="อย่างน้อย 10 ตัว มีทั้งตัวอักษรและตัวเลข" error={formState.errors.password?.message} {...register('password')} />
        <Field label="ยืนยันรหัสผ่าน" type="password" autoComplete="new-password" error={formState.errors.password_confirmation?.message} {...register('password_confirmation')} />
        <div>
          <label className="flex items-start gap-2 text-sm">
            <input type="checkbox" className="mt-1 h-4 w-4 accent-brand-700" {...register('acceptTerms')} />
            <span>ฉันยอมรับเงื่อนไขการใช้งาน และยินยอมให้ประมวลผลข้อมูลส่วนบุคคลตามนโยบายความเป็นส่วนตัว (PDPA)</span>
          </label>
          {formState.errors.acceptTerms ? <p role="alert" className="mt-1 text-xs text-red-600">{formState.errors.acceptTerms.message}</p> : null}
        </div>
        <Button type="submit" className="w-full justify-center" loading={formState.isSubmitting}>
          สร้างบัญชี
        </Button>
        <p className="text-center text-sm text-slate-500">
          มีบัญชีอยู่แล้ว?{' '}
          <Link to="/login" className="font-medium text-brand-700 hover:underline dark:text-brand-300">
            เข้าสู่ระบบ
          </Link>
        </p>
      </form>
    </AuthLayout>
  );
}
