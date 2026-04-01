<?php

namespace App\Services;

use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\PublicPropertyOption;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PublicPropertyCatalogService
{
    private const HOME_SECTIONS = ['featured', 'popular', 'budget', 'luxury'];

    private ?array $statesById = null;
    private ?array $citiesById = null;

    public function getHomeCatalog(): array
    {
        $properties = $this->basePublicProperties()->get();

        $sections = [];
        foreach (self::HOME_SECTIONS as $section) {
            $sections[$section] = $properties
                ->filter(fn (Property $property) => in_array($section, $this->parseHomeSections($property->public_home_sections), true))
                ->map(fn (Property $property) => $this->mapPropertySummary($property, ['stayMode' => 'months']))
                ->filter()
                ->values();
        }

        return [
            'sections' => $sections,
            'suggestions' => $this->buildSuggestions($properties),
        ];
    }

    public function searchProperties(array $filters): array
    {
        $normalized = $this->normalizeFilters($filters);
        $properties = $this->basePublicProperties()->get();

        $results = $properties
            ->map(fn (Property $property) => $this->mapPropertySummary($property, $normalized))
            ->filter()
            ->values();

        return [
            'properties' => $results,
            'suggestions' => $this->buildSuggestions($properties),
        ];
    }

    public function getPropertyDetail(int $propertyId): array
    {
        $property = $this->basePublicProperties()
            ->where('properties.id', $propertyId)
            ->first();

        if (!$property) {
            throw new ModelNotFoundException('Property not found');
        }

        return [
            'property' => $this->mapPropertyDetail($property),
        ];
    }

    public function getPropertyDetailBySlug(string $slug): array
    {
        $property = $this->basePublicProperties()
            ->where('properties.public_slug', $slug)
            ->first();

        if (!$property) {
            throw new ModelNotFoundException('Property not found');
        }

        return [
            'property' => $this->mapPropertyDetail($property),
        ];
    }

    private function basePublicProperties(): Builder
    {
        return Property::query()
            ->with([
                'propertyDetail',
                'fileAttachThumbnail',
                'propertyImages.fileAttachSingle',
                'publicOptions' => function ($query) {
                    $query
                        ->where('status', ACTIVE)
                        ->orderByDesc('is_default')
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->with(['propertyUnit.fileAttachImage']);
                },
            ])
            ->whereNull('properties.deleted_at')
            ->where('properties.is_public', true)
            ->whereNotNull('properties.public_slug')
            ->whereNotNull('properties.public_category')
            ->whereHas('publicOptions', function ($query) {
                $query->where('status', ACTIVE);
            })
            ->orderBy('properties.public_sort_order')
            ->orderBy('properties.id');
    }

    private function normalizeFilters(array $filters): array
    {
        $stayMode = $filters['stayMode'] ?? $filters['stay_mode'] ?? 'months';
        $searchText = trim((string) ($filters['q'] ?? ''));
        $location = trim((string) ($filters['location'] ?? ''));
        $category = trim((string) ($filters['category'] ?? $filters['type'] ?? ''));
        $minPrice = $this->toNullableFloat($filters['minPrice'] ?? $filters['min_price'] ?? null);
        $maxPrice = $this->toNullableFloat($filters['maxPrice'] ?? $filters['max_price'] ?? null);
        $guests = $this->toNullableInt($filters['guests'] ?? null);

        return [
            'stayMode' => in_array($stayMode, ['days', 'weeks', 'months'], true) ? $stayMode : 'months',
            'q' => $searchText,
            'location' => $location,
            'category' => in_array($category, ['apartment', 'boarding'], true) ? $category : '',
            'minPrice' => $minPrice,
            'maxPrice' => $maxPrice,
            'guests' => $guests,
            'checkIn' => trim((string) ($filters['checkIn'] ?? $filters['check_in'] ?? '')),
            'checkOut' => trim((string) ($filters['checkOut'] ?? $filters['check_out'] ?? '')),
        ];
    }

    private function mapPropertySummary(Property $property, array $filters): ?array
    {
        [$location, $district, $address] = $this->resolveLocation($property);

        if (($filters['category'] ?? '') && $property->public_category !== $filters['category']) {
            return null;
        }

        $haystack = Str::lower(implode(' ', array_filter([
            $property->name,
            $property->public_summary,
            $property->description,
            $location,
            $district,
            $address,
        ])));

        if (($filters['q'] ?? '') && !Str::contains($haystack, Str::lower($filters['q']))) {
            return null;
        }

        if (($filters['location'] ?? '') && !Str::contains($haystack, Str::lower($filters['location']))) {
            return null;
        }

        $matchingOptions = $this->filterOptionsForSearch($property->publicOptions, $filters);
        if ($matchingOptions->isEmpty()) {
            return null;
        }

        $selectedOption = $matchingOptions
            ->sort(function (PublicPropertyOption $left, PublicPropertyOption $right) use ($filters) {
                $leftPrice = $this->getComparableRate($left, $filters['stayMode']);
                $rightPrice = $this->getComparableRate($right, $filters['stayMode']);

                if ($leftPrice === $rightPrice) {
                    if ($left->sort_order === $right->sort_order) {
                        return $left->id <=> $right->id;
                    }

                    return $left->sort_order <=> $right->sort_order;
                }

                return $leftPrice <=> $rightPrice;
            })
            ->first();

        if (!$selectedOption) {
            return null;
        }

        [$bedrooms, $bathrooms, $squareFeet] = $this->getOptionStats($property, $selectedOption);
        $gallery = $this->buildGallery($property, $selectedOption);
        $amenities = $this->getOptionAmenities($property, $selectedOption);
        $image = $gallery[0] ?? $property->thumbnail_image;

        return [
            'id' => (string) $property->id,
            'slug' => $property->public_slug,
            'name' => $property->name,
            'summary' => $property->public_summary ?: ($property->description ?? ''),
            'location' => $location,
            'district' => $district,
            'address' => $address,
            'category' => $property->public_category,
            'category_label' => ucfirst($property->public_category),
            'image' => $image,
            'gallery' => $gallery,
            'amenities' => $amenities,
            'starting_option_id' => (string) $selectedOption->id,
            'starting_option_label' => $this->getOptionLabel($selectedOption),
            'occupancy_label' => $this->getOccupancyLabel($selectedOption),
            'monthly_rate' => $this->nullableMoney($selectedOption->monthly_rate),
            'nightly_rate' => $this->nullableMoney($selectedOption->nightly_rate),
            'max_guests' => (int) ($selectedOption->max_guests ?? 0),
            'bedrooms' => $bedrooms,
            'bathrooms' => $bathrooms,
            'square_feet' => $squareFeet,
        ];
    }

    private function mapPropertyDetail(Property $property): array
    {
        [$location, $district, $address] = $this->resolveLocation($property);
        $options = $property->publicOptions
            ->where('status', ACTIVE)
            ->sort(function (PublicPropertyOption $left, PublicPropertyOption $right) {
                if ($left->is_default === $right->is_default) {
                    if ($left->sort_order === $right->sort_order) {
                        return $left->id <=> $right->id;
                    }

                    return $left->sort_order <=> $right->sort_order;
                }

                return $left->is_default ? -1 : 1;
            })
            ->values()
            ->map(function (PublicPropertyOption $option) use ($property) {
                [$bedrooms, $bathrooms, $squareFeet] = $this->getOptionStats($property, $option);
                $gallery = $this->buildGallery($property, $option);

                return [
                    'id' => (string) $option->id,
                    'property_unit_id' => $option->property_unit_id ? (string) $option->property_unit_id : null,
                    'rental_kind' => $option->rental_kind,
                    'rental_kind_label' => $this->getRentalKindLabel($option->rental_kind),
                    'label' => $this->getOptionLabel($option),
                    'summary' => $this->getOptionSummary($property, $option),
                    'monthly_rate' => $this->nullableMoney($option->monthly_rate),
                    'nightly_rate' => $this->nullableMoney($option->nightly_rate),
                    'max_guests' => (int) ($option->max_guests ?? 0),
                    'status' => (int) $option->status,
                    'sort_order' => (int) $option->sort_order,
                    'is_default' => (bool) $option->is_default,
                    'image' => $gallery[0] ?? $property->thumbnail_image,
                    'gallery' => $gallery,
                    'amenities' => $this->getOptionAmenities($property, $option),
                    'bedrooms' => $bedrooms,
                    'bathrooms' => $bathrooms,
                    'square_feet' => $squareFeet,
                    'occupancy_label' => $this->getOccupancyLabel($option),
                ];
            })
            ->all();

        if (empty($options)) {
            throw new ModelNotFoundException('Property not found');
        }

        $defaultOption = collect($options)->firstWhere('is_default', true);
        $defaultOptionId = $defaultOption['id'] ?? $options[0]['id'];

        return [
            'id' => (string) $property->id,
            'slug' => $property->public_slug,
            'name' => $property->name,
            'summary' => $property->public_summary ?: ($property->description ?? ''),
            'description' => $property->description ?? '',
            'location' => $location,
            'district' => $district,
            'address' => $address,
            'map_link' => $property->propertyDetail?->map_link,
            'category' => $property->public_category,
            'category_label' => ucfirst($property->public_category),
            'image' => $this->buildGallery($property)[0] ?? $property->thumbnail_image,
            'gallery' => $this->buildGallery($property),
            'amenities' => $this->getPropertyAmenities($property),
            'default_option_id' => (string) $defaultOptionId,
            'options' => $options,
        ];
    }

    private function filterOptionsForSearch(Collection $options, array $filters): Collection
    {
        return $options
            ->where('status', ACTIVE)
            ->filter(function (PublicPropertyOption $option) use ($filters) {
                $rate = $this->getComparableRate($option, $filters['stayMode'] ?? 'months');
                if ($rate === null) {
                    return false;
                }

                if (($filters['guests'] ?? null) && (! $option->max_guests || $option->max_guests < $filters['guests'])) {
                    return false;
                }

                if (($filters['minPrice'] ?? null) !== null && $rate < $filters['minPrice']) {
                    return false;
                }

                if (($filters['maxPrice'] ?? null) !== null && $rate > $filters['maxPrice']) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    private function getComparableRate(PublicPropertyOption $option, string $stayMode): ?float
    {
        if ($stayMode === 'months') {
            return $option->monthly_rate !== null ? (float) $option->monthly_rate : null;
        }

        return $option->nightly_rate !== null ? (float) $option->nightly_rate : null;
    }

    private function resolveLocation(Property $property): array
    {
        $address = trim((string) ($property->propertyDetail?->address ?? ''));
        $cityName = $this->lookupCityName($property->propertyDetail?->city_id);
        $stateName = $this->lookupStateName($property->propertyDetail?->state_id);

        if ($cityName) {
            return [$cityName, $stateName ?: $this->getAddressDistrict($address), $address];
        }

        if ($stateName) {
            return [$stateName, $this->getAddressDistrict($address), $address];
        }

        $segments = collect(explode(',', $address))
            ->map(fn ($segment) => trim($segment))
            ->filter()
            ->values();

        if ($segments->count() >= 2) {
            return [
                (string) $segments->last(),
                (string) ($segments->get($segments->count() - 2) ?: ''),
                $address,
            ];
        }

        return [$address ?: 'Zambia', '', $address];
    }

    private function getAddressDistrict(string $address): string
    {
        $segments = collect(explode(',', $address))
            ->map(fn ($segment) => trim($segment))
            ->filter()
            ->values();

        if ($segments->count() >= 2) {
            return (string) $segments->get($segments->count() - 2);
        }

        return (string) ($segments->first() ?? '');
    }

    private function lookupCityName(?string $cityId): ?string
    {
        if (!$cityId) {
            return null;
        }

        if ($this->citiesById === null) {
            $rows = csvToArray(public_path('file/cities.csv')) ?: [];
            $this->citiesById = collect($rows)
                ->filter(fn ($row) => isset($row['id'], $row['name']))
                ->mapWithKeys(fn ($row) => [(string) $row['id'] => $row['name']])
                ->all();
        }

        return $this->citiesById[(string) $cityId] ?? null;
    }

    private function lookupStateName(?string $stateId): ?string
    {
        if (!$stateId) {
            return null;
        }

        if ($this->statesById === null) {
            $rows = csvToArray(public_path('file/states.csv')) ?: [];
            $this->statesById = collect($rows)
                ->filter(fn ($row) => isset($row['id'], $row['name']))
                ->mapWithKeys(fn ($row) => [(string) $row['id'] => $row['name']])
                ->all();
        }

        return $this->statesById[(string) $stateId] ?? null;
    }

    private function buildSuggestions(Collection $properties): array
    {
        $suggestions = collect();

        foreach ($properties as $property) {
            [$location, $district] = $this->resolveLocation($property);
            $summary = $this->mapPropertySummary($property, ['stayMode' => 'months']);
            if (!$summary) {
                continue;
            }

            $suggestions->push([
                'label' => $property->name,
                'description' => $summary['starting_option_label'] . ' | ' . $location,
            ]);

            if ($location) {
                $suggestions->push([
                    'label' => $location,
                    'description' => ucfirst($property->public_category) . ' listings in ' . $location,
                ]);
            }

            if ($district) {
                $suggestions->push([
                    'label' => $district,
                    'description' => $location ? $location . ' neighborhood' : 'Property district',
                ]);
            }
        }

        return $suggestions
            ->unique(fn ($item) => Str::lower($item['label']))
            ->values()
            ->all();
    }

    private function buildGallery(Property $property, ?PublicPropertyOption $option = null): array
    {
        $images = [];

        if ($option && $option->propertyUnit?->fileAttachImage?->FileUrl) {
            $images[] = $option->propertyUnit->fileAttachImage->FileUrl;
        }

        foreach ($property->propertyImages as $propertyImage) {
            $url = $propertyImage->fileAttachSingle?->FileUrl;
            if ($url) {
                $images[] = $url;
            }
        }

        if ($property->thumbnail_image) {
            $images[] = $property->thumbnail_image;
        }

        return array_values(array_unique(array_filter($images)));
    }

    private function getPropertyAmenities(Property $property): array
    {
        return $property->publicOptions
            ->where('status', ACTIVE)
            ->flatMap(fn (PublicPropertyOption $option) => $this->getOptionAmenities($property, $option))
            ->unique()
            ->values()
            ->all();
    }

    private function getOptionAmenities(Property $property, PublicPropertyOption $option): array
    {
        if ($option->propertyUnit) {
            return $this->parseAmenitiesFromUnit($option->propertyUnit);
        }

        return PropertyUnit::query()
            ->where('property_id', $property->id)
            ->whereNull('deleted_at')
            ->get()
            ->flatMap(fn (PropertyUnit $unit) => $this->parseAmenitiesFromUnit($unit))
            ->unique()
            ->values()
            ->all();
    }

    private function parseAmenitiesFromUnit(PropertyUnit $unit): array
    {
        $amenities = collect(explode(',', (string) $unit->amenities))
            ->map(fn ($item) => trim($item))
            ->filter()
            ->values();

        if (!empty($unit->parking)) {
            $amenities->push('Parking');
        }

        return $amenities
            ->unique(fn ($item) => Str::lower($item))
            ->values()
            ->all();
    }

    private function getOptionLabel(PublicPropertyOption $option): string
    {
        if ($option->propertyUnit?->unit_name) {
            return $option->propertyUnit->unit_name;
        }

        return $this->getRentalKindLabel($option->rental_kind);
    }

    private function getOptionSummary(Property $property, PublicPropertyOption $option): string
    {
        if ($option->propertyUnit?->description) {
            return $option->propertyUnit->description;
        }

        return $property->public_summary ?: ($property->description ?? '');
    }

    private function getOccupancyLabel(PublicPropertyOption $option): string
    {
        return match ($option->rental_kind) {
            'whole_property' => 'Entire property',
            'whole_unit' => 'Private unit',
            'private_room' => 'Private room in a shared property',
            'shared_space' => 'Shared space',
            default => 'Managed by Savant',
        };
    }

    private function getRentalKindLabel(string $rentalKind): string
    {
        return match ($rentalKind) {
            'whole_property' => 'Whole property',
            'whole_unit' => 'Whole unit',
            'private_room' => 'Private room',
            'shared_space' => 'Shared space',
            default => 'Public option',
        };
    }

    private function parseHomeSections(?string $sections): array
    {
        return collect(explode(',', (string) $sections))
            ->map(fn ($item) => trim(Str::lower($item)))
            ->filter(fn ($item) => in_array($item, self::HOME_SECTIONS, true))
            ->unique()
            ->values()
            ->all();
    }

    private function getOptionStats(Property $property, PublicPropertyOption $option): array
    {
        if ($option->propertyUnit) {
            return [
                (int) ($option->propertyUnit->bedroom ?? 0),
                (int) ($option->propertyUnit->bath ?? 0),
                $this->toNullableInt($option->propertyUnit->square_feet),
            ];
        }

        $units = PropertyUnit::query()
            ->where('property_id', $property->id)
            ->whereNull('deleted_at')
            ->get();

        return [
            (int) $units->sum('bedroom'),
            (int) $units->sum('bath'),
            $units->sum(fn (PropertyUnit $unit) => $this->toNullableInt($unit->square_feet) ?? 0),
        ];
    }

    private function nullableMoney($value): ?float
    {
        if ($value === null) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function toNullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function toNullableFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
