<?php

namespace Database\Support;

use Feeder\Core\Enums\ApplicationType;
use Feeder\Core\Enums\FileCategory;
use Feeder\Core\Models\File;
use Feeder\Core\Services\UuidService;
use Illuminate\Support\Facades\File as FileFacade;

class SeedProductImageFactory
{
    /** @var list<int> */
    private array $fileIds = [];

    /** @var list<array{r: int, g: int, b: int}> */
    private const PALETTE = [
        ['r' => 52, 'g' => 73, 'b' => 94],
        ['r' => 41, 'g' => 128, 'b' => 185],
        ['r' => 39, 'g' => 174, 'b' => 96],
        ['r' => 142, 'g' => 68, 'b' => 173],
        ['r' => 211, 'g' => 84, 'b' => 0],
        ['r' => 192, 'g' => 57, 'b' => 43],
        ['r' => 22, 'g' => 160, 'b' => 133],
        ['r' => 44, 'g' => 62, 'b' => 80],
        ['r' => 127, 'g' => 140, 'b' => 141],
        ['r' => 243, 'g' => 156, 'b' => 18],
        ['r' => 46, 'g' => 204, 'b' => 113],
        ['r' => 52, 'g' => 152, 'b' => 219],
    ];

    public function initialize(int $poolSize = 12): void
    {
        if ($this->fileIds !== []) {
            return;
        }

        $existing = File::query()
            ->where('category', FileCategory::PRODUCT_IMAGE->value)
            ->where('path', 'like', 'product-images/seed/%')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if (count($existing) >= $poolSize) {
            $this->fileIds = array_slice($existing, 0, $poolSize);

            return;
        }

        $storageRoot = $this->resolveStorageRoot();

        for ($index = count($existing); $index < $poolSize; $index++) {
            $color = self::PALETTE[$index % count(self::PALETTE)];
            $storedName = sprintf('seed-product-%02d.jpg', $index + 1);
            $relativePath = 'product-images/seed/'.$storedName;
            $absolutePath = $storageRoot.'/'.$relativePath;

            if (! FileFacade::exists(dirname($absolutePath))) {
                FileFacade::makeDirectory(dirname($absolutePath), 0755, true);
            }

            $this->writePlaceholderImage($absolutePath, $color['r'], $color['g'], $color['b']);

            $checksum = hash_file('sha256', $absolutePath) ?: str_repeat('0', 64);
            $size = (int) filesize($absolutePath);

            $file = File::query()->firstOrCreate(
                ['path' => $relativePath],
                [
                    'uuid' => UuidService::generate(),
                    'application' => ApplicationType::SUPPLIER->value,
                    'entity_type' => 'PRODUCT',
                    'entity_uuid' => UuidService::generate(),
                    'category' => FileCategory::PRODUCT_IMAGE->value,
                    'disk' => 'feeder',
                    'original_name' => $storedName,
                    'extension' => 'jpg',
                    'mime_type' => 'image/jpeg',
                    'size' => $size,
                    'checksum' => $checksum,
                    'visibility' => 'PRIVATE',
                    'status' => 'ACTIVE',
                    'metadata' => ['seed' => true, 'pool_index' => $index],
                ]
            );

            $this->fileIds[] = (int) $file->id;
        }

        $this->fileIds = array_values(array_unique(array_merge(
            array_map('intval', $existing),
            $this->fileIds
        )));
    }

    /**
     * @return list<array{file_id: int, sort_order: int, is_primary: bool}>
     */
    public function imagePayloads(int $productIndex): array
    {
        if ($this->fileIds === []) {
            $this->initialize();
        }

        $count = match ($productIndex % 10) {
            0, 1, 2, 3, 4 => 1,
            5, 6, 7 => 2,
            default => $productIndex % 4 === 0 ? 4 : 3,
        };

        $images = [];

        for ($i = 0; $i < $count; $i++) {
            $fileId = $this->fileIds[($productIndex + $i) % count($this->fileIds)];
            $images[] = [
                'file_id' => $fileId,
                'sort_order' => $i,
                'is_primary' => $i === 0,
            ];
        }

        return $images;
    }

    private function resolveStorageRoot(): string
    {
        $configured = env('FEEDER_FILES_STORAGE_ROOT');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/\\');
        }

        return dirname(__DIR__, 3).'/feeder-files/storage/app/feeder';
    }

    private function writePlaceholderImage(string $path, int $r, int $g, int $b): void
    {
        if (extension_loaded('gd')) {
            $image = imagecreatetruecolor(640, 640);
            $background = imagecolorallocate($image, $r, $g, $b);
            imagefill($image, 0, 0, $background);
            $accent = imagecolorallocate($image, 255, 255, 255);
            imagefilledrectangle($image, 120, 120, 520, 520, $accent);
            imagejpeg($image, $path, 85);
            imagedestroy($image);

            return;
        }

        // Minimal valid JPEG fallback when GD is unavailable.
        FileFacade::put($path, base64_decode(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIy'
            .'MjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIA'
            .'AhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEB'
            .'AQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAB//2Q=='
        ));
    }
}
