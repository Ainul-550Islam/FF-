<?php
namespace App\Http\Controllers;
use App\Models\Tournament;
use Illuminate\Http\Request;
class SitemapController extends Controller
{
    public function sitemap(Request $request)
    {
        $tournaments = Tournament::where('status','!=','draft')->orderBy('updated_at','desc')->limit(100)->get();
        $content = view('seo.sitemap', compact('tournaments'))->render();
        return response($content, 200, ['Content-Type'=>'application/xml']);
    }
    public function robots() { return response("User-agent: *\nAllow: /\nSitemap: ".url('/sitemap.xml')."\n", 200, ['Content-Type'=>'text/plain']); }
}
