<?php

namespace App\Http\Requests\Management\Plugins;

use App\Support\Plugins\PluginRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The connect/edit payload for one plugin.
 *
 * The field rules are not written here — they are read out of
 * {@see PluginRegistry}, which is the same definition the management UI renders
 * its form from and the same one the storefront renderer validates against. A
 * rule can therefore only exist in one place; tightening a pattern tightens the
 * write path and the read path together.
 *
 * `store_id` is absent for a business-wide default and present for a store
 * override. It is not authorised here — the controller runs it through
 * TenantGuard, where the store-access rule already lives.
 */
final class UpdatePluginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $pluginKey = (string) $this->route('pluginKey');

        if (! PluginRegistry::has($pluginKey)) {
            return [];
        }

        $rules = [
            'is_enabled' => ['required', 'boolean'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'config' => ['required', 'array'],
        ];

        foreach (PluginRegistry::rulesFor($pluginKey) as $field => $fieldRules) {
            $rules["config.{$field}"] = $fieldRules;
        }

        return $rules;
    }

    /**
     * Nest the registry's field messages under `config.`.
     *
     * The wording lives with the field definitions, so the form, this request
     * and the storefront renderer all report a bad value the same way.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $pluginKey = (string) $this->route('pluginKey');

        $messages = ['config.required' => 'Fill in the fields this plugin needs.'];

        foreach (PluginRegistry::messagesFor($pluginKey) as $rule => $message) {
            $messages["config.{$rule}"] = $message;
        }

        return $messages;
    }
}
