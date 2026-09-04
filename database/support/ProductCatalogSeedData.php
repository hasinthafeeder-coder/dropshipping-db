<?php

namespace Database\Support;

class ProductCatalogSeedData
{
    public const SEED_VERSION = 'v1';

    public const SEED_SLUG_PREFIX = 'seed-catalog-v1';

    /**
     * @return list<array{
     *     name: string,
     *     tier: string,
     *     category_slug: string,
     *     multi_variant: bool,
     *     variant_template: string
     * }>
     */
    public static function productDefinitions(): array
    {
        return [
            // Electronics — accessories / audio / smart / computer / power
            ['name' => 'Wireless Bluetooth Earbuds', 'tier' => 'medium', 'category_slug' => 'earphones', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => '20W Fast Charging Adapter', 'tier' => 'low', 'category_slug' => 'computer-accessories', 'multi_variant' => false, 'variant_template' => 'standard'],
            ['name' => 'Magnetic Phone Holder', 'tier' => 'low', 'category_slug' => 'computer-accessories', 'multi_variant' => true, 'variant_template' => 'phone_model'],
            ['name' => 'USB-C Multiport Hub', 'tier' => 'medium', 'category_slug' => 'computer-accessories', 'multi_variant' => true, 'variant_template' => 'port_count'],
            ['name' => 'Foldable Laptop Stand', 'tier' => 'medium', 'category_slug' => 'computer-accessories', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Smart Fitness Watch', 'tier' => 'high', 'category_slug' => 'mobile-phones', 'multi_variant' => true, 'variant_template' => 'strap_color'],
            ['name' => 'Portable Neck Fan', 'tier' => 'low', 'category_slug' => 'computer-accessories', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Noise Cancelling Headphones', 'tier' => 'high', 'category_slug' => 'headphones', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Bluetooth Portable Speaker', 'tier' => 'medium', 'category_slug' => 'speakers', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Wireless Charging Pad', 'tier' => 'medium', 'category_slug' => 'computer-accessories', 'multi_variant' => false, 'variant_template' => 'standard'],
            ['name' => 'Mini LED Ring Light', 'tier' => 'low', 'category_slug' => 'computer-accessories', 'multi_variant' => true, 'variant_template' => 'size'],
            ['name' => 'USB-C Fast Charging Cable 2m', 'tier' => 'low', 'category_slug' => 'computer-accessories', 'multi_variant' => true, 'variant_template' => 'length'],
            ['name' => 'Mechanical Gaming Keyboard', 'tier' => 'high', 'category_slug' => 'keyboards', 'multi_variant' => true, 'variant_template' => 'switch_type'],
            ['name' => 'Ergonomic Wireless Mouse', 'tier' => 'medium', 'category_slug' => 'mice', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Smart WiFi Plug Socket', 'tier' => 'medium', 'category_slug' => 'computer-accessories', 'multi_variant' => false, 'variant_template' => 'standard'],
            ['name' => 'Power Bank 20000mAh', 'tier' => 'medium', 'category_slug' => 'computer-accessories', 'multi_variant' => true, 'variant_template' => 'capacity'],
            ['name' => 'Tempered Glass Screen Protector', 'tier' => 'low', 'category_slug' => 'mobile-phones', 'multi_variant' => true, 'variant_template' => 'phone_model'],
            ['name' => 'Silicone Phone Case', 'tier' => 'low', 'category_slug' => 'mobile-phones', 'multi_variant' => true, 'variant_template' => 'phone_model'],
            ['name' => 'Car Phone Mount', 'tier' => 'low', 'category_slug' => 'computer-accessories', 'multi_variant' => false, 'variant_template' => 'standard'],
            ['name' => 'HD Webcam with Microphone', 'tier' => 'medium', 'category_slug' => 'computer-accessories', 'multi_variant' => false, 'variant_template' => 'standard'],

            // Home & Kitchen
            ['name' => 'Stainless Steel Water Bottle', 'tier' => 'low', 'category_slug' => 'kitchen-tools', 'multi_variant' => true, 'variant_template' => 'volume'],
            ['name' => 'Non-Stick Frying Pan 28cm', 'tier' => 'medium', 'category_slug' => 'cookware', 'multi_variant' => false, 'variant_template' => 'standard'],
            ['name' => 'Portable Mini Blender', 'tier' => 'medium', 'category_slug' => 'kitchen-tools', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Rechargeable LED Desk Lamp', 'tier' => 'medium', 'category_slug' => 'home-accessories', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Airtight Food Storage Container Set', 'tier' => 'low', 'category_slug' => 'kitchen-tools', 'multi_variant' => true, 'variant_template' => 'piece_count'],
            ['name' => 'Microfiber Cleaning Cloth Pack', 'tier' => 'low', 'category_slug' => 'home-accessories', 'multi_variant' => true, 'variant_template' => 'piece_count'],
            ['name' => 'Stainless Steel Knife Set', 'tier' => 'medium', 'category_slug' => 'kitchen-tools', 'multi_variant' => false, 'variant_template' => 'standard'],
            ['name' => 'Electric Kettle 1.8L', 'tier' => 'medium', 'category_slug' => 'kitchen-tools', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Collapsible Laundry Basket', 'tier' => 'low', 'category_slug' => 'home-accessories', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Vacuum Storage Bags Set', 'tier' => 'low', 'category_slug' => 'home-accessories', 'multi_variant' => true, 'variant_template' => 'piece_count'],
            ['name' => 'Silicone Kitchen Utensil Set', 'tier' => 'low', 'category_slug' => 'kitchen-tools', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Glass Food Prep Containers', 'tier' => 'medium', 'category_slug' => 'kitchen-tools', 'multi_variant' => true, 'variant_template' => 'piece_count'],
            ['name' => 'Bamboo Cutting Board', 'tier' => 'low', 'category_slug' => 'kitchen-tools', 'multi_variant' => true, 'variant_template' => 'size'],
            ['name' => 'Handheld Garment Steamer', 'tier' => 'medium', 'category_slug' => 'home-accessories', 'multi_variant' => false, 'variant_template' => 'standard'],
            ['name' => 'Digital Kitchen Scale', 'tier' => 'low', 'category_slug' => 'kitchen-tools', 'multi_variant' => false, 'variant_template' => 'standard'],

            // Beauty & Personal Care
            ['name' => 'Vitamin C Face Serum 30ml', 'tier' => 'medium', 'category_slug' => 'skin-care', 'multi_variant' => false, 'variant_template' => 'standard'],
            ['name' => 'Hydrating Facial Cleanser', 'tier' => 'medium', 'category_slug' => 'skin-care', 'multi_variant' => true, 'variant_template' => 'volume'],
            ['name' => 'Argan Oil Hair Serum', 'tier' => 'medium', 'category_slug' => 'hair-care', 'multi_variant' => true, 'variant_template' => 'volume'],
            ['name' => 'Electric Beard Trimmer', 'tier' => 'medium', 'category_slug' => 'hair-care', 'multi_variant' => false, 'variant_template' => 'standard'],
            ['name' => 'Makeup Brush Set', 'tier' => 'low', 'category_slug' => 'skin-care', 'multi_variant' => true, 'variant_template' => 'piece_count'],
            ['name' => 'SPF 50 Sunscreen Lotion', 'tier' => 'medium', 'category_slug' => 'skin-care', 'multi_variant' => true, 'variant_template' => 'volume'],
            ['name' => 'Charcoal Face Mask Pack', 'tier' => 'low', 'category_slug' => 'skin-care', 'multi_variant' => true, 'variant_template' => 'piece_count'],
            ['name' => 'Ceramic Hair Straightener', 'tier' => 'high', 'category_slug' => 'hair-care', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Nail Care Manicure Kit', 'tier' => 'low', 'category_slug' => 'skin-care', 'multi_variant' => false, 'variant_template' => 'standard'],
            ['name' => 'Aloe Vera Gel 200ml', 'tier' => 'low', 'category_slug' => 'skin-care', 'multi_variant' => false, 'variant_template' => 'standard'],

            // Fashion
            ['name' => 'Cotton Crew Neck T-Shirt', 'tier' => 'low', 'category_slug' => 'mens-t-shirts', 'multi_variant' => true, 'variant_template' => 'apparel'],
            ['name' => 'Classic Oxford Shirt', 'tier' => 'medium', 'category_slug' => 'mens-shirts', 'multi_variant' => true, 'variant_template' => 'apparel'],
            ['name' => 'Floral Summer Dress', 'tier' => 'medium', 'category_slug' => 'dresses', 'multi_variant' => true, 'variant_template' => 'apparel'],
            ['name' => 'Casual Crop Top', 'tier' => 'low', 'category_slug' => 'womens-tops', 'multi_variant' => true, 'variant_template' => 'apparel'],
            ['name' => 'Canvas Crossbody Bag', 'tier' => 'medium', 'category_slug' => 'fashion', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Leather Belt', 'tier' => 'low', 'category_slug' => 'fashion', 'multi_variant' => true, 'variant_template' => 'belt_size'],
            ['name' => 'Analog Wrist Watch', 'tier' => 'high', 'category_slug' => 'fashion', 'multi_variant' => true, 'variant_template' => 'strap_color'],
            ['name' => 'Polarized Sunglasses', 'tier' => 'medium', 'category_slug' => 'fashion', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Sports Cap', 'tier' => 'low', 'category_slug' => 'fashion', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Travel Backpack 30L', 'tier' => 'medium', 'category_slug' => 'fashion', 'multi_variant' => true, 'variant_template' => 'color'],

            // Sports & Fitness
            ['name' => 'Yoga Mat with Carry Strap', 'tier' => 'medium', 'category_slug' => 'fitness-equipment', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Resistance Bands Set', 'tier' => 'low', 'category_slug' => 'fitness-equipment', 'multi_variant' => true, 'variant_template' => 'resistance'],
            ['name' => 'Adjustable Dumbbell Pair', 'tier' => 'high', 'category_slug' => 'fitness-equipment', 'multi_variant' => true, 'variant_template' => 'weight'],
            ['name' => 'Foldable Camping Chair', 'tier' => 'medium', 'category_slug' => 'sports-accessories', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Stainless Steel Insulated Flask', 'tier' => 'medium', 'category_slug' => 'sports-accessories', 'multi_variant' => true, 'variant_template' => 'volume'],
            ['name' => 'Running Waist Pouch', 'tier' => 'low', 'category_slug' => 'sports-accessories', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Jump Rope with Counter', 'tier' => 'low', 'category_slug' => 'fitness-equipment', 'multi_variant' => false, 'variant_template' => 'standard'],
            ['name' => 'Cycling Water Bottle Holder', 'tier' => 'low', 'category_slug' => 'sports-accessories', 'multi_variant' => false, 'variant_template' => 'standard'],
            ['name' => 'Foam Roller for Recovery', 'tier' => 'medium', 'category_slug' => 'fitness-equipment', 'multi_variant' => true, 'variant_template' => 'size'],
            ['name' => 'Outdoor Hiking Cap', 'tier' => 'low', 'category_slug' => 'sports-accessories', 'multi_variant' => true, 'variant_template' => 'color'],

            // Baby & Kids (extra categories may be created by seeder)
            ['name' => 'Baby Silicone Feeding Set', 'tier' => 'medium', 'category_slug' => 'baby-accessories', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Soft Plush Teddy Bear', 'tier' => 'low', 'category_slug' => 'toys', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Educational Building Blocks', 'tier' => 'medium', 'category_slug' => 'toys', 'multi_variant' => true, 'variant_template' => 'piece_count'],
            ['name' => 'Kids School Backpack', 'tier' => 'medium', 'category_slug' => 'kids-products', 'multi_variant' => true, 'variant_template' => 'color'],
            ['name' => 'Baby Cotton Onesie Pack', 'tier' => 'low', 'category_slug' => 'baby-accessories', 'multi_variant' => true, 'variant_template' => 'apparel_baby'],
        ];
    }

    /**
     * Additional categories to ensure exist (slug => definition).
     *
     * @return array<string, array{name: string, parent_slug: string, description: string}>
     */
    public static function extraCategories(): array
    {
        return [
            'baby-kids' => [
                'name' => 'Baby & Kids',
                'parent_slug' => '',
                'description' => 'Baby and kids products.',
            ],
            'baby-accessories' => [
                'name' => 'Baby Accessories',
                'parent_slug' => 'baby-kids',
                'description' => 'Baby care and feeding accessories.',
            ],
            'toys' => [
                'name' => 'Toys',
                'parent_slug' => 'baby-kids',
                'description' => 'Toys and play products for children.',
            ],
            'kids-products' => [
                'name' => 'Kids Products',
                'parent_slug' => 'baby-kids',
                'description' => 'General kids products.',
            ],
            'bags' => [
                'name' => 'Bags',
                'parent_slug' => 'fashion',
                'description' => 'Bags and carry accessories.',
            ],
            'watches' => [
                'name' => 'Watches',
                'parent_slug' => 'fashion',
                'description' => 'Wrist watches and timepieces.',
            ],
            'fashion-accessories' => [
                'name' => 'Accessories',
                'parent_slug' => 'fashion',
                'description' => 'Fashion accessories.',
            ],
            'outdoor' => [
                'name' => 'Outdoor',
                'parent_slug' => 'sports-fitness',
                'description' => 'Outdoor sports and recreation.',
            ],
            'grooming' => [
                'name' => 'Grooming',
                'parent_slug' => 'beauty-personal-care',
                'description' => 'Personal grooming products.',
            ],
            'beauty-accessories' => [
                'name' => 'Beauty Accessories',
                'parent_slug' => 'beauty-personal-care',
                'description' => 'Beauty tools and accessories.',
            ],
            'storage' => [
                'name' => 'Storage',
                'parent_slug' => 'home-kitchen',
                'description' => 'Home storage solutions.',
            ],
            'cleaning' => [
                'name' => 'Cleaning',
                'parent_slug' => 'home-kitchen',
                'description' => 'Cleaning supplies and tools.',
            ],
            'home-gadgets' => [
                'name' => 'Home Gadgets',
                'parent_slug' => 'home-kitchen',
                'description' => 'Smart and practical home gadgets.',
            ],
            'smart-devices' => [
                'name' => 'Smart Devices',
                'parent_slug' => 'electronics',
                'description' => 'Smart home and wearable devices.',
            ],
            'power-charging' => [
                'name' => 'Power & Charging',
                'parent_slug' => 'electronics',
                'description' => 'Chargers, cables and power accessories.',
            ],
            'mobile-accessories' => [
                'name' => 'Mobile Accessories',
                'parent_slug' => 'electronics',
                'description' => 'Mobile phone accessories.',
            ],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function variantTemplates(): array
    {
        return [
            'standard' => ['Standard'],
            'color' => ['Black', 'White', 'Blue', 'Red'],
            'phone_model' => ['iPhone 15', 'iPhone 15 Pro', 'iPhone 15 Pro Max', 'Samsung S24'],
            'port_count' => ['4-Port', '6-Port', '8-Port'],
            'strap_color' => ['Black Strap', 'Silver Mesh', 'Rose Gold'],
            'size' => ['Small', 'Medium', 'Large'],
            'length' => ['1m', '1.5m', '2m'],
            'switch_type' => ['Red Switch', 'Blue Switch', 'Brown Switch'],
            'capacity' => ['10000mAh', '20000mAh', '30000mAh'],
            'volume' => ['500ml', '750ml', '1000ml'],
            'piece_count' => ['3-Piece', '5-Piece', '7-Piece'],
            'apparel' => ['Black / S', 'Black / M', 'Black / L', 'White / S', 'White / M', 'White / L'],
            'apparel_baby' => ['0-3M', '3-6M', '6-12M', '12-18M'],
            'belt_size' => ['32 inch', '34 inch', '36 inch', '38 inch'],
            'resistance' => ['Light', 'Medium', 'Heavy'],
            'weight' => ['5kg Pair', '10kg Pair', '15kg Pair'],
        ];
    }

    /**
     * Market-native realistic price bands by tier.
     *
     * @return array<string, array<string, array{cost: array{0: float, 1: float}, sell: array{0: float, 1: float}}>>
     */
    public static function priceBands(): array
    {
        return [
            'lk' => [
                'low' => ['cost' => [350, 2500], 'sell' => [890, 4500]],
                'medium' => ['cost' => [1200, 6500], 'sell' => [2990, 12500]],
                'high' => ['cost' => [4500, 18000], 'sell' => [9990, 35000]],
            ],
            'my' => [
                'low' => ['cost' => [8, 45], 'sell' => [19.90, 79.90]],
                'medium' => ['cost' => [25, 120], 'sell' => [49.90, 199.90]],
                'high' => ['cost' => [80, 350], 'sell' => [149.90, 599.90]],
            ],
        ];
    }

    public static function englishDescription(string $productName, string $marketName): string
    {
        return "{$productName} — premium quality listing for the {$marketName} market. "
            .'Suitable for everyday use with reliable build quality and reseller-friendly packaging.';
    }

    public static function sinhalaDescription(string $productName): string
    {
        return "{$productName} — ශ්‍රී ලංකා වෙළඳපොළ සඳහා උසස් තත්ත්වයේ නිෂ්පාදනයකි.";
    }

    public static function tamilDescription(string $productName): string
    {
        return "{$productName} — தரமான தயாரிப்பு, மறுவிற்பனையாளர்களுக்கு ஏற்றது.";
    }

    public static function malayDescription(string $productName): string
    {
        return "{$productName} — produk berkualiti untuk pasaran Malaysia.";
    }
}
