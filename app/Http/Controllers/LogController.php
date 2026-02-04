<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LogController extends Controller
{
    public function show(Request $request, ?string $date = null)
    {
        $token = env('LOG_ACCESS_TOKEN');

        if (app()->environment('production') && empty($token)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Log access is not configured',
            ], 403);
        }

        if (!empty($token) && $request->header('X-Log-Token') !== $token) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid log access token',
            ], 403);
        }

        if ($date === null || $date === 'latest') {
            $date = now()->format('Y-m-d');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid date format. Use YYYY-MM-DD.',
            ], 422);
        }

        $paths = [
            storage_path("logs/laravel-$date.log"),
            storage_path('logs/laravel.log'),
        ];

        $path = null;
        foreach ($paths as $candidate) {
            if (is_file($candidate)) {
                $path = $candidate;
                break;
            }
        }

        if (!$path) {
            return response()->json([
                'status' => 'error',
                'message' => 'Log file not found',
                'date' => $date,
            ], 404);
        }

        return response()->file($path, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Log-Date' => $date,
        ]);
    }
}