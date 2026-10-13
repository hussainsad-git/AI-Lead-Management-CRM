<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends ApiController
{
    public function index()
    {
        $q = Notification::where('user_id', $this->user()->id);

        return $this->ok((clone $q)->latest('id')->limit(30)->get(['id', 'type', 'title', 'body', 'url', 'read_at', 'created_at']),
            ['unread' => (clone $q)->whereNull('read_at')->count()]);
    }

    public function read(Request $request)
    {
        $data = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['integer']]);
        Notification::where('user_id', $this->user()->id)->whereNull('read_at')
            ->when(! empty($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))->update(['read_at' => now()]);

        return $this->ok(['read' => true]);
    }
}
