<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductCategorySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $categories = [
            [
                'name' => 'Electronics',
                'slug' => 'electronics',
                'description' => 'Electronic devices and accessories.',
                'children' => [
                    [
                        'name' => 'Mobile Phones',
                        'slug' => 'mobile-phones',
                        'description' => 'Smartphones and mobile phones.',
                        'children' => [],
                    ],
                    [
                        'name' => 'Computer Accessories',
                        'slug' => 'computer-accessories',
                        'description' => 'Accessories and peripherals for computers.',
                        'children' => [
                            [
                                'name' => 'Keyboards',
                                'slug' => 'keyboards',
                                'description' => 'Computer keyboards.',
                                'children' => [],
                            ],
                            [
                                'name' => 'Mice',
                                'slug' => 'mice',
                                'description' => 'Computer mice and pointing devices.',
                                'children' => [],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Audio',
                        'slug' => 'audio',
                        'description' => 'Audio devices and accessories.',
                        'children' => [
                            [
                                'name' => 'Earphones',
                                'slug' => 'earphones',
                                'description' => 'Wired and wireless earphones.',
                                'children' => [],
                            ],
                            [
                                'name' => 'Headphones',
                                'slug' => 'headphones',
                                'description' => 'Wired and wireless headphones.',
                                'children' => [],
                            ],
                            [
                                'name' => 'Speakers',
                                'slug' => 'speakers',
                                'description' => 'Portable and home audio speakers.',
                                'children' => [],
                            ],
                        ],
                    ],
                ],
            ],

            [
                'name' => 'Fashion',
                'slug' => 'fashion',
                'description' => 'Clothing, footwear and fashion accessories.',
                'children' => [
                    [
                        'name' => "Men's Clothing",
                        'slug' => 'mens-clothing',
                        'description' => "Men's clothing and apparel.",
                        'children' => [
                            [
                                'name' => 'T-Shirts',
                                'slug' => 'mens-t-shirts',
                                'description' => "Men's T-shirts.",
                                'children' => [],
                            ],
                            [
                                'name' => 'Shirts',
                                'slug' => 'mens-shirts',
                                'description' => "Men's shirts.",
                                'children' => [],
                            ],
                        ],
                    ],
                    [
                        'name' => "Women's Clothing",
                        'slug' => 'womens-clothing',
                        'description' => "Women's clothing and apparel.",
                        'children' => [
                            [
                                'name' => 'Dresses',
                                'slug' => 'dresses',
                                'description' => "Women's dresses.",
                                'children' => [],
                            ],
                            [
                                'name' => 'Tops',
                                'slug' => 'womens-tops',
                                'description' => "Women's tops.",
                                'children' => [],
                            ],
                        ],
                    ],
                ],
            ],

            [
                'name' => 'Home & Kitchen',
                'slug' => 'home-kitchen',
                'description' => 'Home and kitchen products.',
                'children' => [
                    [
                        'name' => 'Kitchen',
                        'slug' => 'kitchen',
                        'description' => 'Kitchen products and accessories.',
                        'children' => [
                            [
                                'name' => 'Cookware',
                                'slug' => 'cookware',
                                'description' => 'Pots, pans and cookware.',
                                'children' => [],
                            ],
                            [
                                'name' => 'Kitchen Tools',
                                'slug' => 'kitchen-tools',
                                'description' => 'Tools and utensils for the kitchen.',
                                'children' => [],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Home Accessories',
                        'slug' => 'home-accessories',
                        'description' => 'Decorative and practical home accessories.',
                        'children' => [],
                    ],
                ],
            ],

            [
                'name' => 'Beauty & Personal Care',
                'slug' => 'beauty-personal-care',
                'description' => 'Beauty and personal care products.',
                'children' => [
                    [
                        'name' => 'Skin Care',
                        'slug' => 'skin-care',
                        'description' => 'Skin care products.',
                        'children' => [],
                    ],
                    [
                        'name' => 'Hair Care',
                        'slug' => 'hair-care',
                        'description' => 'Hair care products.',
                        'children' => [],
                    ],
                ],
            ],

            [
                'name' => 'Sports & Fitness',
                'slug' => 'sports-fitness',
                'description' => 'Sports equipment and fitness products.',
                'children' => [
                    [
                        'name' => 'Fitness Equipment',
                        'slug' => 'fitness-equipment',
                        'description' => 'Equipment for exercise and fitness.',
                        'children' => [],
                    ],
                    [
                        'name' => 'Sports Accessories',
                        'slug' => 'sports-accessories',
                        'description' => 'Accessories for sports and outdoor activities.',
                        'children' => [],
                    ],
                ],
            ],
        ];

        foreach ($categories as $sortOrder => $category) {
            $this->createCategory(
                $category,
                null,
                $sortOrder,
                $now
            );
        }
    }

    private function createCategory(
        array $category,
        ?string $parentId,
        int $sortOrder,
        $now
    ): void {
        $id = (string) Str::uuid();

        DB::table('product_categories')->insert([
            'id' => $id,
            'parent_id' => $parentId,
            'name' => $category['name'],
            'slug' => $category['slug'],
            'description' => $category['description'],
            'sort_order' => $sortOrder,
            'is_active' => true,
            'created_by' => null,
            'updated_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($category['children'] as $childSortOrder => $child) {
            $this->createCategory(
                $child,
                $id,
                $childSortOrder,
                $now
            );
        }
    }
}
