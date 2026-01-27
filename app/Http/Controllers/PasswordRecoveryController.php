<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Mail\PasswordRecoveryCodeMail;
use App\Models\PasswordResetCode;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class PasswordRecoveryController extends Controller
{
    /**
     * Request a password recovery code.
     *
     * @group Authentication
     * @bodyParam email string required Example: jane@example.com
     * @response 200 {
     *   "status": "ok",
     *   "message": "If the email exists, a recovery code was sent."
     * }
     */
    public function forgot(ForgotPasswordRequest $request)
    {
        $user = User::query()->where('email', $request->input('email'))->first();

        if ($user) {
            PasswordResetCode::query()->where('user_id', $user->id)->delete();

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            PasswordResetCode::query()->create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
                'expires_at' => Carbon::now()->addMinutes(10),
            ]);

            Mail::to($user->email)->send(new PasswordRecoveryCodeMail($code));
        }

        return response()->json([
            'status' => 'ok',
            'message' => 'If the email exists, a recovery code was sent.',
        ]);
    }

    /**
     * Reset the user password using the recovery code.
     *
     * @group Authentication
     * @bodyParam email string required Example: jane@example.com
     * @bodyParam code string required Example: 123456
     * @bodyParam password string required Example: newpassword123
     * @bodyParam password_confirmation string required Example: newpassword123
     * @response 200 {
     *   "status": "success",
     *   "message": "Password updated successfully"
     * }
     */
    public function reset(ResetPasswordRequest $request)
    {
        $user = User::query()->where('email', $request->input('email'))->first();

        if (!$user) {
            return response()->json([
                'message' => 'Invalid recovery code.',
            ], 422);
        }

        $codeRecord = PasswordResetCode::query()
            ->where('user_id', $user->id)
            ->first();

        if (!$codeRecord || $codeRecord->expires_at->isPast()) {
            if ($codeRecord) {
                $codeRecord->delete();
            }

            return response()->json([
                'message' => 'Invalid recovery code.',
            ], 422);
        }

        if (!Hash::check($request->input('code'), $codeRecord->code_hash)) {
            return response()->json([
                'message' => 'Invalid recovery code.',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->input('password')),
        ]);

        $user->tokens()->delete();
        $codeRecord->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Password updated successfully',
        ]);
    }
}
