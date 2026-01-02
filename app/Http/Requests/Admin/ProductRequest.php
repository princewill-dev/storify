<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $hasVariants = (bool) $this->input('has_variants', false);

        $base = [
            'store_id' => 'required|exists:stores,id',
            'category_id' => 'nullable|exists:categories,id',
            'name' => 'required|string|max:255',
            'brand' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'tags' => 'nullable|string|max:1000',
            'status' => 'required|in:active,inactive',
            'featured' => 'sometimes|boolean',
            'cod_available' => 'sometimes|boolean',
            'has_variants' => 'sometimes|boolean',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            // Media (images/videos) and update-only fields
            'images.*' => 'nullable|mimes:jpeg,jpg,png,gif,webp,mp4,mpeg,mov,avi,webm|max:307200',
            'primary_image' => 'sometimes|integer',
            'primary_image_id' => 'sometimes|integer',
            'delete_image_ids' => 'sometimes|array',
            'delete_image_ids.*' => 'integer',
            'bulk_quantity' => 'nullable|integer|min:1',
            'bulk_price' => 'nullable|numeric|min:0.01',
        ];

        if ($hasVariants) {
            // Base numeric/selection fields not required when using variants
            $variantRules = [
                'variants' => 'required|array|min:1',
                'variants.*.id' => 'sometimes|integer|exists:product_variants,id',
                'variants.*.size' => 'nullable|numeric|min:0',
                'variants.*.size_unit_id' => 'nullable|exists:size_units,id',
                'variants.*.weight' => 'nullable|numeric|min:0',
                'variants.*.weight_unit_id' => 'nullable|exists:weight_units,id',
                'variants.*.color' => 'nullable|string|max:100',
                'variants.*.quantity' => 'required|integer|gt:0',
                'variants.*.amount' => 'required|numeric|gt:0',
                'variants.*.currency_id' => 'nullable|integer|exists:currencies,id',
                'variants.*.sku' => 'nullable|string|max:100',
                'variants.*.status' => 'sometimes|in:active,inactive',
                'variants.*.featured' => 'sometimes|boolean',
            ];
            return array_merge($base, $variantRules);
        }

        // Single-SKU fields required when not using variants
        $singleSku = [
            'color' => 'nullable|string|max:100',
            'quantity' => 'bail|required|integer|gt:0',
            'size' => 'nullable|numeric|min:0',
            'size_unit_id' => 'nullable|exists:size_units,id',
            'weight' => 'nullable|numeric|min:0',
            'weight_unit_id' => 'nullable|exists:weight_units,id',
            'amount' => 'bail|required|numeric|gt:0',
            'currency_id' => 'nullable|integer|exists:currencies,id',
        ];

        return array_merge($base, $singleSku);
    }

    public function messages(): array
    {
        return [
            'quantity.required' => 'Quantity is required.',
            'quantity.integer' => 'Quantity must be an integer.',
            'quantity.gt' => 'Quantity must be greater than 0.',
            'amount.required' => 'Amount is required.',
            'amount.numeric' => 'Amount must be a number.',
            'amount.gt' => 'Amount must be greater than 0.',
        ];
    }

    protected function failedValidation(ValidatorContract $validator)
    {
        $errors = $validator->errors();

        foreach ($this->normalizeFiles($this->file('images', [])) as $index => $file) {
            if ($file instanceof UploadedFile && !$file->isValid()) {
                $message = $this->buildUploadErrorMessage($file, $index);
                $errors->forget("images.$index");
                $errors->add("images.$index", $message);
                $this->logUploadFailure($file, $index, $message);
            }
        }

        $errorArray = $errors->toArray();

        if ($this->payloadExceededServerLimit($errorArray)) {
            $message = $this->buildPayloadExceededMessage();
            $errors->add('images', $message);
            $errorArray = $errors->toArray();
        }

        Log::warning('product_request_validation_failed', [
            'path' => $this->path(),
            'errors' => $errorArray,
            'content_length' => $this->server('CONTENT_LENGTH'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
        ]);

        $first = collect($errorArray)->flatten()->first();
        session()->flash('error', $first ?: 'Please fix the highlighted errors.');
        session()->flash('_old_input', []);

        throw new ValidationException($validator);
    }

    /**
     * @param  mixed  $files
     * @return array<int, UploadedFile>
     */
    protected function normalizeFiles(mixed $files): array
    {
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        return is_array($files) ? array_values(array_filter($files)) : [];
    }

    protected function buildUploadErrorMessage(UploadedFile $file, int $index): string
    {
        $originalName = $file->getClientOriginalName() ?: 'uploaded file';
        $sizeString = $this->formatBytes($file->getSize());
        $errorCode = $file->getError();

        $reason = match ($errorCode) {
            UPLOAD_ERR_INI_SIZE => 'the file exceeds the maximum size allowed by the server',
            UPLOAD_ERR_FORM_SIZE => 'the file exceeds the maximum size allowed by the form',
            UPLOAD_ERR_PARTIAL => 'the file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'no file data was received',
            UPLOAD_ERR_NO_TMP_DIR => 'the temporary upload directory is missing on the server',
            UPLOAD_ERR_CANT_WRITE => 'the server failed to write the file to disk',
            UPLOAD_ERR_EXTENSION => 'a PHP extension stopped the upload',
            default => 'the upload failed due to an unknown server error',
        };

        $limits = sprintf(
            'upload_max_filesize=%s, post_max_size=%s',
            ini_get('upload_max_filesize'),
            ini_get('post_max_size')
        );

        return sprintf(
            'Image #%d ("%s", %s) could not be uploaded because %s. (%s)',
            $index + 1,
            $originalName,
            $sizeString,
            $reason,
            $limits
        );
    }

    protected function buildPayloadExceededMessage(): string
    {
        return sprintf(
            'The uploaded images were rejected because the total payload exceeded the server limits (upload_max_filesize=%s, post_max_size=%s). Reduce the file size or contact support to increase the limits.',
            ini_get('upload_max_filesize'),
            ini_get('post_max_size')
        );
    }

    protected function payloadExceededServerLimit(array $errors): bool
    {
        $flat = collect($errors)->flatten();

        return $flat->contains(fn ($message) => is_string($message) && str_contains(strtolower($message), 'failed to upload'))
            && empty($this->file('images'));
    }

    protected function formatBytes(?int $bytes): string
    {
        if (empty($bytes) || $bytes <= 0) {
            return 'unknown size';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return number_format($bytes / pow(1024, $power), $power >= 2 ? 2 : 0) . ' ' . $units[$power];
    }

    protected function logUploadFailure(UploadedFile $file, int $index, string $message): void
    {
        Log::error('product_image_upload_failed_detail', [
            'field' => "images.$index",
            'index' => $index,
            'original_name' => $file->getClientOriginalName(),
            'client_mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'size_human' => $this->formatBytes($file->getSize()),
            'error_code' => $file->getError(),
            'error_message' => $message,
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'content_length' => $this->server('CONTENT_LENGTH'),
            'user_id' => optional($this->user())->id,
            'ip' => $this->ip(),
        ]);
    }
}
