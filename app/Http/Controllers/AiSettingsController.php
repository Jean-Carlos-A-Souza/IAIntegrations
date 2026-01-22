<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAiSettingsRequest;
use App\Models\AiSetting;

class AiSettingsController extends Controller
{
    public function show()
    {
        $settings = AiSetting::query()->first();

        return response()->json($settings);
    }

    public function update(UpdateAiSettingsRequest $request)
    {
        $settings = AiSetting::query()->firstOrCreate([], []);
        $settings->fill($request->validated());
        $settings->save();

        return response()->json($settings);
    }
}
