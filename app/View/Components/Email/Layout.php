<?php

namespace App\View\Components\Email;

use App\Support\EmailBrand;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The one shell every transactional email is rendered through.
 *
 * `brand` is an explicit array so a storefront receipt can carry the store's
 * name, logo and support address while the platform keeps its own. Anything the
 * caller omits falls back to the platform letterhead rather than rendering an
 * empty footer field.
 */
class Layout extends Component
{
    public string $brandName;

    public string $brandLogo;

    public ?string $brandEmail;

    public ?string $brandPhone;

    public ?string $brandAddress;

    public ?string $brandBranchAddress;

    public string $platformName;

    public string $platformUrl;

    public string $footerCopy;

    /**
     * @param  array<string, mixed>  $brand
     */
    public function __construct(
        public ?string $title = null,
        public ?string $preheader = null,
        public ?string $eyebrow = null,
        public ?string $heading = null,
        public ?string $subtitle = null,
        public ?string $badge = null,
        public string $tone = 'neutral',
        public array $brand = [],
        public bool $poweredBy = false,
        public ?string $footerNote = null,
    ) {
        $platform = EmailBrand::resolve();

        $chosen = array_merge($platform, array_filter(
            $brand,
            static fn ($value) => $value !== null && $value !== '',
        ));

        $this->brandName = (string) ($chosen['name'] ?? $platform['name']);
        $this->brandLogo = (string) ($chosen['logo'] ?? $platform['logo']);
        $this->brandEmail = $chosen['email'] ?? $platform['email'] ?? null;
        $this->brandPhone = $chosen['phone'] ?? null;
        $this->brandAddress = $chosen['address'] ?? null;
        $this->brandBranchAddress = $chosen['branch_address'] ?? null;
        $this->platformName = (string) $platform['name'];
        $this->platformUrl = (string) $platform['url'];

        $this->footerCopy = $footerNote
            ?? ($poweredBy
                ? 'This message was sent by '.$this->brandName.'.'
                : 'This is an automated message. Please do not reply.');
    }

    public function render(): View
    {
        return view('components.email.layout');
    }
}
