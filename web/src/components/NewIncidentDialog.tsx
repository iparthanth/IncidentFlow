import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Loader2, X } from 'lucide-react';
import { request, ApiError } from '@/lib/api-client';
import { paginated, ServiceSchema, SEVERITIES } from '@/lib/schemas';
import { serviceKeys } from '@/hooks/queryKeys';
import { useCreateIncident } from '@/hooks/useIncidents';

const ServiceListSchema = paginated(ServiceSchema);

/**
 * Report-incident dialog.
 *
 * Uses the native `<dialog>` element rather than a div-with-a-backdrop: it
 * gives focus trapping, Escape-to-close, inert background content and the right
 * ARIA semantics for free — four things that are easy to get wrong by hand and
 * that a responder using a keyboard notices immediately.
 */
export function NewIncidentDialog({ onClose }: { onClose: () => void }) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const navigate = useNavigate();
  const createIncident = useCreateIncident();

  const [form, setForm] = useState({
    title: '',
    description: '',
    impact: '',
    severity: 'sev3',
    service_id: '',
  });
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);

  const { data: services } = useQuery({
    queryKey: serviceKeys.list({ active_only: true }),
    queryFn: ({ signal }) =>
      request('/services', ServiceListSchema, { query: { active_only: true, per_page: 100 }, signal }),
  });

  useEffect(() => {
    dialogRef.current?.showModal();
  }, []);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setFieldErrors({});

    try {
      const created = await createIncident.mutateAsync({
        title: form.title,
        description: form.description || undefined,
        impact: form.impact || undefined,
        severity: form.severity,
        service_id: form.service_id ? Number(form.service_id) : null,
      });

      /**
       * Navigate before closing, not after.
       *
       * `onClose()` unmounts this component, and a `navigate()` issued from an
       * unmounted component is dropped — leaving the reporter staring at the
       * list with no idea whether their SEV-1 was filed. The route change
       * removes the dialog on its own, so there is nothing to close.
       */
      navigate(`/incidents/${created.data.id}`);
    } catch (cause) {
      if (cause instanceof ApiError && cause.isValidation) {
        setFieldErrors(cause.fieldErrors());
      } else {
        setError(cause instanceof ApiError ? cause.message : 'Could not create the incident.');
      }
    }
  }

  return (
    <dialog
      ref={dialogRef}
      onClose={onClose}
      className="w-full max-w-lg rounded-xl p-0 backdrop:bg-slate-900/40 dark:bg-slate-900 dark:text-slate-100"
    >
      <form onSubmit={handleSubmit} className="space-y-4 p-6">
        <div className="flex items-center justify-between">
          <h2 className="text-base font-semibold">Report an incident</h2>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close"
            className="rounded p-1 hover:bg-slate-100 dark:hover:bg-slate-800"
          >
            <X className="h-4 w-4" aria-hidden="true" />
          </button>
        </div>

        <div>
          <label htmlFor="title" className="block text-sm font-medium">
            What is happening?
          </label>
          <input
            id="title"
            required
            minLength={5}
            autoFocus
            value={form.title}
            onChange={(event) => setForm({ ...form, title: event.target.value })}
            placeholder="Checkout API returning 500s for all customers"
            aria-describedby={fieldErrors.title ? 'title-error' : undefined}
            className="mt-1 w-full input"
          />
          {fieldErrors.title && (
            <p id="title-error" className="mt-1 text-xs text-red-600 dark:text-red-400">
              {fieldErrors.title}
            </p>
          )}
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div>
            <label htmlFor="severity" className="block text-sm font-medium">
              Severity
            </label>
            <select
              id="severity"
              value={form.severity}
              onChange={(event) => setForm({ ...form, severity: event.target.value })}
              className="mt-1 w-full input"
            >
              {SEVERITIES.map((severity) => (
                <option key={severity} value={severity}>
                  {severity.toUpperCase().replace('SEV', 'SEV-')}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label htmlFor="service" className="block text-sm font-medium">
              Service
            </label>
            <select
              id="service"
              value={form.service_id}
              onChange={(event) => setForm({ ...form, service_id: event.target.value })}
              className="mt-1 w-full input"
            >
              <option value="">Not sure yet</option>
              {(services?.data ?? []).map((service) => (
                <option key={service.id} value={service.id}>
                  {service.name}
                </option>
              ))}
            </select>
          </div>
        </div>

        <p className="rounded-md bg-amber-50 p-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
          SEV-1 and SEV-2 page the on-call rota immediately and require a
          postmortem. Pick the severity you can defend, not the one that feels
          safest.
        </p>

        <div>
          <label htmlFor="description" className="block text-sm font-medium">
            What do we know? <span className="font-normal text-slate-400">(optional)</span>
          </label>
          <textarea
            id="description"
            rows={3}
            value={form.description}
            onChange={(event) => setForm({ ...form, description: event.target.value })}
            className="mt-1 w-full input"
          />
        </div>

        {error && (
          <p role="alert" className="rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">
            {error}
          </p>
        )}

        <div className="flex justify-end gap-2">
          <button
            type="button"
            onClick={onClose}
            className="input"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={createIncident.isPending}
            className="btn btn-primary"
          >
            {createIncident.isPending && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
            Report incident
          </button>
        </div>
      </form>
    </dialog>
  );
}
