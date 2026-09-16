<?php

namespace App\Filament\Hub\Pages;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Forced first-login password change, for an account created by
 * hub:create-user with a generated temp password. Modelled on Filament's own
 * ResetPassword page (same field/action shape), but for an already
 * authenticated user rather than a signed emailed link.
 *
 * A SimplePage, like Filament's own Login/ResetPassword — which means it has
 * no HasRoutes trait (only the full Page base class does), so it can't go
 * through ->pages()/discoverPages() and is registered by hand instead, via
 * HubPanelProvider's ->routes() closure. See SetPortalPassword's docblock for
 * the same limitation hit before. ROUTE_NAME is that manual route's name,
 * shared with EnsureHubPasswordIsChanged so it can exempt this page from
 * itself without a magic string in two places.
 */
class SetHubPassword extends SimplePage
{
    use RestrictsFileUploadsToSchemaComponents;

    public const ROUTE_NAME = 'filament.hub.pages.set-hub-password';

    protected static bool $shouldRegisterNavigation = false;

    public ?string $password = '';

    public ?string $passwordConfirmation = '';

    public function mount(): void
    {
        if (! Filament::auth()->user()?->must_change_password) {
            redirect()->intended(Filament::getUrl());
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label('New password')
            ->password()
            ->autocomplete('new-password')
            ->revealable(Filament::arePasswordsRevealable())
            ->required()
            ->rule(PasswordRule::default())
            ->same('passwordConfirmation');
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return TextInput::make('passwordConfirmation')
            ->label('Confirm new password')
            ->password()
            ->autocomplete('new-password')
            ->revealable(Filament::arePasswordsRevealable())
            ->required()
            ->dehydrated(false);
    }

    public function updatePassword(): void
    {
        $data = $this->form->getState();

        Filament::auth()->user()->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
        ])->save();

        Notification::make()
            ->title('Password updated')
            ->success()
            ->send();

        redirect()->intended(Filament::getUrl());
    }

    public function getTitle(): string|Htmlable
    {
        return 'Set a new password';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'You must set a new password before continuing';
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('updatePassword')
            ->footer([
                Actions::make([$this->getSubmitFormAction()])
                    ->fullWidth(),
            ]);
    }

    public function getSubmitFormAction(): Action
    {
        return Action::make('updatePassword')
            ->label('Update password')
            ->submit('updatePassword');
    }
}
