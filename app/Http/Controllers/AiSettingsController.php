<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAiSettingsRequest;
use App\Models\AiSetting;
use App\Services\TenantContext;

class AiSettingsController extends Controller
{
    public function show()
    {
        $tenant = TenantContext::getTenant();
        $settings = AiSetting::query()
            ->where('tenant_id', $tenant->id)
            ->first();

        if (!$settings) {
            $settings = AiSetting::query()
                ->whereNull('tenant_id')
                ->first();

            if ($settings) {
                $settings->tenant_id = $tenant->id;
                $settings->save();
            } else {
                $settings = AiSetting::query()->firstOrCreate(
                    ['tenant_id' => $tenant->id],
                    []
                );
            }
        }

        return response()->json($settings);
    }

    public function update(UpdateAiSettingsRequest $request)
    {
        $tenant = TenantContext::getTenant();
        $settings = AiSetting::query()->firstOrCreate(
            ['tenant_id' => $tenant->id],
            []
        );
        $settings->fill($request->validated());
        $settings->save();

        return response()->json($settings);
    }
}
