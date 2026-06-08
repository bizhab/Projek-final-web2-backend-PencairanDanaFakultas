<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * FR-1.1: Login dengan restriksi domain @uin-alauddin.ac.id.
     * FR-1.4: Deteksi otomatis role saat login berhasil.
     *
     * Validasi:
     * - Email wajib @uin-alauddin.ac.id untuk semua role
     * - Akun harus sudah terdaftar (tidak ada pendaftaran mandiri - FR-1.2)
     * - Password harus cocok
     */
    public function login(Request $request)
    {
        try {
            $request->validate([
                'email'    => 'required|email',
                'password' => 'required|string',
            ]);

            // FR-1.1: Restriksi domain email @uin-alauddin.ac.id
            $email  = strtolower(trim($request->email));
            $domain = substr(strrchr($email, '@'), 1);

            if ($domain !== 'uin-alauddin.ac.id') {
                return response()->json([
                    'message' => 'Hanya email dengan domain @uin-alauddin.ac.id yang diizinkan untuk login ke sistem SAPDF.',
                ], 403);
            }

            // FR-1.2: Cari akun yang sudah terdaftar (tidak ada pendaftaran mandiri)
            $user = User::where('email', $email)->first();

            if (! $user) {
                return response()->json([
                    'message' => 'Akun dengan email ' . $email . ' belum terdaftar di sistem SAPDF. Hubungi Admin Fakultas untuk pendaftaran.',
                ], 403);
            }

            // Validasi password
            if (! Hash::check($request->password, $user->password)) {
                throw ValidationException::withMessages([
                    'email' => ['Email atau password salah.'],
                ]);
            }

            // Hapus token lama sebelum buat yang baru (satu session aktif)
            $user->tokens()->delete();

            $token = $user->createToken('sapdf-token')->plainTextToken;

            // FR-1.4: Role terdeteksi otomatis dari data user di database
            return response()->json([
                'message' => 'Login berhasil.',
                'token'   => $token,
                'user'    => $this->formatUser($user),
            ]);

        } catch (ValidationException $e) {
            // Re-throw validation exception agar Laravel handle formatnya
            throw $e;
        } catch (\Exception $e) {
            // NFR-3.2: Error handling informatif (tidak menampilkan kode teknis ke user)
            return response()->json([
                'message' => 'Terjadi kesalahan saat proses login. Silakan coba lagi.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Logout dan hapus semua token aktif user ini.
     */
    public function logout(Request $request)
    {
        try {
            $request->user()->tokens()->delete();

            return response()->json(['message' => 'Logout berhasil.']);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Terjadi kesalahan saat logout.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Data user yang sedang login.
     */
    public function me(Request $request)
    {
        try {
            $user = $request->user();

            return response()->json(array_merge(
                $this->formatUser($user),
                ['unread_notifications' => $user->unreadNotifications()->count()]
            ));
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil data user.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * FR-1.3: Ganti password - bisa dipakai oleh semua role.
     */
    public function changePassword(Request $request)
    {
        try {
            $request->validate([
                'current_password' => 'required|string',
                'new_password'     => 'required|string|min:8|confirmed',
            ]);

            $user = $request->user();

            if (! Hash::check($request->current_password, $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Password saat ini tidak sesuai.'],
                ]);
            }

            $user->update(['password' => Hash::make($request->new_password)]);

            // Paksa logout semua device lain setelah ganti password
            $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

            return response()->json(['message' => 'Password berhasil diubah.']);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengubah password.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Format response data user yang konsisten.
     */
    private function formatUser(User $user): array
    {
        return [
            'id'                => $user->id,
            'name'              => $user->name,
            'email'             => $user->email,
            'role'              => $user->role,
            'organization_name' => $user->organization_name,
            'avatar'            => $user->avatar,
        ];
    }
}
