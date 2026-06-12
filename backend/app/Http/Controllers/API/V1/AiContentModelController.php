<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AiContentModel;
use Illuminate\Http\Request;

class AiContentModelController extends Controller
{
    public function index(Request $request)
    {
        $query = AiContentModel::query()->orderByDesc('created_at');
        if ($channel = $request->query('channel')) {
            $query->where('channel', $channel);
        }
        $items = $query->get();
        return ApiResponse::success($items);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'channel'  => ['required', 'in:sms,voice,email,whatsapp'],
            'content'  => ['required', 'string', 'max:10000'],
            'briefing' => ['nullable', 'array', 'max:20'],
        ]);
        $model = AiContentModel::create($data);
        return ApiResponse::success($model, 'Modelo salvo', [], 201);
    }

    public function show(int $id)
    {
        $model = AiContentModel::findOrFail($id);
        return ApiResponse::success($model);
    }

    public function destroy(int $id)
    {
        $model = AiContentModel::findOrFail($id);
        $model->delete();
        return ApiResponse::success([], 'Modelo excluído');
    }
}
