import { Head, useForm } from '@inertiajs/react';
import { Button, Field, Icon, Input, Notice } from '../Components/ui';

export default function Login() {
  const { data, setData, post, errors, processing } = useForm({ email: '', password: '' });

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 p-4">
      <Head title="Log in" />
      <div className="w-full max-w-md">
        <div className="mb-6 flex flex-col items-center text-center">
          <span className="mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-brand-600 text-white shadow-sm"><Icon name="academic" className="h-6 w-6" /></span>
          <h1 className="text-2xl font-bold tracking-tight text-slate-900">Student Services</h1>
          <p className="text-sm text-slate-600">Sign in to your portal</p>
        </div>

        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
          <form onSubmit={(e) => { e.preventDefault(); post('/login'); }} className="space-y-4">
            {errors.email && <Notice kind="error">{errors.email}</Notice>}
            <Field label="Email" required>
              <Input type="email" autoComplete="username" value={data.email} onChange={(e) => setData('email', e.target.value)} />
            </Field>
            <Field label="Password" required>
              <Input type="password" autoComplete="current-password" value={data.password} onChange={(e) => setData('password', e.target.value)} />
            </Field>
            <Button type="submit" loading={processing} className="w-full">{processing ? 'Signing in…' : 'Log in'}</Button>
          </form>
        </div>

        <p className="mt-4 text-center text-xs text-slate-500">
          Demo: student@ / registration@ / cashier@ / department@ / admin@ + school.test · password <b>password</b>
        </p>
      </div>
    </div>
  );
}
