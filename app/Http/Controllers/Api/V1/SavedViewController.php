<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\SavedView;
use Illuminate\Http\Request;

class SavedViewController extends ApiController
{
    public function index(Request $request)
    {
        return $this->ok(SavedView::where('user_id', $this->user()->id)->where('module', $request->input('module', 'leads'))->orderBy('name')->get(['id', 'name', 'filters', 'columns']));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['module' => ['required', 'in:leads'], 'name' => ['required', 'string', 'max:80'], 'filters' => ['present', 'array'], 'columns' => ['nullable', 'array'], 'columns.*' => ['string', 'max:40']]);
        $view = SavedView::updateOrCreate(['user_id' => $this->user()->id, 'module' => $data['module'], 'name' => $data['name']],
            ['filters' => $data['filters'], 'columns' => $data['columns'] ?? null]);

        return $this->ok($view->only(['id', 'name', 'filters', 'columns']), [], 201);
    }

    public function destroy(SavedView $view)
    {
        abort_unless($view->user_id === $this->user()->id, 404);
        $view->delete();

        return $this->ok(['deleted' => true]);
    }
}
