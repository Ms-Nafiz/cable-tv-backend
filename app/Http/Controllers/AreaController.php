<?php

namespace App\Http\Controllers;

use App\Models\Area;
use Illuminate\Http\Request;

class AreaController extends Controller
{
    public function index()
    {
        $areas = Area::withCount(['customers', 'collectors'])->get();
        return response()->json($areas);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:areas,name',
        ]);

        $area = Area::create([
            'name' => $request->name,
        ]);

        return response()->json($area, 201);
    }

    public function update(Request $request, Area $area)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:areas,name,' . $area->id,
        ]);

        $area->update([
            'name' => $request->name,
        ]);

        return response()->json($area);
    }

    public function destroy(Area $area)
    {
        if ($area->customers()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete area with assigned customers.',
            ], 422);
        }

        $area->delete();
        return response()->json(['message' => 'Area deleted successfully']);
    }
}
