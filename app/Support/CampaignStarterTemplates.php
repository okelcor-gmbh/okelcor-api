<?php

namespace App\Support;

/**
 * Built-in starting points for a campaign.
 *
 * Deliberately CODE, not database rows: they're always present, can't be
 * deleted by accident, and can be improved in a deploy without a migration or a
 * re-run of a seeder. The marketer's own saved designs live in
 * `campaign_templates` (see App\Models\CampaignTemplate) — these are what they
 * start from before they have any.
 *
 * `okelcor_classic` is a direct reconstruction of the campaigns Okelcor was
 * sending from Wix — teal page, dark card, centred title, hero photo, three
 * benefit sections, a teal call-to-action, a closing section and the
 * address/social/website footer. Starting from it means the team gets the layout
 * they already know without anybody writing HTML.
 */
class CampaignStarterTemplates
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            self::okelcorClassic(),
            self::simpleAnnouncement(),
            self::productOffer(),
        ];
    }

    public static function find(string $key): ?array
    {
        foreach (self::all() as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    /**
     * The full house-style newsletter, matching the Wix campaigns the team used
     * to send. Placeholder copy is real, usable copy — not lorem ipsum — so it
     * can be edited down rather than written from scratch.
     */
    private static function okelcorClassic(): array
    {
        return [
            'key'         => 'okelcor_classic',
            'name'        => 'Okelcor classic newsletter',
            'description' => 'The full house style: title, hero photo, benefit sections, call-to-action and footer. Closest to the campaigns sent from Wix.',
            'theme'       => ['preset' => 'okelcor_dark'],
            'blocks'      => [
                ['type' => 'heading', 'text' => 'Accelerate Your Growth with OKELCOR TIRES', 'level' => 'large', 'align' => 'center'],
                ['type' => 'image', 'url' => 'https://api.okelcor.com/storage/campaign/warehouse.jpg', 'alt' => 'Okelcor tyre warehouse'],
                [
                    'type'  => 'text',
                    'align' => 'center',
                    'size'  => 'large',
                    'text'  => "Are you looking to expand your business without breaking the bank? At OKELCOR TIRES, we understand the challenges of growth. That's why we offer affordable tire solutions tailored for businesses just like yours.",
                ],
                ['type' => 'heading', 'text' => 'Why Choose OKELCOR TIRES?', 'level' => 'medium', 'align' => 'center'],
                [
                    'type' => 'text',
                    'text' => 'We provide more than just tires; we offer a partnership that fuels your business expansion. Consider these benefits:',
                ],
                ['type' => 'heading', 'text' => 'Affordable Pricing', 'level' => 'small', 'align' => 'left'],
                [
                    'type' => 'text',
                    'text' => "Our competitive pricing ensures you get the best value for your investment. We believe growing businesses shouldn't have to compromise on quality. By choosing OKELCOR TIRES, you can equip your fleet or stock your shelves without depleting your budget.",
                ],
                ['type' => 'heading', 'text' => 'Flexible Shipping Options', 'level' => 'small', 'align' => 'left'],
                [
                    'type' => 'text',
                    'text' => 'No matter where your business is located, our flexible shipping options ensure your tires arrive on time and in perfect condition. We handle the logistics, allowing you to focus on what you do best—growing your business.',
                ],
                ['type' => 'heading', 'text' => 'Uncompromising Quality', 'level' => 'small', 'align' => 'left'],
                [
                    'type' => 'text',
                    'text' => 'We source our tires from reputable manufacturers, ensuring they meet the highest standards of performance and durability. Your peace of mind is our priority.',
                ],
                ['type' => 'button', 'label' => 'Explore Our Tire Solutions', 'url' => 'https://okelcor.com/products', 'align' => 'center'],
                [
                    'type'  => 'text',
                    'align' => 'center',
                    'size'  => 'large',
                    'text'  => 'Ready to take your business to the next level? Discover how OKELCOR TIRES can support your growth with affordable, high-quality tire solutions. Click below to learn more!',
                ],
                ['type' => 'image', 'url' => 'https://api.okelcor.com/storage/campaign/tyres.jpg', 'alt' => 'Okelcor tyres close up'],
                ['type' => 'heading', 'text' => 'Contact Us Today', 'level' => 'small', 'align' => 'center'],
                [
                    'type'  => 'text',
                    'align' => 'center',
                    'size'  => 'large',
                    'text'  => "Have questions or need a custom quote? Our expert team is here to assist you. Reach out today, and let's discuss how OKELCOR TIRES can help your business thrive.",
                ],
                ['type' => 'button', 'label' => 'Get a Custom Quote', 'url' => 'https://okelcor.com/contact', 'align' => 'center'],
                ['type' => 'spacer', 'height' => 24],
                [
                    'type'          => 'footer',
                    'address_lines' => ['Landsbergerstr. 155 80687', 'München Deutschland', '+49 (0) 89 / 545 583 60'],
                    'social'        => [
                        ['label' => 'Facebook', 'url' => 'https://www.facebook.com/'],
                        ['label' => 'X', 'url' => 'https://x.com/'],
                        ['label' => 'Pinterest', 'url' => 'https://www.pinterest.com/'],
                    ],
                    'site_label'    => 'Check out our site',
                    'site_url'      => 'https://okelcor.com',
                ],
            ],
        ];
    }

    /**
     * The short one. Most sends don't need the full newsletter, and giving the
     * team a small template stops them deleting most of the big one every time.
     */
    private static function simpleAnnouncement(): array
    {
        return [
            'key'         => 'simple_announcement',
            'name'        => 'Simple announcement',
            'description' => 'One message, one button. Good for news, price updates and short notices.',
            'theme'       => ['preset' => 'okelcor_dark'],
            'blocks'      => [
                ['type' => 'heading', 'text' => 'Your headline here', 'level' => 'large', 'align' => 'center'],
                [
                    'type'  => 'text',
                    'align' => 'center',
                    'text'  => "Hi [[FIRST_NAME|there]],\n\nWrite your message here. Keep it to a few sentences — say what has changed and what you'd like the reader to do next.",
                ],
                ['type' => 'button', 'label' => 'Learn more', 'url' => 'https://okelcor.com', 'align' => 'center'],
                ['type' => 'spacer', 'height' => 16],
                [
                    'type'          => 'footer',
                    'address_lines' => ['Landsbergerstr. 155 80687', 'München Deutschland', '+49 (0) 89 / 545 583 60'],
                    'site_label'    => 'Check out our site',
                    'site_url'      => 'https://okelcor.com',
                ],
            ],
        ];
    }

    private static function productOffer(): array
    {
        return [
            'key'         => 'product_offer',
            'name'        => 'Product offer',
            'description' => 'A photo, a list of what is on offer, and a quote button.',
            'theme'       => ['preset' => 'okelcor_dark'],
            'blocks'      => [
                ['type' => 'heading', 'text' => 'This month from Okelcor', 'level' => 'large', 'align' => 'center'],
                ['type' => 'image', 'url' => 'https://api.okelcor.com/storage/campaign/tyres.jpg', 'alt' => 'Tyres in stock'],
                [
                    'type' => 'text',
                    'text' => "Hi [[FIRST_NAME|there]],\n\nHere is what we currently have available for **[[COMPANY|your business]]**:",
                ],
                ['type' => 'list', 'items' => ['Passenger car tyres (PCR) — all common sizes', 'Truck & bus tyres (TBR)', 'Quality-checked used tyres', 'Off-the-road (OTR) on request']],
                ['type' => 'text', 'text' => 'Tell us the sizes and quantities you need and we will send you a price the same working day.'],
                ['type' => 'button', 'label' => 'Request a quote', 'url' => 'https://okelcor.com/contact', 'align' => 'center'],
                ['type' => 'divider'],
                [
                    'type'          => 'footer',
                    'address_lines' => ['Landsbergerstr. 155 80687', 'München Deutschland', '+49 (0) 89 / 545 583 60'],
                    'site_label'    => 'Check out our site',
                    'site_url'      => 'https://okelcor.com',
                ],
            ],
        ];
    }
}
