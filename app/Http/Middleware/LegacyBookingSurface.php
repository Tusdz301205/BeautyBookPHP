<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class LegacyBookingSurface
{
 public function handle(Request $request,Closure $next){abort_unless(config('beautybook.legacy_ui',false),404);return $next($request);}
}
