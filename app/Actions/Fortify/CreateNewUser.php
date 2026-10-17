<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Player;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): Player
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::default()],
        ])->validate();

        return Player::create([
            'name' => $input['name'],
            'username' => $input['username'],
            'email' => empty($input['email']) ? null : $input['email'],
            'password' => $input['password'],
            'balance' => 1000000.00,
            'is_government' => false,
        ]);
    }
}
