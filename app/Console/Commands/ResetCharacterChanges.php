<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Settings;
use App\Models\User\User;
use DB;
use Config;
use App\Models\User\UserSettings;


class ResetCharacterChanges extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset-character-changes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resets character changes to setting amount.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        //
        // update all user models with foraging stamina
        UserSettings::all()->each(function($character_changes) {
            $character_changes->character_changes = Settings::get('character_changes');
            $character_changes->save();
        });
    }
}
