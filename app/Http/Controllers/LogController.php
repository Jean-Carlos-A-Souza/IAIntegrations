<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

        if (!is_readable($path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Log file not readable',
                'date' => $date,
            ], 403);
        }

        try {
            $content = file_get_contents($path);
            if ($content === false) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to read log file',
                    'date' => $date,
                ], 500);
            }

            return response($content, 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Log-Date' => $date,
            ]);
        } catch (\Throwable $e) {
            Log::error('Log file response failed', [
                'path' => $path,
                'date' => $date,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to read log file',
                'date' => $date,
            ], 500);
        }
    }
}
