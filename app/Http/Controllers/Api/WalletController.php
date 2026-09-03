<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WalletController extends Controller
{
    /**
     * Methods this personal wallet page accepts. Deliberately separate from
     * App\Support\AutoPaymentMethods — that list drives the per-account auto-payment automation
     * (and includes bKash as an unsupported future entry); this is just a personal reference book.
     *
     * @var list<string>
     */
    public const METHODS = ['rocket', 'nagad'];

    public function index(Request $request)
    {
        return $request->user()->wallets()->orderBy('method')->orderBy('created_at')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'method' => ['required', 'string', Rule::in(self::METHODS)],
            'wallet_number' => ['required', 'string', 'max:20', self::walletNumberRule()],
            'pin' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:64'],
        ]);

        $wallet = $request->user()->wallets()->create($data);

        return response()->json($wallet, 201);
    }

    /**
     * Rocket wallet numbers are exactly 12 digits; other methods are unconstrained beyond length.
     */
    private static function walletNumberRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value !== null && request()->input('method') === 'rocket' && ! preg_match('/^\d{12}$/', $value)) {
                $fail('Rocket wallet numbers must be exactly 12 digits.');
            }
        };
    }

    public function show(Request $request, Wallet $wallet)
    {
        abort_unless($wallet->user_id === $request->user()->id, 404);

        return response()->json([
            ...$wallet->toArray(),
            'pin' => $wallet->pin,
        ]);
    }

    public function update(Request $request, Wallet $wallet)
    {
        abort_unless($wallet->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'method' => ['required', 'string', Rule::in(self::METHODS)],
            'wallet_number' => ['required', 'string', 'max:20', self::walletNumberRule()],
            'pin' => ['nullable', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:64'],
        ]);

        // Same contract as Account::auto_payment_pin — an untouched PIN field means "keep the
        // stored one" rather than blanking a live credential.
        if (! filled($data['pin'] ?? null)) {
            unset($data['pin']);
        }

        $wallet->update($data);

        return $wallet;
    }

    public function destroy(Request $request, Wallet $wallet)
    {
        abort_unless($wallet->user_id === $request->user()->id, 404);

        $wallet->delete();

        return response()->json(null, 204);
    }
}
