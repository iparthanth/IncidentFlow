import { Link } from 'react-router-dom';

export function NotFoundPage() {
  return (
    <div className="py-20 text-center">
      <h1 className="page-title">Page not found</h1>
      <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
        That link does not lead anywhere in IncidentFlow.
      </p>
      <Link to="/incidents" className="mt-4 inline-block text-sm font-medium underline">
        Back to incidents
      </Link>
    </div>
  );
}
