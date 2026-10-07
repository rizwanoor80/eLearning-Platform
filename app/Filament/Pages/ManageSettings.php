<?php

namespace App\Filament\Pages;

use App\Actions\RecordAuditLog;
use App\Enums\SettingGroup;
use App\Models\Setting;
use App\Services\Scheduling\BookingLeadTime;
use App\Services\Scheduling\SlotCalculator;
use App\Support\Facades\Settings;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Settings editor (CP1): tabs platform / booking / site / mail / features, keys and
 * groups from config('settings.groups') so there is one key list. Every
 * changed key writes an audit row (before/after) against its `settings` row.
 *
 * @property-read Schema $form
 */
class ManageSettings extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static ?string $navigationLabel = 'Settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected string $view = 'filament.pages.manage-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * Value casting by key: everything not listed is a plain string/null.
     * Money-like values (payout_min) are integer fils — never floats.
     *
     * @var list<string>
     */
    private const INTEGER_KEYS = [
        'commission_pct', 'trial_discount_pct', 'cancel_window_hours', 'student_grace_min', 'tutor_grace_min',
        'report_due_hours', 'auto_release_hours', 'payout_weekday', 'payout_min', 'booking_min_lead_hours',
        'booking_max_days', 'recurring_horizon_weeks', 'recurring_charge_lead_hours',
        'recurring_pause_after_failures', 'recurring_tutor_end_notice_days', 'vat_pct',
    ];

    /** @var list<string> */
    private const BOOLEAN_KEYS = ['match_requests', 'reviews', 'messaging', 'allow_immediate_booking'];

    public function mount(): void
    {
        $this->form->fill($this->currentValues());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Settings')
                    ->tabs([
                        Tab::make('Platform')->schema([
                            TextInput::make('commission_pct')->numeric()->integer()->minValue(0)->maxValue(100)->required(),
                            TextInput::make('trial_discount_pct')->numeric()->integer()->minValue(0)->maxValue(99)->required(),
                            TextInput::make('cancel_window_hours')->numeric()->integer()->minValue(0)->required(),
                            TextInput::make('student_grace_min')->numeric()->integer()->minValue(0)->required(),
                            TextInput::make('tutor_grace_min')->numeric()->integer()->minValue(0)->required(),
                            TextInput::make('report_due_hours')->numeric()->integer()->minValue(0)->required(),
                            TextInput::make('auto_release_hours')->numeric()->integer()->minValue(0)->required(),
                            TextInput::make('payout_weekday')->numeric()->integer()->minValue(0)->maxValue(6)->required(),
                            TextInput::make('payout_min')->label('Payout minimum (fils)')->numeric()->integer()->minValue(0)->required(),
                            TextInput::make('recurring_horizon_weeks')->numeric()->integer()->minValue(1)->required(),
                            TextInput::make('recurring_charge_lead_hours')->numeric()->integer()->minValue(0)->required(),
                            TagsInput::make('recurring_retry_hours')->helperText('Hours before the lesson, e.g. 36 and 24'),
                            TextInput::make('recurring_pause_after_failures')->numeric()->integer()->minValue(1)->required(),
                            TextInput::make('recurring_tutor_end_notice_days')->numeric()->integer()->minValue(0)->required(),
                            TextInput::make('vat_pct')->numeric()->integer()->minValue(0)->maxValue(100)->required(),
                            TextInput::make('currency_code')->required()->maxLength(3),
                            TextInput::make('currency_symbol')->required()->maxLength(8),
                            TextInput::make('default_timezone')->required()->rule('timezone:all'),
                        ])->columns(2),
                        Tab::make('Booking')->schema([
                            TextInput::make('booking_min_lead_hours')
                                ->label('Default lead time (hours)')
                                ->helperText('Used for a tutor who has not chosen one, and rounded up to the nearest allowed value below.')
                                ->numeric()->integer()->minValue(0)->maxValue(BookingLeadTime::MAX_HOURS)->required(),
                            TextInput::make('booking_max_days')->numeric()->integer()->minValue(1)->maxValue(SlotCalculator::MAX_HORIZON_DAYS)->required(),
                            TagsInput::make('lead_time_options')
                                ->label('Lead times a tutor can choose (hours)')
                                ->helperText('Whole hours, e.g. 0, 4, 8, 12, 24. 0 means "book right away" and only appears while it is switched on below.')
                                ->required()
                                ->nestedRecursiveRules(['integer', 'min:0', 'max:'.BookingLeadTime::MAX_HOURS]),
                            Toggle::make('allow_immediate_booking')
                                ->label('Allow "book right away" (lead time 0)')
                                ->helperText('On for testing and rehearsal; off on production unless you decide otherwise. While off, a tutor who chose 0 is held to the smallest other allowed value.'),
                        ])->columns(2),
                        Tab::make('Site')->schema([
                            TextInput::make('site_name')->required()->maxLength(255),
                            TextInput::make('tagline')->maxLength(255),
                            FileUpload::make('logo_path')->label('Logo')->image()->disk('public')->directory('branding'),
                            FileUpload::make('favicon_path')->label('Favicon')->image()->disk('public')->directory('branding'),
                            TextInput::make('contact_email')->email(),
                            TextInput::make('contact_phone'),
                            Textarea::make('contact_address'),
                            KeyValue::make('social_links'),
                            Textarea::make('footer_text'),
                            Textarea::make('head_scripts')
                                ->helperText('Rendered raw inside <head> on every page (analytics snippets). Admin-trusted input — only paste code you trust.')
                                ->rows(6),
                            TextInput::make('legal_entity_name'),
                            TextInput::make('legal_entity_trn'),
                            Textarea::make('legal_entity_address'),
                        ])->columns(2),
                        Tab::make('Mail')->schema([
                            TextInput::make('from_name')->helperText('Shown as the sender of every email. Defaults to TrusTutor when empty.'),
                            TextInput::make('from_address')->email(),
                            TextInput::make('reply_to')->email(),
                            TextInput::make('support_address')->email(),
                            Textarea::make('email_footer'),
                        ]),
                        Tab::make('Features')->schema([
                            Toggle::make('match_requests'),
                            Toggle::make('reviews'),
                            Toggle::make('messaging'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $admin = auth()->user();

        /** @var array<string, list<string>> $groups */
        $groups = config('settings.groups', []);

        foreach ($groups as $groupValue => $keys) {
            $group = SettingGroup::from($groupValue);

            foreach ($keys as $key) {
                $new = $this->cast($key, $state[$key] ?? null);
                $old = Settings::get($key);

                if ($new === $old) {
                    continue;
                }

                Settings::set($key, $new, $group, $admin);

                $row = Setting::query()->where('key', $key)->firstOrFail();
                app(RecordAuditLog::class)($admin, 'setting.updated', $row, ['value' => $old], ['value' => $new]);
            }
        }

        Notification::make()->title('Settings saved')->success()->send();
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        /** @var array<string, list<string>> $groups */
        $groups = config('settings.groups', []);

        $values = [];

        foreach ($groups as $keys) {
            foreach ($keys as $key) {
                $values[$key] = Settings::get($key);
            }
        }

        return $values;
    }

    private function cast(string $key, mixed $value): mixed
    {
        if (in_array($key, self::INTEGER_KEYS, true)) {
            return (int) $value;
        }

        if (in_array($key, self::BOOLEAN_KEYS, true)) {
            return (bool) $value;
        }

        if ($key === 'recurring_retry_hours') {
            return array_map('intval', (array) $value);
        }

        if ($key === 'lead_time_options') {
            $options = array_values(array_unique(array_map('intval', (array) $value)));
            sort($options);

            return $options;
        }

        if ($key === 'social_links') {
            return (array) $value;
        }

        return ($value === '' || $value === null) ? null : $value;
    }
}
