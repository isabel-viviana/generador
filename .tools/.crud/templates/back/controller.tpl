<?php

namespace App\Http\Controllers;

use App\Models\{{CLASS_NAME}};
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
{{USE_CLOUDINARY}}
{{USE_VALIDATION_RULE}}
class {{CLASS_NAME}}Controller extends Controller
{
    public function index(): JsonResponse
    {
        $items = {{INDEX_QUERY}};

        return response()->json($items, 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
{{STORE_VALIDATION_RULES}}
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }
        $data = $validator->validated();
{{CLOUDINARY_STORE_UPLOAD}}
        $item = {{CLASS_NAME}}::create($data);

        return response()->json($item, 201);
    }

    public function show($id): JsonResponse
    {
        $item = {{SHOW_QUERY}};
        if (!$item) {
            return response()->json(['mensaje' => 'Registro no encontrado.'], 404);
        }

        return response()->json($item, 200);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = {{CLASS_NAME}}::find($id);
        if (!$item) {
            return response()->json(['mensaje' => 'Registro no encontrado.'], 404);
        }

        $validator = Validator::make($request->all(), [
{{UPDATE_VALIDATION_RULES}}
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }
        $data = $validator->validated();
{{CLOUDINARY_UPDATE_UPLOAD}}
        $item->update($data);

        return response()->json($item, 200);
    }

    public function destroy($id): JsonResponse
    {
        $item = {{CLASS_NAME}}::find($id);
        if (!$item) {
            return response()->json(['mensaje' => 'Registro no encontrado.'], 404);
        }
        $item->delete();

        return response()->json(['mensaje' => 'Registro eliminado exitosamente.'], 200);
    }
}
