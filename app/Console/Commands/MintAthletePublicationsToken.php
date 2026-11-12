<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Sync\Models\PrismAthletePublication;
use Illuminate\Console\Command;

class MintAthletePublicationsToken extends Command
{
    /**
     * @var string
     */
    protected $signature = 'athlete:publications-token {email : The user the token belongs to}';

    /**
     * @var string
     */
    protected $description = 'Mint a token that can only read Athlete publications, and print it once';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user has that email.');

            return self::FAILURE;
        }

        $token = $user->createToken('athlete-publications-read', [PrismAthletePublication::READ_ABILITY]);

        $this->info('Token for reading Athlete publications. It is shown once; store it now:');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
