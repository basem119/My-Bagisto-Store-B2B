<?php

namespace Webkul\Marketplace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddVendorMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'exists:customers,email'],
            // `owner` deliberately excluded — VendorMembershipService rejects it too (defense in depth).
            'role' => ['required', Rule::in(['admin', 'manager', 'staff'])],
        ];
    }
}
