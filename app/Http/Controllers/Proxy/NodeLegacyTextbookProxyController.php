<?php

namespace App\Http\Controllers\Proxy;

use App\Http\Controllers\Controller;
use App\Services\Proxy\NodeLegacyProxyService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NodeLegacyTextbookProxyController extends Controller
{
    public function __construct(
        private readonly NodeLegacyProxyService $proxy,
    ) {}

    public function handle(Request $request, ?string $path = null): Response
    {
        $apiPath = 'admin/textbooks';

        if (is_string($path) && $path !== '') {
            $apiPath .= '/'.$path;
        }

        return $this->proxy->forward($request, $apiPath);
    }
}
