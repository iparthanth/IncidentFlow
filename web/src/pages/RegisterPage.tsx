import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, Loader2 } from 'lucide-react';
import { useAuth } from '@/auth/useAuth';
import { ApiError } from '@/lib/api-client';

export function RegisterPage() {
  const { register } = useAuth();
  const [form, setForm] = useState({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    organization_name: '',
  });
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setFieldErrors({});
    setSubmitting(true);

    try {
      await register(form);
    } catch (cause) {
      if (cause instanceof ApiError && cause.isValidation) {
        // Server-side validation is the authority. Mirroring its rules in the
        // client would guarantee the two drift apart; surfacing its answer
        // against the right field cannot.
        setFieldErrors(cause.fieldErrors());
      } else {
        setError(cause instanceof ApiError ? cause.message : 'Registration failed. Try again.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  const fields = [
    { name: 'name', label: 'Your name', type: 'text', autoComplete: 'name' },
    { name: 'organization_name', label: 'Organization name', type: 'text', autoComplete: 'organization' },
    { name: 'email', label: 'Email', type: 'email', autoComplete: 'username' },
    { name: 'password', label: 'Password', type: 'password', autoComplete: 'new-password' },
    { name: 'password_confirmation', label: 'Confirm password', type: 'password', autoComplete: 'new-password' },
  ] as const;

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-10 dark:bg-slate-950">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex items-center gap-2">
          <AlertTriangle className="h-6 w-6 text-red-600" aria-hidden="true" />
          <h1 className="page-title">Create your organization</h1>
        </div>

        <form
          onSubmit={handleSubmit}
          className="space-y-4 card p-6"
        >
          {fields.map((field) => (
            <div key={field.name}>
              <label htmlFor={field.name} className="block text-sm font-medium">
                {field.label}
              </label>
              <input
                id={field.name}
                type={field.type}
                autoComplete={field.autoComplete}
                required
                value={form[field.name]}
                onChange={(event) => setForm({ ...form, [field.name]: event.target.value })}
                aria-invalid={Boolean(fieldErrors[field.name])}
                aria-describedby={fieldErrors[field.name] ? `${field.name}-error` : undefined}
                className="mt-1 w-full input"
              />
              {fieldErrors[field.name] && (
                <p id={`${field.name}-error`} className="mt-1 text-xs text-red-600 dark:text-red-400">
                  {fieldErrors[field.name]}
                </p>
              )}
            </div>
          ))}

          <p className="text-xs text-slate-500 dark:text-slate-400">
            Passwords must be at least 12 characters. Length beats punctuation —
            a passphrase is both stronger and easier to type at 3am.
          </p>

          {error && (
            <p role="alert" className="rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">
              {error}
            </p>
          )}

          <button
            type="submit"
            disabled={submitting}
            className="btn btn-primary w-full"
          >
            {submitting && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
            Create account
          </button>

          <p className="text-center text-sm text-slate-500 dark:text-slate-400">
            Already have one?{' '}
            <Link to="/login" className="font-medium underline">
              Sign in
            </Link>
          </p>
        </form>
      </div>
    </div>
  );
}
