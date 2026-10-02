<?php

namespace Webkul\Marketplace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Vendor profile fields only — lifecycle `status` is never writable here
 * (see VendorService, the only place status may change).
 */
class VendorProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $vendor = $this->route('vendor');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('marketplace_vendors', 'email')->ignore($vendor?->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
