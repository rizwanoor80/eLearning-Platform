<?php

namespace App\Filament\Pages;

use App\Actions\Ops\IssueOpsStatusToken;
use App\Actions\Ops\RevokeOpsStatusToken;
use App\Actions\Ops\UpdateOpsStatusAllowList;
use App\Exceptions\OpsStatusTokenException;
use App\Models\OpsStatusToken;
use App\Models\User;
use App\Services\Ops\AllowedIps;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;

/**
 * Admin → Settings → Operations (R193): generate, rotate and revoke the bearer token that opens
 * `GET /ops/status`, and edit its optional IP allow-list. The plaintext token exists in exactly one
 * place after it is made: the `$revealedToken` property below, until the admin dismisses it or leaves
 * the page. It is never stored, never put in a notification, and never written to the audit log.
 *
 * @property-read Schema $form
 */
class ManageOperations extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static ?string $navigationLabel = 'Operations';

    protected static ?string $navigationParentItem = 'Settings';

    protected static ?string $title = 'Operations';

    protected static ?string $slug = 'operations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected string $view = 'filament.pages.manage-operations';

    /** @var array<string, mixed> */
    public array $data = [];

    /** The plaintext of the token just generated; shown once. Locked so a client cannot set it. */
    #[Locked]
    public ?string $revealedToken = null;

    public function mount(): void
    {
        $this->form->fill([
            'allowed_ips' => implode("\n", $this->activeToken()->allowed_ips ?? []),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('allowed_ips')
                    ->label('Allowed addresses (optional)')
                    ->helperText('One IP address or CIDR range per line. Empty means any address. Matched against the address the request arrives from; behind a load balancer that is the balancer unless trusted proxies are configured.')
                    ->rows(4),
            ])
            ->statePath('data');
    }

    public function activeToken(): ?OpsStatusToken
    {
        return OpsStatusToken::current();
    }

    public function dismissToken(): void
    {
        $this->revealedToken = null;
    }

    public function saveAllowList(): void
    {
        try {
            $allowed = AllowedIps::parse($this->data['allowed_ips'] ?? null);

            app(UpdateOpsStatusAllowList::class)($this->admin(), $allowed);
        } catch (OpsStatusTokenException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Allow-list saved')->success()->send();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate token')
                ->icon(Heroicon::OutlinedKey)
                ->visible(fn (): bool => $this->activeToken() === null)
                ->requiresConfirmation()
                ->modalHeading('Generate the status token?')
                ->modalDescription('The token is shown once, right after this. Copy it into the cloud environment; it cannot be shown again.')
                ->action(fn () => $this->issue()),
            Action::make('rotate')
                ->label('Rotate token')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->visible(fn (): bool => $this->activeToken() !== null)
                ->requiresConfirmation()
                ->modalHeading('Rotate the status token?')
                ->modalDescription('The current token stops working at once, and everything that holds it must be given the new one.')
                ->action(fn () => $this->issue()),
            Action::make('revoke')
                ->label('Revoke token')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->visible(fn (): bool => $this->activeToken() !== null)
                ->requiresConfirmation()
                ->modalHeading('Revoke the status token?')
                ->modalDescription('GET /ops/status answers 404 to everyone until a new token is generated.')
                ->action(fn () => $this->revoke()),
        ];
    }

    private function issue(): void
    {
        try {
            $allowed = AllowedIps::parse($this->data['allowed_ips'] ?? null);

            $issued = app(IssueOpsStatusToken::class)($this->admin(), $allowed);
        } catch (OpsStatusTokenException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->revealedToken = $issued->plainToken;

        Notification::make()->title('Status token generated — copy it now')->success()->send();
    }

    private function revoke(): void
    {
        try {
            $revoked = app(RevokeOpsStatusToken::class)($this->admin());
        } catch (OpsStatusTokenException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->revealedToken = null;

        Notification::make()->title($revoked ? 'Status token revoked' : 'There was no active token')->success()->send();
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
