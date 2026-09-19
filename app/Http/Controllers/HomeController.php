<?php

namespace App\Http\Controllers;

use App\Support\HomepageContent;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The homepage: copy comes from the admin-edited content blocks, layout from
 * the page — so an admin's edit shows without a deploy.
 */
class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Welcome', ['content' => HomepageContent::build()]);
    }
}
