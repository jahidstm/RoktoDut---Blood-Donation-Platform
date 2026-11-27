<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Anyone can register an organisation, so its admin gets nothing until a site admin approves it. */
class EnsureOrganizationVerified
{
    public function handle(Request $request, Closure $next)
    {
        $org = $request->user()?->organization;

        $approved = $org
            && $org->status !== 'rejected'
            && ($org->is_verified || in_array($org->status, ['approved', 'verified'], true));

        abort_unless($approved, 403, 'আপনার অর্গানাইজেশন এখনো অনুমোদিত হয়নি। অ্যাডমিন যাচাই করার পর এই অংশ ব্যবহার করতে পারবেন।');

        return $next($request);
    }
}
