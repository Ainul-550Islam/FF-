<?php

namespace App\Http\Controllers;

class AdminAnalyticsController extends Controller
{
    public function index()
    {
        return view('admin.analytics.index');
    }

    public function tournaments()
    {
        return view('admin.analytics.tournaments');
    }

    public function tournament()
    {
        return view('admin.analytics.tournament');
    }

    public function financial()
    {
        return view('admin.analytics.financial');
    }

    public function disputes()
    {
        return view('admin.analytics.disputes');
    }

    public function security()
    {
        return view('admin.analytics.security');
    }

    public function support()
    {
        return view('admin.analytics.support');
    }
}
