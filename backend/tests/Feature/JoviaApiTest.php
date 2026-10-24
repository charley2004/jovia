<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TaskModule;
use App\Models\TaskSession;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\AdminActions;
use App\Services\Orders;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JoviaApiTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
    }

    private function signup(array $extra = []): User
    {
        $this->n++;
        $this->postJson('/api/auth/signup', array_merge([
            'firstName' => 'Member', 'lastName' => "Test{$this->n}", 'email' => "m{$this->n}@ex.com",
            'phone' => '+234 803 000 '.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT), 'country' => 'Nigeria',
            'password' => 'secret123', 'terms' => true,
        ], $extra))->assertCreated();
        auth()->guard('web')->logout();

        return User::where('email', $extra['email'] ?? "m{$this->n}@ex.com")->first();
    }

    // Pay and have the admin approve → active member
    private function activate(User $u): User
    {
        $this->actingAs($u)->postJson('/api/me/payments', ['senderName' => $u->name])->assertCreated();
        AdminActions::approvePayment(Payment::where('user_id', $u->id)->latest('id')->first());

        return $u->fresh();
    }

    private function member(array $extra = []): User
    {
        return $this->activate($this->signup($extra));
    }

    private function balance(User $u): int
    {
        return $u->fresh()->balance;
    }

    // ── Accounts ──

    public function test_signup_validation_and_login(): void
    {
        $this->postJson('/api/auth/signup', ['firstName' => 'A', 'lastName' => 'B', 'email' => 'bad', 'phone' => '1', 'country' => 'France', 'password' => 'x', 'terms' => true])
            ->assertStatus(400)->assertJsonStructure(['error']);
        $u = $this->signup(['email' => 'ada@ex.com', 'phone' => '+234 803 111 2222']);
        $this->postJson('/api/auth/signup', ['firstName' => 'X', 'lastName' => 'Y', 'email' => 'ada@ex.com', 'phone' => '08099999999', 'country' => 'Ghana', 'password' => 'secret123', 'terms' => true])
            ->assertStatus(409);
        $this->postJson('/api/auth/signup', ['firstName' => 'X', 'lastName' => 'Y', 'email' => 'z@ex.com', 'phone' => '0803 111 2222', 'country' => 'Ghana', 'password' => 'secret123', 'terms' => true])
            ->assertStatus(409)->assertJson(['error' => 'An account with this phone number already exists.']);

        $this->postJson('/api/auth/login', ['identifier' => 'ada@ex.com', 'password' => 'wrong'])->assertStatus(401);
        $this->postJson('/api/auth/login', ['identifier' => '08031112222', 'password' => 'secret123'])->assertOk()->assertJsonPath('user.email', 'ada@ex.com');
        $this->assertNotEquals('secret123', $u->fresh()->password);
    }

    public function test_tasks_need_activation_and_activation_pays_only_the_welcome_bonus(): void
    {
        $existing = $this->member();
        // A cached sign-up page or an old ?ref= link may still send a referral code: it is ignored
        $new = $this->signup(['referral' => 'OLDCODE1']);

        $this->actingAs($new)->getJson('/api/tasks/modules')->assertStatus(403);
        $before = $this->balance($existing);
        $this->activate($new);

        $this->assertSame(15000, $this->balance($new));
        $this->assertSame($before, $this->balance($existing)); // nobody else is paid
        $this->assertSame(['WELCOME_BONUS'], $new->transactions()->pluck('type')->all());
        $this->actingAs($new)->getJson('/api/me/referrals')->assertNotFound();
        $this->actingAs($new)->getJson('/api/auth/me')->assertJsonMissingPath('user.referralCode');
        $this->actingAs($new)->getJson('/api/me/notifications')->assertJsonPath('items.0.title', 'Your account is active');
    }

    public function test_admin_can_activate_a_member_manually(): void
    {
        // Paid but never pressed "I've paid": a payment is recorded and approved
        $quiet = $this->signup();
        AdminActions::activateManually($quiet, 'Quiet Payer', 'KUDA-123');
        $this->assertSame('ACTIVE', $quiet->fresh()->status);
        $this->assertSame(15000, $this->balance($quiet));
        $this->assertDatabaseHas('payments', ['user_id' => $quiet->id, 'status' => 'APPROVED', 'sender_name' => 'Quiet Payer', 'reference' => 'KUDA-123']);

        // Already submitted: their own pending payment is approved, no second one is created
        $submitted = $this->signup();
        $this->actingAs($submitted)->postJson('/api/me/payments', ['senderName' => 'Their Name'])->assertCreated();
        AdminActions::activateManually($submitted->fresh(), 'Ignored', null);
        $this->assertSame(1, Payment::where('user_id', $submitted->id)->count());
        $this->assertSame('APPROVED', Payment::where('user_id', $submitted->id)->value('status'));

        // Can't activate twice
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        AdminActions::activateManually($quiet->fresh(), 'Again', null);
    }

    // ── Task timers ──

    private function beat(User $u, string $id, int $seconds): void
    {
        for ($i = 0; $i < $seconds; $i += 5) {
            $this->travel(5)->seconds();
            $this->actingAs($u)->postJson("/api/tasks/sessions/{$id}/beat")->assertOk();
        }
    }

    public function test_video_session_pays_only_after_the_timer_and_only_once(): void
    {
        $u = $this->member();
        $this->actingAs($u)->postJson('/api/tasks/sessions', ['moduleKey' => 'video', 'durationSeconds' => 5])->assertStatus(400);
        $id = $this->actingAs($u)->postJson('/api/tasks/sessions', ['moduleKey' => 'video', 'durationSeconds' => 20])->assertCreated()->json('session.id');
        $this->actingAs($u)->postJson("/api/tasks/sessions/{$id}/complete")->assertStatus(400);

        $this->beat($u, $id, 20);
        $this->actingAs($u)->postJson("/api/tasks/sessions/{$id}/complete")->assertOk()->assertJsonPath('session.reward', 2000);
        $this->assertSame(17000, $this->balance($u));
        $this->actingAs($u)->postJson("/api/tasks/sessions/{$id}/complete")->assertStatus(409);
    }

    public function test_time_away_from_the_page_is_not_counted(): void
    {
        $u = $this->member();
        $id = $this->actingAs($u)->postJson('/api/tasks/sessions', ['moduleKey' => 'video', 'durationSeconds' => 20])->json('session.id');
        $this->beat($u, $id, 10);
        $this->travel(60)->seconds(); // member left the page for a minute
        $this->actingAs($u)->postJson("/api/tasks/sessions/{$id}/beat")->assertJsonPath('session.activeSeconds', 10);
        $this->actingAs($u)->postJson("/api/tasks/sessions/{$id}/complete")->assertStatus(400);
    }

    public function test_daily_cap_limits_rewards(): void
    {
        // At most ₦12,000 a day from tasks: video ₦4,000 + gameplay ₦6,000 + music ₦2,000, Meta off
        $u = $this->member();
        $run = function (string $module, int $secs) use ($u) {
            $id = $this->actingAs($u)->postJson('/api/tasks/sessions', ['moduleKey' => $module, 'durationSeconds' => $secs])->assertCreated()->json('session.id');
            $this->beat($u, $id, $secs);

            return $this->actingAs($u)->postJson("/api/tasks/sessions/{$id}/complete")->assertOk()->json('session.reward');
        };
        $this->assertSame(4000, $run('video', 120)); // a 2-minute video is worth ₦12,000 but pays only what's left of the cap
        $this->assertSame(6000, $run('game', 30));
        $this->assertSame(2000, $run('music', 60));
        foreach (['video' => 20, 'game' => 30, 'music' => 60] as $module => $secs) {
            $this->actingAs($u)->postJson('/api/tasks/sessions', ['moduleKey' => $module, 'durationSeconds' => $secs])->assertStatus(400);
        }
        $this->actingAs($u)->postJson('/api/tasks/sessions', ['moduleKey' => 'meta', 'durationSeconds' => 30])->assertNotFound();
        $this->assertSame(15000 + 12000, $this->balance($u));
    }

    public function test_meta_task_with_proof_is_paid_after_review(): void
    {
        $u = $this->member();
        TaskModule::whereKey('meta')->update(['active' => true]); // switched off by default until real tasks are added
        $task = ContentItem::create([
            'module_key' => 'meta', 'title' => 'Follow Jovia on Instagram', 'provider' => 'Jovia',
            'instructions' => 'Follow @jovianetwork, then enter your Instagram username.',
            'requires_proof' => true, 'proof_label' => 'your Instagram username',
        ]);
        $id = $this->actingAs($u)->postJson('/api/tasks/sessions', ['moduleKey' => 'meta', 'durationSeconds' => 30, 'contentId' => $task->id])->json('session.id');
        $this->beat($u, $id, 30);
        $this->actingAs($u)->postJson("/api/tasks/sessions/{$id}/complete", [])->assertStatus(400);
        $this->actingAs($u)->postJson("/api/tasks/sessions/{$id}/complete", ['proof' => '@amaka.n'])
            ->assertOk()->assertJsonPath('pendingReview', true)->assertJsonPath('session.reward', 3000);
        $this->assertSame(15000, $this->balance($u));
        $meta = collect($this->actingAs($u)->getJson('/api/tasks/modules')->json('modules'))->firstWhere('key', 'meta');
        $this->assertSame(0, $meta['remainingToday']); // reserved reward counts toward the cap

        AdminActions::approveTaskProof(TaskSession::find($id));
        $this->assertSame(18000, $this->balance($u));
    }

    // ── Wallet ──

    public function test_withdrawals_and_rejection_refund(): void
    {
        $u = $this->member();
        $this->actingAs($u)->postJson('/api/me/withdrawals', ['amount' => 1000])->assertStatus(400)->assertJson(['error' => 'Add a bank account first.']);
        $this->actingAs($u)->putJson('/api/me/bank', ['bankName' => 'GTBank', 'accountNumber' => '0123456789', 'accountName' => 'Member Test'])->assertOk();
        $this->actingAs($u)->postJson('/api/me/withdrawals', ['amount' => 999999])->assertStatus(400);
        $this->actingAs($u)->postJson('/api/me/withdrawals', ['amount' => 5000])->assertCreated();
        $this->assertSame(10000, $this->balance($u));
        AdminActions::rejectWithdrawal(Withdrawal::first(), 'test');
        $this->assertSame(15000, $this->balance($u));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        AdminActions::markWithdrawalPaid(Withdrawal::first(), null); // already rejected
    }

    public function test_refund_only_for_accounts_never_activated(): void
    {
        $u = $this->signup();
        $this->actingAs($u)->postJson('/api/me/payments', ['senderName' => 'Xavier Obi'])->assertCreated();
        $this->actingAs($u)->postJson('/api/me/payments/refund', ['reason' => 'Not activated'])->assertOk()->assertJsonPath('payment.status', 'REFUND_REQUESTED');
        AdminActions::markRefunded(Payment::first(), 'Sent back');
        $this->assertSame('REFUNDED', Payment::first()->status);
        $active = $this->member();
        $this->actingAs($active)->postJson('/api/me/payments/refund')->assertStatus(400);
    }

    // ── Marketplace ──

    private function approvedListing(User $seller, int $price, int $qty = 1): int
    {
        $id = $this->actingAs($seller)->post('/api/marketplace', [
            'title' => 'Bluetooth speaker', 'description' => 'Portable speaker with 10 hours battery.', 'price' => $price,
            'quantity' => $qty, 'category' => 'Electronics', 'image' => UploadedFile::fake()->create('speaker.jpg', 120, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('listing.id');
        AdminActions::approveListing(Listing::find($id));

        return $id;
    }

    private function order(User $buyer, int $listingId, string $plan = 'FULL')
    {
        return $this->actingAs($buyer)->postJson('/api/marketplace/orders', [
            'listingId' => $listingId, 'plan' => $plan, 'deliveryName' => 'Buyer', 'deliveryPhone' => '0801234567', 'deliveryAddress' => '12 Allen Avenue, Ikeja, Lagos',
        ]);
    }

    public function test_full_order_dispute_refund(): void
    {
        $seller = $this->member();
        $buyer = $this->member();
        $other = $this->member();
        $listing = $this->approvedListing($seller, 8000);
        $this->assertSame(18000, $this->balance($seller)); // listing reward
        Storage::disk('public')->assertExists(Listing::find($listing)->image_path);

        $this->order($seller, $listing)->assertStatus(400);
        $orderId = $this->order($buyer, $listing)->assertCreated()->assertJsonPath('order.status', 'PAID')->json('order.id');
        $this->assertSame(7000, $this->balance($buyer));
        $this->order($other, $listing)->assertStatus(400)->assertJson(['error' => 'This product is sold out.']);
        $this->actingAs($seller)->getJson('/api/marketplace/sales')->assertJsonPath('items.0.delivery.address', '12 Allen Avenue, Ikeja, Lagos');

        $this->actingAs($buyer)->postJson("/api/marketplace/orders/{$orderId}/received")->assertStatus(400);
        $this->actingAs($seller)->postJson("/api/marketplace/orders/{$orderId}/ship")->assertOk();
        $this->actingAs($buyer)->postJson("/api/marketplace/orders/{$orderId}/dispute", ['reason' => 'Arrived broken'])->assertOk();
        Orders::resolve($orderId, 'refund', 'Seller could not prove delivery');
        $this->assertSame(15000, $this->balance($buyer));
        $this->assertSame(18000, $this->balance($seller));
    }

    public function test_instalment_order_and_release_to_seller(): void
    {
        $seller = $this->member();
        $buyer = $this->member();
        $listing = $this->approvedListing($seller, 10000, 3);
        $orderId = $this->order($buyer, $listing, 'INSTALMENT')->assertJsonPath('order.status', 'AWAITING_BALANCE')->json('order.id');
        $this->assertSame(10000, $this->balance($buyer));
        $this->assertNull($this->actingAs($seller)->getJson('/api/marketplace/sales')->json('items.0.delivery'));
        $this->actingAs($seller)->postJson("/api/marketplace/orders/{$orderId}/ship")->assertStatus(400);

        $this->actingAs($buyer)->postJson("/api/marketplace/orders/{$orderId}/pay-balance")->assertOk();
        $this->assertSame(5000, $this->balance($buyer));
        $this->actingAs($seller)->postJson("/api/marketplace/orders/{$orderId}/ship")->assertOk();
        $this->actingAs($buyer)->postJson("/api/marketplace/orders/{$orderId}/received")->assertOk();
        $this->assertSame(18000 + 10000, $this->balance($seller));
    }

    public function test_cancel_and_scheduler_settlement(): void
    {
        $seller = $this->member();
        $buyer = $this->member();
        $listing = $this->approvedListing($seller, 4000, 5);

        $o1 = $this->order($buyer, $listing)->json('order.id');
        $this->actingAs($buyer)->postJson("/api/marketplace/orders/{$o1}/cancel")->assertOk();
        $this->assertSame(15000, $this->balance($buyer));

        // Overdue instalment → cancelled, deposit back, item back in stock
        $this->order($buyer, $listing, 'INSTALMENT')->assertCreated();
        $this->assertSame(13000, $this->balance($buyer));
        // Shipped order never confirmed → released after 7 days
        $o3 = $this->order($buyer, $listing)->json('order.id');
        $this->actingAs($seller)->postJson("/api/marketplace/orders/{$o3}/ship")->assertOk();

        $this->travel(15)->days();
        $this->assertSame(['cancelled' => 1, 'released' => 1], Orders::settle());
        $this->assertSame(13000 - 4000 + 2000, $this->balance($buyer));
        $this->assertSame(18000 + 4000, $this->balance($seller));
        $this->assertSame(['cancelled' => 0, 'released' => 0], Orders::settle()); // never pays twice
        $this->assertSame(4, Listing::find($listing)->quantity);
    }

    // ── Friday Bonus ──

    public function test_friday_bonus_claims(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 13:00', 'Africa/Lagos')); // a Friday, after 12:00
        $a = $this->member();
        $fbr = $this->actingAs($a)->getJson('/api/fbr')->assertJsonPath('state', 'open')->json();
        $this->actingAs($a)->postJson('/api/fbr/claim', ['code' => '000000'])->assertStatus(400);
        $this->actingAs($a)->postJson('/api/fbr/claim', ['code' => $fbr['code']])->assertOk();
        $this->assertSame(25000, $this->balance($a));
        $this->actingAs($a)->postJson('/api/fbr/claim', ['code' => $fbr['code']])->assertStatus(400);
        $this->actingAs($a)->getJson('/api/fbr')->assertJsonPath('state', 'claimed');
    }

    // ── Assistant, site, admin access ──

    public function test_assistant_without_key_and_static_site(): void
    {
        config(['services.gemini.key' => null]);
        $u = $this->member();
        $this->actingAs($u)->getJson('/api/assistant/status')->assertJsonPath('available', false);
        $this->actingAs($u)->postJson('/api/assistant', ['messages' => [['role' => 'user', 'content' => 'Hi']]])->assertStatus(503);

        $this->get('/')->assertOk()->assertHeader('Content-Security-Policy');
        $this->get('/dashboard.html')->assertOk();
        $this->get('/signup')->assertOk(); // extensionless URL
        $this->get('/backend/.env')->assertNotFound();
        $this->get('/backend-node/package.json')->assertNotFound();
        $this->assertStringEndsWith('blog'.DIRECTORY_SEPARATOR.'index.html', $this->get('/blog/')->assertOk()->baseResponse->getFile()->getPathname()); // folder → index.html
        $this->get('/blog/is-jovia-network-legit')->assertOk();
        $this->get('/fonts/inter.woff2')->assertOk()->assertHeader('Content-Type', 'font/woff2');
        $this->assertStringNotContainsString('fonts.googleapis', $this->get('/')->headers->get('Content-Security-Policy'));
    }

    public function test_contact_form_saves_messages_for_the_admin(): void
    {
        $msg = ['name' => 'Ada Obi', 'email' => 'Ada@Example.com', 'topic' => 'WITHDRAWAL', 'message' => 'My withdrawal from Monday has not arrived yet.'];
        $this->postJson('/api/contact', $msg)->assertCreated();
        $this->assertDatabaseHas('contact_messages', ['email' => 'ada@example.com', 'topic' => 'WITHDRAWAL', 'status' => 'NEW', 'user_id' => null]);

        // Signed-in members are linked to their account
        $u = $this->member();
        $this->actingAs($u)->postJson('/api/contact', array_merge($msg, ['email' => $u->email]))->assertCreated();
        $this->assertDatabaseHas('contact_messages', ['user_id' => $u->id]);

        // Validation, and bots that fill the hidden field are dropped silently
        $this->postJson('/api/contact', array_merge($msg, ['message' => 'Hi']))->assertStatus(400)->assertJsonPath('error', 'Tell us a little more (at least 10 characters).');
        $this->postJson('/api/contact', array_merge($msg, ['topic' => 'NOPE']))->assertStatus(400);
        $this->postJson('/api/contact', array_merge($msg, ['website' => 'http://spam.example']))->assertCreated();
        $this->assertSame(2, \App\Models\ContactMessage::count());

        // At most 5 messages an hour from one address (the 5 above all count)
        $this->postJson('/api/contact', $msg)->assertStatus(429)->assertJsonPath('error', "You've sent several messages already. We'll reply to those first; please try again later.");
    }

    public function test_assistant_uses_gemini_without_sending_personal_data(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-3.8-flash']);
        \Illuminate\Support\Facades\Http::fake([
            'generativelanguage.googleapis.com/*' => \Illuminate\Support\Facades\Http::sequence()
                ->push(['candidates' => [['content' => ['parts' => [['text' => 'thinking…', 'thought' => true], ['text' => 'Open the Wallet tab to see your balance.']]], 'finishReason' => 'STOP']]])
                ->push(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429),
        ]);
        $u = $this->member(['firstName' => 'Chiamaka', 'email' => 'private.person@ex.com']);

        $this->actingAs($u)->postJson('/api/assistant', ['messages' => [
            ['role' => 'user', 'content' => 'Hi'], ['role' => 'assistant', 'content' => 'Hello!'], ['role' => 'user', 'content' => 'What is my balance?'],
        ]])->assertOk()->assertJson(['reply' => 'Open the Wallet tab to see your balance.']);

        \Illuminate\Support\Facades\Http::assertSent(function ($req) use ($u) {
            $body = $req->body();

            return str_contains($req->url(), 'models/gemini-3.8-flash:generateContent')
                && $req->hasHeader('x-goog-api-key', 'test-key')
                && $req['contents'][1]['role'] === 'model'
                && str_contains($req['system_instruction']['parts'][0]['text'], 'Jovia AI Assistant')
                && ! str_contains($body, 'Chiamaka') && ! str_contains($body, 'private.person') && ! str_contains($body, $u->phone);
        });

        // Free-tier quota reached → friendly "busy" message
        $this->actingAs($u)->postJson('/api/assistant', ['messages' => [['role' => 'user', 'content' => 'Hi again']]])->assertStatus(503);
    }

    public function test_only_admins_reach_filament(): void
    {
        $member = $this->member();
        $this->actingAs($member)->get('/admin')->assertForbidden();
        $admin = User::where('role', 'ADMIN')->first();
        $this->flushSession();
        $this->actingAs($admin)->get('/admin')->assertOk();
        foreach (['payments', 'withdrawals', 'listings', 'task-reviews', 'orders', 'users', 'task-modules'] as $page) {
            $this->actingAs($admin)->get("/admin/{$page}")->assertOk();
        }
        $this->actingAs($admin)->get('/admin/settings')->assertOk();
        $this->actingAs($admin)->get('/admin/task-modules/video/edit')->assertOk();
    }
}
