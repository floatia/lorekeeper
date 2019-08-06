<?php namespace App\Services;

use App\Services\Service;

use Carbon\Carbon;

use DB;
use Config;
use Image;
use Notifications;
use Settings;

use App\Models\User\User;
use App\Models\Character\Character;
use App\Models\Submission\Submission;
use App\Models\Submission\SubmissionCharacter;
use App\Models\Currency\Currency;
use App\Models\Item\Item;
use App\Models\Loot\LootTable;
use App\Models\Prompt\Prompt;

class SubmissionManager extends Service
{
    public function createSubmission($data, $user)
    {
        DB::beginTransaction();

        try {
            // 1. check that the prompt can be submitted at this time
            // 2. check that the characters selected exist (are visible too)
            // 3. check that the currencies selected can be attached to characters
            $prompt = Prompt::active()->where('id', $data['prompt_id'])->with('rewards')->first();
            if(!$prompt) throw new \Exception("Invalid prompt selected.");

            // The character identification comes in both the slug field and as character IDs
            // that key the reward ID/quantity arrays. 
            // We'll need to match characters to the rewards for them.
            // First, check if the characters are accessible to begin with.
            $characters = Character::visible()->whereIn('slug', $data['slug'])->get();
            if(count($characters) != count($data['slug'])) throw new \Exception("One or more of the selected characters do not exist.");

            // Get a list of rewards, then create the submission itself
            $promptRewards = createAssetsArray();
            foreach($prompt->rewards as $reward) 
            {
                addAsset($promptRewards, $reward->reward, $reward->quantity);
            }
            $promptRewards = mergeAssetsArrays($promptRewards, $this->processRewards($data, false));
            $submission = Submission::create([
                'user_id' => $user->id,
                'prompt_id' => $prompt->id,
                'url' => $data['url'],
                'status' => 'Pending',
                'comments' => $data['comments'],
                'data' => json_encode(getDataReadyAssets($promptRewards)) // list of rewards
            ]);

            // Retrieve all currency IDs for characters
            $currencyIds = [];
            foreach($data['character_currency_id'] as $c)
            {
                foreach($c as $currencyId) $currencyIds[] = $currencyId;
            }
            array_unique($currencyIds);
            $currencies = Currency::whereIn('id', $currencyIds)->where('is_character_owned', 1)->get()->keyBy('id');

            // Attach characters
            foreach($characters as $c) 
            {
                // Users might not pass in clean arrays (may contain redundant data) so we need to clean that up
                $assets = $this->processRewards($data + ['character_id' => $c->id, 'currencies' => $currencies], true);

                // Now we have a clean set of assets (redundant data is gone, duplicate entries are merged)
                // so we can attach the character to the submission
                SubmissionCharacter::create([
                    'character_id' => $c->id,
                    'submission_id' => $submission->id,
                    'data' => json_encode(getDataReadyAssets($assets))
                ]);
            }

            return $this->commitReturn($submission);
        } catch(\Exception $e) { 
            dd($e->getMessage());
            $this->setError('error', $e->getMessage());
        }
        return $this->rollbackReturn(false);
    }

    private function processRewards($data, $isCharacter, $isStaff = false)
    {
        if($isCharacter)
        {
            $assets = createAssetsArray(true);
            if(isset($data['character_currency_id'][$data['character_id']]) && isset($data['character_quantity'][$data['character_id']]))
            {
                foreach($data['character_currency_id'][$data['character_id']] as $key => $currency)
                {
                    if($data['character_quantity'][$data['character_id']][$key]) addAsset($assets, $data['currencies'][$currency], $data['character_quantity'][$data['character_id']][$key]);
                }
            }
            return $assets;
        }
        else
        {
            $assets = createAssetsArray(false);
            // Process the additional rewards
            if(isset($data['rewardable_type']) && $data['rewardable_type'])
            {
                foreach($data['rewardable_type'] as $key => $type)
                {
                    $reward = null;
                    switch($type)
                    {
                        case 'Item':
                            $reward = Item::find($data['rewardable_id'][$key]);
                            break;
                        case 'Currency':
                            $reward = Currency::find($data['rewardable_id'][$key]);
                            if(!$reward->is_user_owned) throw new \Exception("Invalid currency selected.");
                            break;
                        case 'LootTable':
                            if (!$isStaff) break;
                            $reward = LootTable::find($data['rewardable_id'][$key]);
                            break;
                    }
                    if(!$reward) continue;
                    addAsset($assets, $reward, $data['quantity'][$key]);
                }
            }
            return $assets;
        }
    }

