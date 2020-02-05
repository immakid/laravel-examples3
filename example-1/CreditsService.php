<?php

namespace App\Services\CreditsService;


use App\Models\Credit;
use App\Models\CreditType;
use App\Models\Post;
use App\Models\Payment;

use App\Models\PostCredit;
use App\Models\User;
use Carbon\Carbon;

use App\Helpers\CreditsCollection;
use Illuminate\Support\Facades\DB;

class CreditsService
{
    public function __construct()
    {

    }

    /**
     * Create new credit for user
     * @param CreditType $type
     * @param User $user
     * @param Carbon|null $expirationDate, null means "no expire"
     */
    public function createCredit(CreditType $type, User $user, $expirationDate, Payment $payment = null)
    {
        $credit = new Credit([
            'quantity' => $type->default_quantity,
            'expiration' => $expirationDate,
            'payment_id' => ($payment ? $payment->id : null),
            'is_paid' => ($payment != null)
        ]);

        $credit->type()->associate($type);
        $credit->user()->associate($user);
        $credit->save();
        return $credit;
    }

    /**
     * Get list of available credits
     * @param User $user
     * @return array|Collection
     */
    public function getAvailableCredits(User $user)
    {
        $available = new CreditsCollection();

        $allUserCredits = Credit::where('user_id', $user->id)
            ->with('type')->get()->keyBy('id');

        $spentCredits = PostCredit::whereHas(
                'credit',
                function($query) use ($user) {
                    $query->where('user_id', $user->id);
                }
            )
            ->select(DB::raw("SUM(quantity_consumed) as quantity_consumed"), 'credit_id as id')
            ->groupBy('credit_id')
            ->get();

        foreach ($allUserCredits as $creditId => $userCredit) {

            $spentCredit = $spentCredits->find($creditId);

            if ($spentCredit) {
                $quantityAvailable = $userCredit->quantity - $spentCredit->quantity_consumed;
            } else {
                $quantityAvailable = $userCredit->quantity;
            }

            if ($quantityAvailable > 0 || $userCredit->isMonthlyFreeCredit()) {
                $available[] = [
                    'id' => $userCredit->id,
                    'sku' => $userCredit->type->sku,
                    'name' => $userCredit->type->name,
                    'expirable' => (bool)$userCredit->expiration,
                    'is_paid' => $userCredit->is_paid,
                    'quantity_available' => (float)$quantityAvailable
                ];
            }

        }

        return $available;
    }

    public function getFreeMonthlyCredit(User $user)
    {
        $credits = $this->getAvailableCredits($user);
        $montlyCredit = $credits
            ->first(function($value, $key) {
                return $value['sku'] == CreditType::MONTHLY_FREE_SKU;
            });
        if (empty($montlyCredit)) {
            return null;
        }
        $montlyCredit = Credit::find($montlyCredit['id']);
        return $montlyCredit;
    }

    /**
     * Spend credits for post stuff
     *
     * @param Post $post
     * @param Credit $credit
     * @param float $consumedQuantity
     * @param array $meta
     * @return PostCredit
     */
    public function consumeCredits(Post $post, Credit $credit, float $consumedQuantity, $meta = [])
    {
        $postCredit = new PostCredit([
            'quantity_consumed' => $consumedQuantity,
            'meta' => 'some_meta'
        ]);

        $postCredit->post()->associate($post);
        $postCredit->credit()->associate($credit);
        $postCredit->save();

        return $postCredit;
    }
}
