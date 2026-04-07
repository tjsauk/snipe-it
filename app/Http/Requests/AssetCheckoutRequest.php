<?php

namespace App\Http\Requests;

class AssetCheckoutRequest extends Request
{
    /**
     * Default assigned_user to the current user when checking out/reserving to
     * a user but the form didn't submit an explicit value (e.g. the JS cleared
     * the Select2 field on page-load or the user relied on the self-checkout path).
     * This runs before validation so the required_without_all rule passes.
     */
    protected function prepareForValidation(): void
    {
        if (
            $this->input('checkout_to_type', 'user') === 'user'
            && !$this->filled('assigned_user')
            && !$this->filled('assigned_asset')
            && !$this->filled('assigned_location')
        ) {
            $this->merge(['assigned_user' => auth()->id()]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $settings = \App\Models\Setting::getSettings();

        $rules = [
            'assigned_user' => 'numeric|nullable|required_without_all:assigned_asset,assigned_location',
            'assigned_asset' => 'numeric|nullable|required_without_all:assigned_user,assigned_location',
            'assigned_location' => 'numeric|nullable|required_without_all:assigned_user,assigned_asset',
            'status_id'             => 'exists:status_labels,id,deployable,1',
            'checkout_to_type'      => 'required|in:asset,location,user',
            'checkout_at' => [
                'nullable',
                'date',
            ],
            'expected_checkin' => [
                'nullable',
                'date'
            ],
            ];

            if($settings->require_checkinout_notes) {
                $rules['note'] = 'required|string';
            }

        return $rules;
    }
}