    public function rejectSubmission($data, $user)
    {
        DB::beginTransaction();

        try {
            // 1. check that the submission exists
            // 2. check that the submission is pending
            $submission = Submission::where('status', 'Pending')->where('id', $data['id'])->first();
            if(!$submission) throw new \Exception("Invalid submission.");

            // The only things we need to set are: 
            // 1. staff comment
            // 2. staff ID
            // 3. status
            $submission->update([
                'staff_comments' => $data['staff_comments'],
                'staff_id' => $user->id,
                'status' => 'Rejected'
            ]);

            Notifications::create('SUBMISSION_REJECTED', $submission->user, [
                'staff_url' => $user->url,
                'staff_name' => $user->name,
                'submission_id' => $submission->id,
            ]);

            return $this->commitReturn($submission);
        } catch(\Exception $e) { 
            dd($e->getMessage());
            $this->setError('error', $e->getMessage());
        }
        return $this->rollbackReturn(false);
    }

    public function approveSubmission($data, $user)
    {
        DB::beginTransaction();

        try {
            // 1. check that the submission exists
            // 2. check that the submission is pending
            $submission = Submission::where('status', 'Pending')->where('id', $data['id'])->first();
            if(!$submission) throw new \Exception("Invalid submission.");

            // The character identification comes in both the slug field and as character IDs
            // that key the reward ID/quantity arrays. 
            // We'll need to match characters to the rewards for them.
            // First, check if the characters are accessible to begin with.
            $characters = Character::visible()->whereIn('slug', $data['slug'])->get();
            if(count($characters) != count($data['slug'])) throw new \Exception("One or more of the selected characters do not exist.");

            // Get the updated set of rewards
            $rewards = $this->processRewards($data, false, true);

            // Logging data
            $promptLogType = 'Prompt Rewards';
            $promptData = [
                'data' => 'Received rewards for submission (<a href="'.$submission->viewUrl.'">#'.$submission->id.'</a>)'
            ];

            // Distribute user rewards
            if(!$rewards = fillUserAssets($rewards, $user, $submission->user, $promptLogType, $promptData)) throw new \Exception("Failed to distribute rewards to user.");
            
            // Retrieve all currency IDs for characters
            $currencyIds = [];
            foreach($data['character_currency_id'] as $c)
                foreach($c as $currencyId) $currencyIds[] = $currencyId;
            array_unique($currencyIds);
            $currencies = Currency::whereIn('id', $currencyIds)->where('is_character_owned', 1)->get()->keyBy('id');

            // We're going to remove all characters from the submission and reattach them with the updated data
            $submission->characters()->delete();
            
            // Distribute character rewards
            foreach($characters as $c) 
            {
                // Users might not pass in clean arrays (may contain redundant data) so we need to clean that up
                $assets = $this->processRewards($data + ['character_id' => $c->id, 'currencies' => $currencies], true);

                if(!fillCharacterAssets($assets, $user, $c, $promptLogType, $promptData)) throw new \Exception("Failed to distribute rewards to character.");
                
                SubmissionCharacter::create([
                    'character_id' => $c->id,
                    'submission_id' => $submission->id,
                    'data' => json_encode(getDataReadyAssets($assets))
                ]);
            }

            // Increment user submission count
            $user->settings->submission_count++;
            $user->settings->save();

            // Finally, set: 
            // 1. staff ID
            // 2. status
            // 3. final rewards
            $submission->update([
                'staff_id' => $user->id,
                'status' => 'Approved',
                'data' => json_encode(getDataReadyAssets($rewards))
            ]);

            Notifications::create('SUBMISSION_APPROVED', $submission->user, [
                'staff_url' => $user->url,
                'staff_name' => $user->name,
                'submission_id' => $submission->id,
            ]);

            return $this->commitReturn($submission);
        } catch(\Exception $e) { 
            dd($e->getMessage());
            $this->setError('error', $e->getMessage());
        }
        return $this->rollbackReturn(false);
    }
    
}