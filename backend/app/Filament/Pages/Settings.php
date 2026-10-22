<?php

namespace App\Filament\Pages;

use App\Services\Settings as SettingsStore;
use Filament\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

// Every amount and business detail the site uses. Changes apply immediately.
class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Platform';

    protected static string $view = 'filament.pages.settings';

    public ?array $data = [];

    public function mount(): void
    {
        $s = SettingsStore::all();
        $s['spilloverLevels'] = array_map('strval', $s['spilloverLevels']);
        $this->form->fill($s);
    }

    public function form(Form $form): Form
    {
        $money = fn (string $key, string $label) => TextInput::make($key)->label($label)->integer()->minValue(0)->required()->prefix('₦');
        $number = fn (string $key, string $label) => TextInput::make($key)->label($label)->integer()->minValue(0)->required();

        return $form->statePath('data')->schema([
            Section::make('Activation')->schema([
                Grid::make(2)->schema([$money('activationFee', 'Activation fee'), $money('welcomeBonus', 'Welcome Smash Bonus')]),
            ]),
            Section::make('Activation bank account')->description('Shown to members on the payment screen.')->schema([
                Grid::make(3)->schema([
                    TextInput::make('activationBankName')->label('Bank name')->maxLength(100),
                    TextInput::make('activationAccountName')->label('Account name')->maxLength(120),
                    TextInput::make('activationAccountNumber')->label('Account number')->maxLength(32),
                ]),
            ]),
            Section::make('Referrals')->schema([
                Grid::make(2)->schema([
                    $money('referralBonus', 'Direct referral bonus'),
                    TagsInput::make('spilloverLevels')->label('Spillover per level, from level 2 (₦)')
                        ->nestedRecursiveRules(['integer', 'min:0'])->placeholder('Add an amount'),
                ]),
            ]),
            Section::make('Rewards')->schema([
                Grid::make(2)->schema([
                    $money('marketplaceReward', 'Approved listing reward'),
                    $money('fbrReward', 'Friday Bonus reward'),
                    $number('fbrMaxWinners', 'Friday Bonus winners per week'),
                    TextInput::make('fbrOpenHour')->label('Friday Bonus opens at (hour, Lagos time)')->integer()->minValue(0)->maxValue(23)->required(),
                ]),
            ]),
            Section::make('Wallet')->schema([
                Grid::make(2)->schema([$money('minWithdrawal', 'Minimum withdrawal (0 = none)'), $money('usdToNgn', 'Naira per $1')]),
            ]),
            Section::make('Marketplace orders')->schema([
                Grid::make(2)->schema([
                    TextInput::make('instalmentDepositPercent')->label('Instalment deposit')->integer()->minValue(1)->maxValue(100)->suffix('%')->required(),
                    $number('instalmentDays', 'Days to pay the balance'),
                    $number('autoReleaseDays', 'Pay the seller automatically this many days after shipping'),
                    TextInput::make('marketplaceFeePercent')->label('Platform fee on sales')->integer()->minValue(0)->maxValue(100)->suffix('%')->required(),
                ]),
            ]),
            Section::make('AI Assistant')->schema([$number('assistantDailyLimit', 'Messages per member per day')]),
            Section::make('Support')->schema([
                Grid::make(2)->schema([
                    TextInput::make('supportUrl')->label('Support link (Telegram / WhatsApp)')->url()->maxLength(300),
                    TextInput::make('supportEmail')->label('Support email')->email()->maxLength(300),
                ]),
            ]),
        ]);
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('Save settings')->submit('save')];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        foreach ($data as $key => $value) {
            $default = SettingsStore::DEFAULTS[$key] ?? null;
            if (is_array($default)) {
                $data[$key] = array_values(array_map('intval', (array) $value));
            } elseif (is_int($default)) {
                $data[$key] = (int) $value;
            } else {
                $data[$key] = (string) ($value ?? '');
            }
        }
        SettingsStore::save($data);
        Notification::make()->success()->title('Settings saved')->send();
    }
}
