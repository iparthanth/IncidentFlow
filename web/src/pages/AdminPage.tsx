import { useQuery } from '@tanstack/react-query';
import { request } from '@/lib/api-client';
import { AuditLogSchema, MemberSchema, paginated } from '@/lib/schemas';
import { auditKeys, memberKeys } from '@/hooks/queryKeys';
import { formatRelative, titleCase } from '@/lib/format';

const MemberListSchema = paginated(MemberSchema);
const AuditListSchema = paginated(AuditLogSchema);

export function AdminPage() {
  const { data: members } = useQuery({
    queryKey: memberKeys.list({}),
    queryFn: ({ signal }) => request('/members', MemberListSchema, { query: { per_page: 100 }, signal }),
  });

  const { data: auditLogs } = useQuery({
    queryKey: auditKeys.list({}),
    queryFn: ({ signal }) => request('/audit-logs', AuditListSchema, { query: { per_page: 50 }, signal }),
  });

  return (
    <div className="space-y-6">
      <h1 className="page-title">Administration</h1>

      <section aria-labelledby="members-heading">
        <h2 id="members-heading" className="mb-2 text-sm font-semibold">
          Members
        </h2>
        <div className="overflow-x-auto card">
          <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
            <thead className="bg-slate-50 dark:bg-slate-800/50">
              <tr>
                <th scope="col" className="px-3 py-2 text-left font-medium">Name</th>
                <th scope="col" className="px-3 py-2 text-left font-medium">Email</th>
                <th scope="col" className="px-3 py-2 text-left font-medium">Role</th>
                <th scope="col" className="px-3 py-2 text-left font-medium">Joined</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {(members?.data ?? []).map((member) => (
                <tr key={member.id}>
                  <td className="px-3 py-2">{member.user?.name ?? '—'}</td>
                  <td className="px-3 py-2 text-slate-500 dark:text-slate-400">{member.user?.email ?? '—'}</td>
                  <td className="px-3 py-2">{member.role_label}</td>
                  <td className="px-3 py-2 text-slate-500 dark:text-slate-400">
                    {formatRelative(member.joined_at)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section aria-labelledby="audit-heading">
        <h2 id="audit-heading" className="mb-2 text-sm font-semibold">
          Audit log
        </h2>
        <p className="mb-2 text-xs text-slate-500 dark:text-slate-400">
          Append-only and permanent. Entries cannot be edited or deleted by
          anyone, including administrators — an audit log an administrator can
          rewrite proves nothing about administrators.
        </p>

        <ul className="divide-y divide-slate-100 card dark:divide-slate-800">
          {(auditLogs?.data ?? []).map((entry) => (
            <li key={entry.id} className="px-3 py-2 text-sm">
              <div className="flex flex-wrap items-baseline gap-x-2">
                <span className="font-medium">{titleCase(entry.action)}</span>
                <span className="text-slate-500 dark:text-slate-400">
                  by {entry.actor.name ?? entry.actor.email ?? 'system'}
                </span>
                <span className="text-xs text-slate-400">{formatRelative(entry.created_at)}</span>
              </div>
              {entry.request_id && (
                <p className="font-mono text-[10px] text-slate-300 dark:text-slate-600">
                  {entry.request_id}
                </p>
              )}
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}
