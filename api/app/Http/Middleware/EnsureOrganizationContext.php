<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves which organization this request is acting within, and proves the
 * caller belongs to it.
 *
 * Every tenant-scoped query in the application names its organization
 * explicitly (see the `BelongsToOrganization` trait for why a global scope was
 * rejected). This middleware is what guarantees there is always a *verified*
 * organization to name — resolved from the request, checked against the
 * membership table, then bound into the container so controllers, policies and
 * form requests all read the same value.
 *
 * Membership failure returns 404, not 403: confirming that an organization
 * exists to someone who is not in it is a small information leak, and there is
 * no reason to hand it out.
 */
final class EnsureOrganizationContext
{
    public const string HEADER = 'X-Organization';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $organization = $this->resolve($request, $user);

        if ($organization === null) {
            $this->fail(
                'organization_required',
                'Specify the organization with the X-Organization header.',
                Response::HTTP_BAD_REQUEST,
                $request,
            );
        }

        if (! $user->belongsToOrganization($organization)) {
            $this->fail(
                'organization_not_found',
                'That organization does not exist or you are not a member of it.',
                Response::HTTP_NOT_FOUND,
                $request,
            );
        }

        app()->instance(Organization::class, $organization);
        $request->attributes->set('organization', $organization);

        return $next($request);
    }

    private function resolve(Request $request, User $user): ?Organization
    {
        // 1. An explicit route parameter always wins.
        $routeOrganization = $request->route('organization');
        if ($routeOrganization instanceof Organization) {
            return $routeOrganization;
        }

        // 2. The header, accepting either the slug or the numeric id.
        $header = $request->headers->get(self::HEADER);
        if (is_string($header) && trim($header) !== '') {
            $header = trim($header);

            return Organization::query()
                ->when(
                    ctype_digit($header),
                    fn ($query) => $query->whereKey((int) $header),
                    fn ($query) => $query->where('slug', $header),
                )
                ->first();
        }

        // 3. Unambiguous fallback: if the user belongs to exactly one
        //    organization there is nothing to choose between. With two or more,
        //    guessing would silently write incidents into the wrong tenant, so
        //    we refuse instead.
        $memberships = $user->memberships()->get();

        return $memberships->count() === 1
            ? Organization::query()->find($memberships->first()?->organization_id)
            : null;
    }

    private function fail(string $code, string $message, int $status, Request $request): never
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $request->headers->get(AssignRequestId::HEADER),
            ],
        ], $status));
    }
}
