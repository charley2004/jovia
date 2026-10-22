<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\Order;
use App\Services\Orders;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MarketplaceController extends Controller
{
    public const CATEGORIES = ['Fashion', 'Electronics', 'Phones & Accessories', 'Home & Kitchen', 'Beauty & Health', 'Food & Groceries', 'Books & Education', 'Services', 'Other'];

    private static function listing(Listing $l, ?int $me = null): array
    {
        return [
            'id' => $l->id,
            'mine' => $me !== null ? $l->user_id === $me : null,
            'quantity' => $l->quantity,
            'title' => $l->title,
            'description' => $l->description,
            'price' => $l->price,
            'category' => $l->category,
            'image' => $l->imageUrl(),
            'status' => $l->status,
            'adminNote' => $l->admin_note,
            'createdAt' => $l->created_at,
            'seller' => $l->relationLoaded('user') ? $l->user->shortName() : null,
        ];
    }

    private static function order(Order $o, int $me): array
    {
        $isSeller = $o->seller_id === $me;
        // The seller only sees where to deliver once the order is fully paid
        $showDelivery = ! $isSeller || in_array($o->status, ['PAID', 'SHIPPED', 'COMPLETED', 'DISPUTED'], true);

        return [
            'id' => $o->id, 'role' => $isSeller ? 'seller' : 'buyer', 'title' => $o->title, 'price' => $o->price,
            'paidAmount' => $o->paid_amount, 'balanceDue' => $o->price - $o->paid_amount, 'plan' => $o->plan, 'status' => $o->status,
            'dueAt' => $o->due_at, 'shippedAt' => $o->shipped_at, 'disputeReason' => $o->dispute_reason, 'adminNote' => $o->admin_note,
            'createdAt' => $o->created_at,
            'counterpart' => $isSeller ? $o->buyer->shortName() : $o->seller->shortName(),
            'delivery' => $showDelivery ? ['name' => $o->delivery_name, 'phone' => $o->delivery_phone, 'address' => $o->delivery_address] : null,
        ];
    }

    public function categories()
    {
        return response()->json(['categories' => self::CATEGORIES]);
    }

    // Approved, in-stock listings from all members
    public function index(Request $request)
    {
        $q = Listing::with('user')->where('status', 'APPROVED')->where('quantity', '>', 0);
        if (in_array($request->query('category'), self::CATEGORIES, true)) {
            $q->where('category', $request->query('category'));
        }
        $me = $request->user()->id;

        return response()->json(['items' => $q->latest('reviewed_at')->limit(60)->get()->map(fn ($l) => self::listing($l, $me))]);
    }

    public function mine(Request $request)
    {
        return response()->json(['items' => $request->user()->listings()->latest('id')->get()->map(fn ($l) => self::listing($l))]);
    }

    public function store(Request $request)
    {
        $data = $this->check($request, [
            'title' => 'required|string|min:3|max:120',
            'description' => 'required|string|min:10|max:1000',
            'price' => 'required|integer|min:1',
            'quantity' => 'nullable|integer|min:1|max:999',
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'image' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:3072',
        ], [
            'title.*' => 'Give your product a title.',
            'description.*' => 'Describe the product in at least 10 characters.',
            'price.*' => 'Enter the price in whole naira.',
            'quantity.*' => 'Quantity must be between 1 and 999.',
            'category.*' => 'Choose a category.',
            'image.mimes' => 'Upload a JPG, PNG or WebP image.',
            'image.max' => 'Image must be 3 MB or smaller.',
        ]);
        $path = $request->hasFile('image') ? $request->file('image')->store('listings', 'public') : null;
        $listing = Listing::create([
            'user_id' => $request->user()->id, 'title' => trim($data['title']), 'description' => trim($data['description']),
            'price' => (int) $data['price'], 'quantity' => (int) ($data['quantity'] ?? 1), 'category' => $data['category'], 'image_path' => $path,
        ])->refresh();

        return response()->json(['listing' => self::listing($listing)], 201);
    }

    public function destroy(Request $request, int $id)
    {
        $l = $request->user()->listings()->find($id);
        if (! $l) {
            abort(404, 'Listing not found.');
        }
        if ($l->status === 'APPROVED') {
            abort(400, 'Approved listings can only be removed by support.');
        }
        if ($l->image_path) {
            Storage::disk('public')->delete($l->image_path);
        }
        $l->delete();

        return response()->json(['ok' => true]);
    }

    // ── Orders ──
    public function orderTerms()
    {
        $s = Settings::all();

        return response()->json(['depositPercent' => $s['instalmentDepositPercent'], 'instalmentDays' => $s['instalmentDays'], 'autoReleaseDays' => $s['autoReleaseDays']]);
    }

    public function orders(Request $request)
    {
        $me = $request->user()->id;

        return response()->json(['items' => Order::with(['buyer', 'seller'])->where('buyer_id', $me)->latest('id')->limit(50)->get()->map(fn ($o) => self::order($o, $me))]);
    }

    public function sales(Request $request)
    {
        $me = $request->user()->id;

        return response()->json(['items' => Order::with(['buyer', 'seller'])->where('seller_id', $me)->latest('id')->limit(50)->get()->map(fn ($o) => self::order($o, $me))]);
    }

    public function placeOrder(Request $request)
    {
        $data = $this->check($request, [
            'listingId' => 'required|integer',
            'plan' => ['required', Rule::in(['FULL', 'INSTALMENT'])],
            'deliveryName' => 'required|string|min:2|max:120',
            'deliveryPhone' => ['required', 'string', 'max:32', function ($a, $v, $fail) {
                if (strlen(preg_replace('/\D/', '', $v)) < 7) {
                    $fail('Enter a delivery phone number.');
                }
            }],
            'deliveryAddress' => 'required|string|min:10|max:500',
        ], [
            'deliveryName.*' => 'Enter the name for delivery.',
            'deliveryPhone.required' => 'Enter a delivery phone number.',
            'deliveryAddress.*' => 'Enter the full delivery address.',
        ]);
        $order = Orders::place($request->user(), $data);

        return response()->json(['order' => ['id' => $order->id, 'status' => $order->status]], 201);
    }

    public function payBalance(Request $request, int $id)
    {
        Orders::payBalance($request->user(), $id);

        return response()->json(['ok' => true]);
    }

    public function cancel(Request $request, int $id)
    {
        Orders::cancel($request->user(), $id);

        return response()->json(['ok' => true]);
    }

    public function ship(Request $request, int $id)
    {
        Orders::ship($request->user(), $id);

        return response()->json(['ok' => true]);
    }

    public function received(Request $request, int $id)
    {
        Orders::confirmReceived($request->user(), $id);

        return response()->json(['ok' => true]);
    }

    public function dispute(Request $request, int $id)
    {
        $data = $this->check($request, ['reason' => 'required|string|min:5|max:500'], ['reason.*' => 'Describe the problem.']);
        Orders::dispute($request->user(), $id, trim($data['reason']));

        return response()->json(['ok' => true]);
    }
}
