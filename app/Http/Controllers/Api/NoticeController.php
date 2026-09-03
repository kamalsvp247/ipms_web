<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * The header notices shown to every signed-in user.
 *
 * Only authors talk to this controller — readers get the enabled texts from the
 * `notices` shared Inertia prop, so the banner costs no request of its own.
 */
class NoticeController extends Controller
{
    private const MAX_LENGTH = 2000;

    /**
     * @return array{id: int, text: string, enabled: bool, sort_order: int}
     */
    private function payload(Notice $notice): array
    {
        return [
            'id' => $notice->id,
            'text' => (string) $notice->text,
            'enabled' => (bool) $notice->is_enabled,
            'sort_order' => (int) $notice->sort_order,
        ];
    }

    public function index(): JsonResponse
    {
        Gate::authorize('notice.write');

        $notices = Notice::query()->ordered()->get()
            ->map(fn (Notice $notice): array => $this->payload($notice))
            ->all();

        return response()->json(['data' => $notices]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('notice.write');

        $validated = $this->validated($request);

        $notice = Notice::create([
            'text' => $validated['text'],
            'is_enabled' => $validated['enabled'],
            'sort_order' => (int) Notice::max('sort_order') + 1,
        ]);

        Log::info('[Notice] notice created', [
            'user_id' => $request->user()?->id,
            'notice_id' => $notice->id,
            'enabled' => $notice->is_enabled,
        ]);

        return response()->json($this->payload($notice), 201);
    }

    public function update(Request $request, Notice $notice): JsonResponse
    {
        Gate::authorize('notice.write');

        $validated = $this->validated($request);

        $notice->update([
            'text' => $validated['text'],
            'is_enabled' => $validated['enabled'],
        ]);

        Log::info('[Notice] notice updated', [
            'user_id' => $request->user()?->id,
            'notice_id' => $notice->id,
            'enabled' => $notice->is_enabled,
            'length' => mb_strlen($validated['text']),
        ]);

        return response()->json($this->payload($notice));
    }

    public function destroy(Request $request, Notice $notice): JsonResponse
    {
        Gate::authorize('notice.write');

        $notice->delete();

        Log::info('[Notice] notice deleted', [
            'user_id' => $request->user()?->id,
            'notice_id' => $notice->id,
        ]);

        return response()->json(['deleted' => true]);
    }

    /**
     * A blank notice would scroll as an empty gap in the marquee, so the text is required.
     *
     * @return array{text: string, enabled: bool}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'text' => 'required|string|max:'.self::MAX_LENGTH,
            'enabled' => 'required|boolean',
        ]);

        return [
            'text' => trim($validated['text']),
            'enabled' => (bool) $validated['enabled'],
        ];
    }
}
